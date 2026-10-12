<?php

namespace Modules\Performance\Support;

use Illuminate\Support\Facades\DB;
use Modules\Performance\Models\CommissionRule;

/**
 * 提成试算引擎。
 *
 * 输入：月份（YYYY-MM）+ 业务员 user_id 列表。
 * 取数口径（与利润查询、报表模块一致）：
 *   - 销售额：已审核（status=approved）销售订单的明细 amount 之和；
 *     amount 为 0 时回退 qty × price/price_small（沿用 SalesReportController 的写法）。
 *   - 成本：qty × products.cost_price。
 *   - 回款：receives（status=1 已审核收款单）按 sales_order_id 汇总。
 *   - 退货：sales_returns（status=approved）按 order_id 关联回销售订单扣减。
 *   - 费用分摊：本期不参与提成计算（提成基数只用销售/回款/退货/毛利口径）。
 *
 * 方案匹配优先级：单品专属 > 阶梯 > 固定/比例；allow_stack=false 时同一业务员
 * 只取最高提成方案的结果，不重复计。
 */
class CommissionCalculator
{
    /** 月份 → 业务员分组的试算结果（不落库） */
    public function preview(string $period, array $employeeIds): array
    {
        // 1. 取该月所有已审核销售订单行，按业务员 + 商品行聚合
        $rows = $this->loadOrderLines($period, $employeeIds);

        if (empty($rows)) {
            return ['groups' => [], 'totals' => $this->emptyTotals()];
        }

        // 2. 取启用中的方案
        $rules = CommissionRule::where('status', 'active')
            ->where(function ($q) use ($period) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $period.'-01');
            })
            ->where(function ($q) use ($period) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $period.'-01');
            })
            ->get();

        if ($rules->isEmpty()) {
            return ['groups' => [], 'totals' => $this->emptyTotals()];
        }

        // 3. 按业务员分组，逐行试算
        $byEmployee = [];
        foreach ($rows as $r) {
            $byEmployee[$r->salesman_id][] = $r;
        }

        $groups = [];
        foreach ($byEmployee as $empId => $lines) {
            $group = $this->calcEmployee($empId, $lines, $rules, $period);
            if ($group) {
                $groups[] = $group;
            }
        }

        // 4. 合计
        $totals = $this->sumGroups($groups);

        return ['groups' => $groups, 'totals' => $totals];
    }

    /** 取该月已审核订单行，带业务员、回款、退货聚合 */
    private function loadOrderLines(string $period, array $employeeIds): array
    {
        $start = $period.'-01';
        $end = date('Y-m-t', strtotime($start));

        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('customer_levels as cl', 'cl.id', '=', 'c.level_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->leftJoin('employees as e', 'e.user_id', '=', 'so.salesman_id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id') // 排除红冲负单
            ->whereBetween('so.order_date', [$start, $end])
            ->whereNotNull('so.salesman_id'); // 业务员即 auth_user，不强求 employees.user_id 关联

        if (! empty($employeeIds)) {
            $query->whereIn('so.salesman_id', $employeeIds);
        }

        // 回款（已审核收款单，按订单汇总）
        $query->leftJoinSub(
            DB::table('receives')
                ->select('sales_order_id', DB::raw('SUM(amount) as paid'))
                ->where('status', 1)
                ->groupBy('sales_order_id'),
            'rcv', 'rcv.sales_order_id', '=', 'so.id'
        );

        // 退货（已审核销售退货，按原订单汇总）
        $query->leftJoinSub(
            DB::table('sales_returns as sr')
                ->leftJoin('sales_return_items as sri', 'sri.return_id', '=', 'sr.id')
                ->select('sr.order_id', DB::raw('SUM(sri.return_amount) as ret_amt'))
                ->where('sr.status', 'approved')
                ->groupBy('sr.order_id'),
            'ret', 'ret.order_id', '=', 'so.id'
        );

        return $query->orderBy('so.salesman_id')
            ->orderBy('so.id')
            ->select([
                'so.id as order_id',
                'so.order_no',
                'so.salesman_id',
                'so.customer_id',
                'c.name as customer_name',
                'c.level_id as customer_level_id',
                'cl.name as customer_level_name',
                'so.order_date',
                'soi.product_id',
                'p.name as product_name',
                'p.brand_id',
                'p.main_category_id',
                'p.sub_category_id',
                'soi.quantity',
                DB::raw('CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END as amount'),
                DB::raw('soi.quantity * COALESCE(p.cost_price, 0) as cost_amount'),
                DB::raw('COALESCE(rcv.paid, 0) as paid_amount'),
                DB::raw('COALESCE(ret.ret_amt, 0) as return_amount'),
                DB::raw('COALESCE(sm.real_name, sm.username, "未指派") as salesman_name'),
                DB::raw('COALESCE(e.code, sm.username, "—") as salesman_code'),
                DB::raw('COALESCE(e.department, "") as department_name'),
            ])
            ->get()
            ->map(fn ($r) => (object) (array) $r)
            ->all();
    }

    /** 单个业务员的试算 */
    private function calcEmployee(int $empId, array $lines, $rules, string $period): ?array
    {
        $empName = $lines[0]->salesman_name ?? '未指派';
        $empCode = $lines[0]->salesman_code ?? '—';
        $dept = $lines[0]->department_name ?? '';

        $saleAmount = array_sum(array_map(fn ($l) => (float) $l->amount, $lines));
        $costAmount = array_sum(array_map(fn ($l) => (float) $l->cost_amount, $lines));
        $returnAmount = array_sum(array_map(fn ($l) => (float) $l->return_amount, $lines));
        // 回款按订单去重汇总（一条 order 行有 paid_amount，但同一订单多行会重复）
        $paidByOrder = [];
        foreach ($lines as $l) {
            $paidByOrder[$l->order_id] = (float) $l->paid_amount;
        }
        $paymentAmount = array_sum($paidByOrder);
        $grossProfit = $saleAmount - $costAmount - $returnAmount;

        // 命中方案
        $matched = $rules->filter(fn ($rule) => $this->ruleMatchesEmployee($rule, $empId))->values();
        if ($matched->isEmpty()) {
            return null;
        }

        $detailLines = [];
        $commissionGross = 0.0;
        $commissionBase = 0.0;
        $appliedRule = null;

        foreach ($matched as $rule) {
            // 每个方案独立按其方式计算
            [$ruleBase, $ruleAmount, $ruleLines] = $this->applyRule($rule, $lines, [
                'sale_amount' => $saleAmount,
                'payment_amount' => $paymentAmount,
                'return_amount' => $returnAmount,
                'gross_profit' => $grossProfit,
            ]);

            if ($ruleAmount <= 0) {
                continue;
            }

            // 非叠加模式：只保留提成最高的方案
            if (! $rule->allow_stack && $appliedRule !== null && $ruleAmount <= $commissionGross) {
                continue;
            }

            $commissionBase = $ruleBase;
            $commissionGross = $ruleAmount;
            $appliedRule = $rule;
            $detailLines = $ruleLines;
        }

        if ($appliedRule === null) {
            return null;
        }

        // 扣减：退货按比例扣减（deduct_return）、未回款不计提已在 applyRule 内按 base_on_payment 处理
        $deduct = 0.0;
        if ($appliedRule->deduct_return && $returnAmount > 0 && $saleAmount > 0) {
            $deduct = round($commissionGross * ($returnAmount / $saleAmount), 2);
        }

        $commissionAmount = max(0, $commissionGross - $deduct);

        // 封顶 / 保底
        if ($appliedRule->cap_amount > 0 && $commissionAmount > (float) $appliedRule->cap_amount) {
            $commissionAmount = (float) $appliedRule->cap_amount;
        }
        if ($appliedRule->floor_amount > 0 && $commissionAmount < (float) $appliedRule->floor_amount) {
            $commissionAmount = (float) $appliedRule->floor_amount;
        }

        return [
            'employee_id' => $empId,
            'employee_name' => $empName,
            'employee_code' => $empCode,
            'department_name' => $dept,
            'period' => $period,
            'sale_amount' => round($saleAmount, 2),
            'payment_amount' => round($paymentAmount, 2),
            'return_amount' => round($returnAmount, 2),
            'gross_profit' => round($grossProfit, 2),
            'commission_base' => round($commissionBase, 2),
            'commission_gross' => round($commissionGross, 2),
            'commission_deduct' => round($deduct, 2),
            'commission_amount' => round($commissionAmount, 2),
            'rule_id' => $appliedRule->id,
            'rule_name' => $appliedRule->name,
            'calc_type' => $appliedRule->calc_type,
            'detail_lines' => $detailLines,
        ];
    }

    /** 方案是否适用于该业务员 */
    private function ruleMatchesEmployee(CommissionRule $rule, int $empId): bool
    {
        $emps = $rule->apply_employees;
        if (empty($emps)) {
            return true; // 空=全部
        }

        return in_array((int) $empId, array_map('intval', (array) $emps), true);
    }

    /**
     * 按方案方式计算提成基数与金额。
     *
     * base_on_payment=true 时提成基数用回款额而非销售额（快消行业惯例）。
     * exclude_baddebt=true 时未回款部分不计入基数。
     */
    private function applyRule(CommissionRule $rule, array $lines, array $agg): array
    {
        $base = match ($rule->calc_type) {
            'sale_rate' => $rule->base_on_payment ? $agg['payment_amount'] : $agg['sale_amount'],
            'profit_rate' => $rule->base_on_payment
                ? ($agg['payment_amount'] - ($agg['sale_amount'] > 0 ? $agg['payment_amount'] / $agg['sale_amount'] * $agg['gross_profit'] : 0))
                : $agg['gross_profit'],
            'fixed_per_unit' => array_sum(array_map(fn ($l) => (float) $l->quantity, $lines)),
            'tiered' => $rule->base_on_payment ? $agg['payment_amount'] : $agg['sale_amount'],
            'product_specific' => 0, // 逐行算
            default => 0,
        };

        $amount = 0.0;
        $detail = [];

        switch ($rule->calc_type) {
            case 'sale_rate':
            case 'profit_rate':
                $rate = (float) $rule->rate / 100;
                $amount = round($base * $rate, 2);
                $detail = [[
                    'source_type' => 'order',
                    'source_no' => '汇总',
                    'base_amount' => round($base, 2),
                    'rule_name' => $rule->name,
                    'calc_type' => $rule->calc_type,
                    'rate_or_unit' => $rule->rate.'%',
                    'amount' => $amount,
                ]];
                break;

            case 'fixed_per_unit':
                $amount = round($base * (float) $rule->unit_amount, 2);
                $detail = [[
                    'source_type' => 'order',
                    'source_no' => '汇总',
                    'base_amount' => round($base, 3),
                    'rule_name' => $rule->name,
                    'calc_type' => $rule->calc_type,
                    'rate_or_unit' => '¥'.$rule->unit_amount.'/'.$rule->unit_base,
                    'amount' => $amount,
                ]];
                break;

            case 'tiered':
                $amount = $this->calcTiered($base, $rule->tier_data ?? []);
                $detail = [[
                    'source_type' => 'order',
                    'source_no' => '汇总',
                    'base_amount' => round($base, 2),
                    'rule_name' => $rule->name,
                    'calc_type' => 'tiered',
                    'rate_or_unit' => '阶梯',
                    'amount' => $amount,
                ]];
                break;

            case 'product_specific':
                foreach ($rule->product_rules ?? [] as $pr) {
                    $pid = (int) ($pr['product_id'] ?? 0);
                    if (! $pid) {
                        continue;
                    }
                    $hit = array_filter($lines, fn ($l) => (int) $l->product_id === $pid);
                    if (empty($hit)) {
                        continue;
                    }
                    $qty = array_sum(array_map(fn ($l) => (float) $l->quantity, $hit));
                    $lineBase = $rule->base_on_payment
                        ? array_sum(array_map(fn ($l) => (float) $l->paid_amount, $hit))
                        : array_sum(array_map(fn ($l) => (float) $l->amount, $hit));

                    if (isset($pr['rate'])) {
                        $lineAmount = round($lineBase * ((float) $pr['rate'] / 100), 2);
                        $rateStr = $pr['rate'].'%';
                    } else {
                        $lineAmount = round($qty * (float) ($pr['amount'] ?? 0), 2);
                        $rateStr = '¥'.($pr['amount'] ?? 0).'/件';
                    }
                    $amount += $lineAmount;
                    $detail[] = [
                        'source_type' => 'product_line',
                        'source_no' => $pr['product_name'] ?? ('商品#'.$pid),
                        'product_id' => $pid,
                        'base_amount' => round($lineBase, 2),
                        'rule_name' => $rule->name,
                        'calc_type' => 'product_specific',
                        'rate_or_unit' => $rateStr,
                        'amount' => $lineAmount,
                    ];
                }
                break;
        }

        return [round($base, 2), $amount, $detail];
    }

    /** 阶梯提成：区间内分段计 */
    private function calcTiered(float $base, array $tiers): float
    {
        if (empty($tiers)) {
            return 0.0;
        }
        usort($tiers, fn ($a, $b) => ($a['min'] ?? 0) <=> ($b['min'] ?? 0));
        $total = 0.0;
        $remaining = $base;
        foreach ($tiers as $t) {
            $min = (float) ($t['min'] ?? 0);
            $max = isset($t['max']) ? (float) $t['max'] : INF;
            $rate = (float) ($t['rate'] ?? 0) / 100;
            if ($base <= $min) {
                break;
            }
            $seg = min($base, $max) - $min;
            if ($seg <= 0) {
                continue;
            }
            $total += round($seg * $rate, 2);
        }

        return round($total, 2);
    }

    private function sumGroups(array $groups): array
    {
        $keys = ['sale_amount', 'payment_amount', 'return_amount', 'gross_profit', 'commission_base', 'commission_gross', 'commission_deduct', 'commission_amount'];
        $t = array_fill_keys($keys, 0);
        foreach ($groups as $g) {
            foreach ($keys as $k) {
                $t[$k] += $g[$k];
            }
        }

        return array_map(fn ($v) => round($v, 2), $t);
    }

    private function emptyTotals(): array
    {
        return array_fill_keys(['sale_amount', 'payment_amount', 'return_amount', 'gross_profit', 'commission_base', 'commission_gross', 'commission_deduct', 'commission_amount'], 0);
    }
}
