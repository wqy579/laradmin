<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Performance\Models\CommissionRule;
use Modules\Performance\Support\CommissionCalculator;

/**
 * 提成方案管理（绩效分析 → 提成方案）。
 *
 * 五种 calc_type 共用一张表，列表按方案类型给不同色标签（前端渲染）。
 * 阶梯区间保存时校验连续不重叠。
 */
class CommissionRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CommissionRule::query()->orderByDesc('id');

        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->input('name').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
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
        // 已被提成单引用的不允许删除
        $used = DB::table('perf_commission_order_items')->where('rule_id', $id)->exists();
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

    /** 方案试算示例：按月销 10 万预估提成 */
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'calc_type' => 'required|string',
            'rate' => 'nullable|numeric',
            'unit_amount' => 'nullable|numeric',
            'unit_base' => 'nullable|string',
            'tier_data' => 'nullable|array',
            'product_rules' => 'nullable|array',
            'base_on_payment' => 'boolean',
        ]);
        $sample = (float) $request->input('sample_amount', 100000);

        // 复用引擎的 applyRule，构造一条假聚合做预估
        $fakeRule = new CommissionRule($data);
        $calc = new CommissionCalculator;
        $ref = new \ReflectionMethod($calc, 'applyRule');
        $ref->setAccessible(true);
        [$base, $amount] = $ref->invoke($calc, $fakeRule, [(object) [
            'quantity' => 100,
            'amount' => $sample,
            'paid_amount' => $sample,
            'cost_amount' => $sample * 0.8,
            'product_id' => 0,
        ]], [
            'sale_amount' => $sample,
            'payment_amount' => $sample,
            'return_amount' => 0,
            'gross_profit' => $sample * 0.2,
        ]);

        return $this->success(['base' => round($base, 2), 'amount' => round($amount, 2)]);
    }

    private function validateRule(Request $request, bool $isUpdate = false): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'calc_type' => 'required|in:sale_rate,profit_rate,fixed_per_unit,tiered,product_specific',
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

        // 阶梯区间连续性校验
        if (($data['calc_type'] ?? '') === 'tiered' && ! empty($data['tier_data'])) {
            $this->validateTiers($data['tier_data']);
        }

        return $data;
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
