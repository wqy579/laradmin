<?php

namespace Modules\Archive\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Archive\Models\Company;
use Modules\Archive\Models\Division;

/**
 * 事业部档案管理（F6 基础档案）。
 *
 * 与公司档案同一套档案页约定，多一层主体归属：每条事业部必须挂在一家公司下，
 * 公司不存在或已停用都不允许挂进去（停用公司下的存量事业部仍可查询，只是不能再新增）。
 */
class DivisionController extends Controller
{
    use ResponseTrait;

    /**
     * 列表：编码/名称模糊 + 状态 + 所属公司筛选 + 分页。
     *
     * with('company') 一次联取公司名，不在 map 里逐行查（列表要显示「所属公司」列）。
     * withTrashed 的 company 关联是有意的：历史事业部挂的公司被停用了也要显示得出名字。
     */
    public function index(Request $request)
    {
        $query = Division::with('company:id,name,code');

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
                        ->orWhere('manager_name', 'like', $likeKeyword);
                }
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->company_id);
        }

        $divisions = $query->orderBy('sort')->orderBy('id')->paginate($request->integer('page_size', 30));

        $divisions->getCollection()->transform(function (Division $division) {
            $division['company_name'] = $division->company?->name;

            return $division;
        });

        return $this->paginated($divisions);
    }

    /** 下拉选项：只返回启用中的事业部，可按公司过滤 */
    public function options(Request $request)
    {
        $query = Division::enabled()->orderBy('sort')->orderBy('id');
        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->company_id);
        }

        $list = $query->get()->map(fn (Division $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'company_id' => $d->company_id,
        ]);

        return $this->success(['list' => $list]);
    }

    public function show($id)
    {
        $division = Division::with('company:id,name,code')->find($id);
        if (! $division) {
            return $this->notFound('事业部不存在');
        }
        $division['company_name'] = $division->company?->name;

        return $this->success($division);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['code'] = $this->normalizeCode($data['code'] ?? null);

        if ($err = $this->companyGuard($data['company_id'], false)) {
            return $err;
        }
        if ($this->codeTaken($data['code'], null)) {
            return $this->error('事业部编码已存在', 422);
        }

        $division = Division::create($data);

        return $this->created($division->fresh(), '已创建');
    }

    public function update(Request $request, $id)
    {
        $division = Division::find($id);
        if (! $division) {
            return $this->notFound('事业部不存在');
        }
        $data = $this->validateData($request, updating: true);

        if (array_key_exists('company_id', $data)) {
            if ($data['company_id'] === null) {
                // 没提交归属就沿用原公司，不能把 company_id 写成 null（列是 NOT NULL）
                unset($data['company_id']);
            } else {
                $companyId = (int) $data['company_id'];
                // 归属未变时放宽：公司停用后其存量事业部仍可维护，只有换主体才要求新公司启用
                if ($err = $this->companyGuard($companyId, $companyId === (int) $division->company_id)) {
                    return $err;
                }
            }
        }
        if (array_key_exists('code', $data)) {
            $data['code'] = $this->normalizeCode($data['code']);
            if ($this->codeTaken($data['code'], $division->id)) {
                return $this->error('事业部编码已存在', 422);
            }
        }

        $division->update($data);

        $fresh = $division->fresh()->load('company:id,name,code');
        // 表单保存后前端直接用返回行刷新当前行，company_name 要和列表一样附好，
        // 否则「所属公司」列会变成空白
        $fresh['company_name'] = $fresh->company?->name;

        return $this->success($fresh, '已更新');
    }

    /** 删除：软删。事业部是组织维度档案，历史单据可能引用其 id，不做物理删除。 */
    public function destroy($id)
    {
        $division = Division::find($id);
        if (! $division) {
            return $this->notFound('事业部不存在');
        }
        $division->delete();

        return $this->success(null, '已删除');
    }

    /** 批量启用/停用 */
    public function batchUpdateStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'status' => ['required', Rule::in([Division::STATUS_ENABLED, Division::STATUS_DISABLED])],
        ]);
        Division::whereIn('id', $data['ids'])->update([
            'status' => $data['status'],
            'updated_at' => now(),
        ]);

        return $this->success(['affected' => count($data['ids'])], '已更新');
    }

    /** 导出 CSV（带 BOM，Excel 打开不乱码） */
    public function export()
    {
        $divisions = Division::with('company:id,name')->orderBy('sort')->orderBy('id')->get();

        $csv = "\u{FEFF}"; // BOM：Excel 正确识别 UTF-8
        $csv .= "事业部档案\n\n";
        $csv .= "事业部编码,事业部名称,所属公司,负责人,负责人电话,启用日期,状态,排序,备注,创建时间\n";

        foreach ($divisions as $d) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%d,%s,%s\n",
                $this->csvCell($d->code),
                $this->csvCell($d->name),
                $this->csvCell($d->company?->name),
                $this->csvCell($d->manager_name),
                $this->csvCell($d->manager_phone),
                $d->effective_at?->format('Y-m-d') ?? '',
                $d->status === Division::STATUS_ENABLED ? '启用' : '停用',
                (int) $d->sort,
                $this->csvCell($d->remark),
                $d->created_at?->format('Y-m-d H:i') ?? '',
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="divisions.csv"',
        ]);
    }

    private function validateData(Request $request, bool $updating = false): array
    {
        return $request->validate([
            // 编辑时公司可缺省（沿用原归属），新增必须显式指定。
            // 规则数组里的字符串不会再按 | 拆分（只有 Date/Numeric/StringRule 对象会），
            // 所以 'nullable|integer' 必须写成两个元素，否则 Validator 会去找
            // validateRequired|integer() 这个方法并直接 500。
            'company_id' => [
                $updating ? 'nullable' : 'required',
                'integer',
                Rule::exists('archive_companies', 'id')->whereNull('deleted_at'),
            ],
            'code' => 'nullable|string|max:30',
            'name' => 'required|string|max:100',
            'manager_name' => 'nullable|string|max:30',
            'manager_phone' => 'nullable|string|max:20',
            'effective_at' => 'nullable|date',
            'status' => ['nullable', Rule::in([Division::STATUS_ENABLED, Division::STATUS_DISABLED])],
            'sort' => 'nullable|integer|min:0',
            'remark' => 'nullable|string|max:255',
        ], [], [
            'company_id' => '所属公司',
            'code' => '事业部编码',
            'name' => '事业部名称',
        ]);
    }

    /**
     * 主体校验：公司必须存在且启用。
     *
     * 与 exists 规则分开做，是为了给出一句人话（「公司已停用」比「所属公司字段无效」
     * 更能让用户明白该怎么补救）。
     */
    private function companyGuard(int $companyId, bool $allowDisabled)
    {
        $company = Company::find($companyId);
        if (! $company) {
            return $this->error('所属公司不存在', 422);
        }
        if (! $allowDisabled && $company->status !== Company::STATUS_ENABLED) {
            // 新增与「换挂到停用公司」共用这句文案，措辞不能只说「新增」
            return $this->error("所属公司「{$company->name}」已停用，不能挂靠事业部", 422);
        }

        return null;
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

        return Division::where('code', $code)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
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
