<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Performance\Models\CommissionRule;
use Modules\Performance\Support\CommissionCalculator;
use Modules\Report\Support\ReportFilter;

/**
 * 提成方案管理（绩效分析 → 提成方案）。
 *
 * 任务书 §七：type ∈ sales|profit|piece|ladder|single，规则细节进 rule JSON，
 * 适用范围 scope ∈ all|specified + employee_ids。
 * calc_type 与 type 双写保持兼容（计算器仍读扁平列），rule 始终打包落库。
 * 阶梯区间保存时校验连续不重叠。
 */
class CommissionRuleController extends Controller
{
    /** 新任务书 type ↔ 计算器 calc_type */
    private const TYPE_TO_CALC = [
        'sales' => 'sale_rate',
        'profit' => 'profit_rate',
        'piece' => 'fixed_per_unit',
        'ladder' => 'tiered',
        'single' => 'product_specific',
    ];

    private const CALC_TO_TYPE = [
        'sale_rate' => 'sales',
        'profit_rate' => 'profit',
        'fixed_per_unit' => 'piece',
        'tiered' => 'ladder',
        'product_specific' => 'single',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = CommissionRule::query()->orderByDesc('id');

        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->input('name').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('calc_type')) {
            $query->where('calc_type', $request->input('calc_type'));
        }

        $page = $query->paginate(min(100, max(1, (int) $request->input('page_size', 30))));

        return $this->paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRule($request);
        $data['code'] = $data['code'] ?? $this->genCode();

        $rule = CommissionRule::create($data);

