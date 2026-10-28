<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cash\Http\Requests\CashIntegralRuleRequest;
use Modules\Cash\Models\CashIntegralRule;
use Modules\Cash\Services\CashIntegralService;

/**
 * 积分规则控制器（D 域 D5，cash/integral/rules 前缀）。
 *
 * 规则只影响后续发放口径，不追溯已发放积分，故编辑/停用无需审核流。
 */
class CashIntegralRuleController extends Controller
{
    use ResponseTrait;

    private const EXPORT_LIMIT = 10000;

    public function __construct(private CashIntegralService $service) {}

    public function index(Request $request): JsonResponse
    {
        $pager = $this->service->ruleList($request->all());

        return $this->success([
            'list' => collect($pager->items())->map(fn (CashIntegralRule $r) => $this->transform($r))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->success($this->transform($this->find($id)));
    }

    public function store(CashIntegralRuleRequest $request): JsonResponse
    {
        $rule = $this->service->ruleSave($request->validated(), null, $this->userId($request));

        return $this->created($this->transform($rule), '保存成功');
    }

    public function update(CashIntegralRuleRequest $request, int $id): JsonResponse
    {
        $rule = $this->service->ruleSave($request->validated(), $this->find($id), $this->userId($request));

        return $this->success($this->transform($rule), '保存成功');
    }

    /** 启用/停用互切（前端二次确认标题「操作确认」） */
    public function toggle(int $id): JsonResponse
    {
        $rule = $this->service->ruleToggle($this->find($id));

        return $this->success($this->transform($rule), '保存成功');
    }

    public function destroy(int $id): JsonResponse
    {
        $rule = $this->find($id);

        if ((int) $rule->status === CashIntegralRule::STATUS_ENABLED) {
            return $this->error('启用中的规则不能删除，请先停用', 422);
        }

        $rule->delete();

        return $this->success(null, '删除成功');
    }

    public function export(Request $request)
    {
        $rows = $this->service->ruleRows($request->all(), self::EXPORT_LIMIT);

        $csv = "\u{FEFF}规则名称,积分比例,生效日期,状态,备注\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s\n",
                $this->csvCell($r->rule_name),
                rtrim(rtrim(number_format((float) $r->ratio, 4, '.', ''), '0'), '.'),
                $r->effective_date?->format('Y-m-d'),
                CashIntegralRule::statusLabel((int) $r->status),
                $this->csvCell($r->remark)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cash_integral_rules.csv"',
        ]);
    }

    private function find(int $id): CashIntegralRule
    {
        return CashIntegralRule::query()->findOrFail($id);
    }

    private function userId(Request $request): ?int
    {
        return optional($request->input('auth_user'))->id;
    }

    private function transform(CashIntegralRule $rule): array
    {
        return [
            'id' => $rule->id,
            'rule_name' => $rule->rule_name,
            'ratio' => (float) $rule->ratio,
            'effective_date' => $rule->effective_date?->format('Y-m-d'),
            'status' => (int) $rule->status,
            'status_label' => CashIntegralRule::statusLabel((int) $rule->status),
            'remark' => $rule->remark,
            'created_at' => $rule->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function csvCell(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
