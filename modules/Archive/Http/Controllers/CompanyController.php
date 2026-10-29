<?php

namespace Modules\Archive\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Archive\Models\Company;
use Modules\Archive\Models\Division;

/**
 * 公司档案管理（F6 基础档案）。
 *
 * 与品牌档案（BrandController）同一套档案页约定：列表可模糊查编码/名称、
 * 软删保护（有下级的档案不能删）、批量启停、CSV 导出带 BOM。
 *
 * 编码刻意不自动生成：公司编码要和单据、发票上的口径对齐，属于人工登记的
 * 主数据，自动生成出来的 GS0001 反而容易被当成系统内部号忽略掉。
 */
class CompanyController extends Controller
{
    use ResponseTrait;

    /**
     * 列表：编码/名称/关键字模糊 + 状态筛选 + 分页。
     *
     * 事业部数走一次 GROUP BY 而不是逐行 COUNT（与 BrandController::index 同原则），
     * 并在 transform 里附上名称，供列表「所属公司」列直接显示。
     */
    public function index(Request $request)
    {
        $query = Company::query();

        $likeName = $request->filled('name') ? $this->like($request->name) : null;
        $likeCode = $request->filled('code') ? $this->like($request->code) : null;
        $likeKeyword = $request->filled('keyword') ? $this->like($request->keyword) : null;

        if ($likeName || $likeCode || $likeKeyword) {
            $query->where(function ($q) use ($likeName, $likeCode, $likeKeyword) {
                if ($likeName) {
                    $q->where('name', 'like', $likeName);
                }
                if ($likeCode) {
                    $q->orWhere('code', 'like', $likeCode);
                }
                if ($likeKeyword) {
                    $q->orWhere('name', 'like', $likeKeyword)
                        ->orWhere('code', 'like', $likeKeyword)
                        ->orWhere('short_name', 'like', $likeKeyword);
                }
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $companies = $query->orderBy('sort')->orderBy('id')->paginate($request->integer('page_size', 30));

        $countMap = Division::query()
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as cnt')
            ->pluck('cnt', 'company_id');

        $companies->getCollection()->transform(function (Company $company) use ($countMap) {
            $company['division_count'] = (int) ($countMap[$company->id] ?? 0);

            return $company;
        });

        return $this->paginated($companies);
    }

    /** 下拉选项（事业部表单/筛选器用）：只返回启用中的公司 */
    public function options()
    {
        $list = Company::enabled()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'is_default' => $c->is_default,
            ]);

        return $this->success(['list' => $list]);
    }

    public function show($id)
    {
        $company = Company::find($id);
        if (! $company) {
            return $this->notFound('公司不存在');
        }
        $company['division_count'] = $company->divisions()->count();
        $company['divisions'] = $company->divisions()
            ->select('id', 'company_id', 'code', 'name', 'status')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return $this->success($company);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['code'] = $this->normalizeCode($data['code'] ?? null);

        if ($this->codeTaken($data['code'] ?? null, null)) {
            return $this->error('公司编码已存在', 422);
        }

        $company = Company::create($data);
        if ($company->is_default) {
            $this->keepSingleDefault($company->id);
        }

        return $this->created($company->fresh(), '已创建');
    }

    public function update(Request $request, $id)
    {
        $company = Company::find($id);
        if (! $company) {
            return $this->notFound('公司不存在');
        }
        $data = $this->validateData($request);

        // 未提交编码时保留原值：前端表单改单个字段不带 code 是正常情况。
        if (array_key_exists('code', $data)) {
            $data['code'] = $this->normalizeCode($data['code']);
            if ($this->codeTaken($data['code'], $company->id)) {
                return $this->error('公司编码已存在', 422);
            }
        }

        $company->update($data);
        if ($company->fresh()->is_default) {
            $this->keepSingleDefault($company->id);
        }

        return $this->success($company->fresh(), '已更新');
    }

    /**
     * 删除：软删。
     *
     * 两道保护：默认公司不能删（单据主体缺省会连带影响开账与开票），
     * 名下还有事业部不能删（否则事业部变成挂空主体的孤儿档案）。
     */
    public function destroy($id)
    {
        $company = Company::find($id);
        if (! $company) {
            return $this->notFound('公司不存在');
        }
        if ($company->is_default) {
            return $this->error('默认公司不可删除，请先指定其他公司为默认', 422);
        }
        $count = $company->divisions()->count();
        if ($count > 0) {
            return $this->error("该公司下还有 {$count} 个事业部，请先迁移或删除事业部", 422);
        }
        $company->delete();

        return $this->success(null, '已删除');
    }

    /** 批量启用/停用 */
    public function batchUpdateStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'status' => ['required', Rule::in([Company::STATUS_ENABLED, Company::STATUS_DISABLED])],
        ]);
        Company::whereIn('id', $data['ids'])->update([
            'status' => $data['status'],
            'updated_at' => now(),
        ]);

        return $this->success(['affected' => count($data['ids'])], '已更新');
    }

    /** 导出 CSV（带 BOM，Excel 打开不乱码；与品牌档案同一套写法） */
    public function export()
    {
        $companies = Company::orderBy('sort')->orderBy('id')->get();

        $csv = "\u{FEFF}"; // BOM：Excel 正确识别 UTF-8
        $csv .= "公司档案\n\n";
        $csv .= "公司编码,公司名称,简称,纳税人识别号,开票名称,开户银行,银行账号,联系人,联系电话,默认,状态,排序,备注,创建时间\n";

        foreach ($companies as $c) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s\n",
                $this->csvCell($c->code),
                $this->csvCell($c->name),
                $this->csvCell($c->short_name),
                $this->csvCell($c->tax_no),
                $this->csvCell($c->invoice_title),
                $this->csvCell($c->bank_name),
                $this->csvCell($c->bank_account),
                $this->csvCell($c->contact_name),
                $this->csvCell($c->contact_phone),
                $c->is_default ? '是' : '否',
                $c->status === Company::STATUS_ENABLED ? '启用' : '停用',
                (int) $c->sort,
                $this->csvCell($c->remark),
                $c->created_at?->format('Y-m-d H:i') ?? '',
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="companies.csv"',
        ]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'code' => 'nullable|string|max:30',
            'name' => 'required|string|max:100',
            'short_name' => 'nullable|string|max:50',
            'tax_no' => 'nullable|string|max:30',
            'invoice_title' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:40',
            'contact_name' => 'nullable|string|max:30',
            'contact_phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:200',
            'is_default' => 'nullable|boolean',
            'status' => ['nullable', Rule::in([Company::STATUS_ENABLED, Company::STATUS_DISABLED])],
            'sort' => 'nullable|integer|min:0',
            'remark' => 'nullable|string|max:255',
        ], [], [
            'code' => '公司编码',
            'name' => '公司名称',
            'tax_no' => '纳税人识别号',
        ]);
    }

    /** 全库只保留一条默认公司 */
    private function keepSingleDefault(int $keepId): void
    {
        Company::where('id', '!=', $keepId)->where('is_default', true)->update(['is_default' => false]);
    }

    private function normalizeCode(?string $code): ?string
    {
        $code = trim((string) ($code ?? ''));

        return $code === '' ? null : $code;
    }

    private function codeTaken(?string $code, ?int $ignoreId): bool
    {
        if (empty($code)) {
            return false;
        }

        return Company::where('code', $code)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
    }

    private function like(string $value): string
    {
        return '%'.str_replace('%', '\%', $value).'%';
    }

    /** CSV 单元格转义：含引号/逗号/换行时用双引号包裹并转义内部引号 */
    private function csvCell($value): string
    {
        $value = (string) ($value ?? '');
        if (preg_match('/[",\r\n]/', $value)) {
            $value = '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