        return $this->created($rule, '保存成功');
    }

    public function show(int $id): JsonResponse
    {
        $rule = CommissionRule::findOrFail($id);

        return $this->success($rule);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = CommissionRule::findOrFail($id);
        $data = $this->validateRule($request, true);
        $rule->update($data);

        return $this->success($rule, '保存成功');
    }

    public function destroy(int $id): JsonResponse
    {
        $rule = CommissionRule::findOrFail($id);
        // 已被提成单引用的不允许删除（明细表已更名 perf_commission_items）
        $used = DB::table('perf_commission_items')->where('rule_id', $id)->exists();
        if ($used) {
            return $this->error('该方案已被提成单引用，不能删除，可停用', 400);
        }
        $rule->delete();

        return $this->success(null, '删除成功');
    }

    public function copy(int $id): JsonResponse
    {
        $rule = CommissionRule::findOrFail($id);
        $new = $rule->replicate();
        $new->code = $this->genCode();
        $new->name = $rule->name.' 副本';
        $new->status = 'inactive';
        $new->save();

        return $this->created($new, '复制成功');
    }

    /** 方案列表导出（与筛选一致） */
    public function export(Request $request)
    {
        $query = CommissionRule::query()->orderByDesc('id');
        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->input('name').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('calc_type')) {
            $query->where('calc_type', $request->input('calc_type'));
        }

        $headers = ['方案编号', '方案名称', '方案类型', '计提方式', '适用范围', '开始日期', '结束日期', '状态', '备注'];
        $typeLabel = ['sales' => '销售额提成', 'profit' => '利润提成', 'piece' => '按件计费', 'ladder' => '阶梯提成', 'single' => '单品方案'];
        $calcLabel = [
            'sale_rate' => '按销售额比例',
            'profit_rate' => '按利润比例',
            'fixed_per_unit' => '按件固定',
            'tiered' => '阶梯',
            'product_specific' => '按单品',
        ];
        $rows = [];
        foreach ($query->get() as $r) {
            $rows[] = [
                $r->code,
                $r->name,
                $typeLabel[$r->type] ?? $r->type,
                $calcLabel[$r->calc_type] ?? $r->calc_type,
                $r->scope === 'specified' ? '指定业务员' : '全部业务员',
                optional($r->start_date)->toDateString() ?? '',
                optional($r->end_date)->toDateString() ?? '',
                $r->status === 'active' ? '启用' : '停用',
                (string) $r->remark,
            ];
        }

        $csv = ReportFilter::csv('提成方案', $headers, $rows);

        return ReportFilter::csvResponse($csv, 'commission_rules_'.date('Ymd_His').'.csv');
    }

    /** 方案试算示例：按月销 10 万预估提成 */
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'calc_type' => 'nullable|string',
            'type' => 'nullable|string',
            'rate' => 'nullable|numeric',
            'unit_amount' => 'nullable|numeric',
            'unit_base' => 'nullable|string',
            'tier_data' => 'nullable|array',
            'product_rules' => 'nullable|array',
            'base_on_payment' => 'boolean',
        ]);
        if (empty($data['calc_type']) && ! empty($data['type'])) {
            $data['calc_type'] = self::TYPE_TO_CALC[$data['type']] ?? 'sale_rate';
        }
        $data['calc_type'] = $data['calc_type'] ?? 'sale_rate';
        $sample = (float) $request->input('sample_amount', 100000);

        // 复用引擎的 applyRule；聚合键同时给旧名与任务书新名，避免口径切换期读空
        $fakeRule = new CommissionRule($data);
        $calc = new CommissionCalculator;
        $ref = new \ReflectionMethod($calc, 'applyRule');
        $ref->setAccessible(true);
        [$base, $amount] = $ref->invoke($calc, $fakeRule, [(object) [
            'order_id' => 0,
            'quantity' => 100,
            'amount' => $sample,
            'paid_amount' => $sample,
            'return_amount' => 0,
            'cost_amount' => $sample * 0.8,
            'product_id' => 0,
        ]], [
            'sale_amount' => $sample,
            'sales_amount' => $sample,
            'payment_amount' => $sample,
            'received_amount' => $sample,
            'return_amount' => 0,
            'gross_profit' => $sample * 0.2,
            'profit_amount' => $sample * 0.2,
        ]);

        return $this->success(['base' => round($base, 2), 'amount' => round($amount, 2)]);
    }

    private function validateRule(Request $request, bool $isUpdate = false): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'nullable|in:sales,profit,piece,ladder,single',
            'calc_type' => 'nullable|in:sale_rate,profit_rate,fixed_per_unit,tiered,product_specific',
            'rule' => 'nullable|array',
            'scope' => 'nullable|in:all,specified',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer',
            'apply_employees' => 'nullable|array',
            'apply_customer_levels' => 'nullable|array',
            'product_scope_type' => 'nullable|in:all,category,brand,product',
            'product_scope_ids' => 'nullable|array',
            'rate' => 'nullable|numeric',
            'unit_base' => 'nullable|string',
            'unit_amount' => 'nullable|numeric',
            'tier_data' => 'nullable|array',
            'product_rules' => 'nullable|array',
            'base_on_payment' => 'boolean',
            'deduct_return' => 'boolean',
            'exclude_baddebt' => 'boolean',
            'cap_amount' => 'nullable|numeric',
            'floor_amount' => 'nullable|numeric',
            'allow_stack' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|in:active,inactive',
            'remark' => 'nullable|string',
        ]);

        if (empty($data['type']) && empty($data['calc_type'])) {
            throw ValidationException::withMessages([
                'type' => '方案类型 type 与 calc_type 至少填一个',
            ]);
        }

        // type ↔ calc_type 双写：计算器读 calc_type，列表/新前端读 type
        if (! empty($data['type']) && empty($data['calc_type'])) {
            $data['calc_type'] = self::TYPE_TO_CALC[$data['type']];
        }
        if (! empty($data['calc_type']) && empty($data['type'])) {
            $data['type'] = self::CALC_TO_TYPE[$data['calc_type']];
        }

        // 适用范围：scope=specified 时必须有业务员，并镜像到计算器读的 apply_employees
        if (($data['scope'] ?? null) === 'specified') {
            $ids = $data['employee_ids'] ?? $data['apply_employees'] ?? [];
            if (empty($ids)) {
                throw ValidationException::withMessages([
                    'employee_ids' => '指定业务员时至少选择 1 人',
                ]);
            }
            $data['employee_ids'] = array_values(array_map('intval', $ids));
            $data['apply_employees'] = $data['employee_ids'];
        } elseif (array_key_exists('scope', $data) && $data['scope'] === 'all') {
            $data['employee_ids'] = [];
            $data['apply_employees'] = [];
        }

        // rule JSON：前端传了就用前端的，否则由扁平列打包（与迁移回填同一形状）
        if (empty($data['rule'])) {
            $data['rule'] = $this->packRule($data);
        }

        // 阶梯区间连续性校验（新 ladder 与旧 tiered 同源）
        $isLadder = ($data['type'] ?? '') === 'ladder'
            || ($data['calc_type'] ?? '') === 'tiered'
            || ! empty($data['rule']['tier_data']);
        $tiers = $data['tier_data'] ?? ($data['rule']['tier_data'] ?? null);
        if ($isLadder && ! empty($tiers)) {
            $this->validateTiers($tiers);
        }

        return $data;
    }

    /** 由扁平列打包 rule JSON（与迁移 alignRules 回填同形） */
    private function packRule(array $data): array
    {
        return array_filter([
            'rate' => $data['rate'] ?? null,
            'unit_base' => $data['unit_base'] ?? null,
            'unit_amount' => $data['unit_amount'] ?? null,
            'tier_data' => $data['tier_data'] ?? null,
            'product_rules' => $data['product_rules'] ?? null,
            'apply_customer_levels' => $data['apply_customer_levels'] ?? null,
            'product_scope_type' => $data['product_scope_type'] ?? null,
            'product_scope_ids' => $data['product_scope_ids'] ?? null,
            'base_on_payment' => $data['base_on_payment'] ?? null,
            'deduct_return' => $data['deduct_return'] ?? null,
            'exclude_baddebt' => $data['exclude_baddebt'] ?? null,
            'cap_amount' => $data['cap_amount'] ?? null,
            'floor_amount' => $data['floor_amount'] ?? null,
            'allow_stack' => $data['allow_stack'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function validateTiers(array $tiers): void
    {
        usort($tiers, fn ($a, $b) => ($a['min'] ?? 0) <=> ($b['min'] ?? 0));
        $prevMax = null;
        foreach ($tiers as $t) {
            $min = (float) ($t['min'] ?? 0);
            $max = isset($t['max']) ? (float) $t['max'] : null;
            if ($prevMax !== null && abs($min - $prevMax) > 0.01) {
                throw ValidationException::withMessages([
                    'tier_data' => '阶梯区间必须连续不重叠：上一段上限 '.$prevMax.' 与下一段下限 '.$min.' 不衔接',
                ]);
            }
            $prevMax = $max;
        }
    }

    private function genCode(): string
    {
        return 'CR'.date('ymd').str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
