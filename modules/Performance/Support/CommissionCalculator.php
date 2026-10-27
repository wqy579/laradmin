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

        // 退货按订单单独取：sales_return_items 是退货单自己的明细，
        // join 到销售明细行会把同一订单的退货金额按行数放大（订单1两行 → 退货4变8），
        // 所以先按 order_id 聚成一张 map，再在 PHP 侧按订单贴回每行。
        $returns = DB::table('sales_returns as sr')
            ->leftJoin('sales_return_items as sri', 'sri.return_id', '=', 'sr.id')
            ->where('sr.status', 'approved')
            ->whereNotNull('sr.order_id')
            ->groupBy('sr.order_id')
            ->select('sr.order_id', DB::raw('SUM(sri.return_amount) as ret_amt'))
            ->pluck('ret_amt', 'order_id');

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
                DB::raw('COALESCE(sm.real_name, sm.username, "未指派") as salesman_name'),
                DB::raw('COALESCE(e.code, sm.username, "—") as salesman_code'),
                DB::raw('COALESCE(e.department, "") as department_name'),
            ])
            ->get()
            ->map(function ($r) use ($returns) {
                $r = (object) (array) $r;
                $r->return_amount = (float) ($returns[$r->order_id] ?? 0);

                return $r;
            })
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
        // 回款/退货都是订单级金额，同一订单多行只取一次（否则会按行数放大）
        $paidByOrder = [];
        $returnByOrder = [];
        foreach ($lines as $l) {
            $paidByOrder[$l->order_id] = (float) $l->paid_amount;
            $returnByOrder[$l->order_id] = (float) $l->return_amount;
        }
        $paymentAmount = array_sum($paidByOrder);
        $returnAmount = array_sum($returnByOrder);
        $grossProfit = $saleAmount - $costAmount - $returnAmount;

        // 命中方案
        $matched = $rules->filter(fn ($rule) => $this->ruleMatchesEmployee($rule, $empId))->values();
        if ($matched->isEmpty()) {
            return null;
        }

        $detailLines = [];
        $commissionGross = 0.0;
        $commissionBase = 0.0;
        $commissionDeduct = 0.0;
        $commissionNet = 0.0;
        $appliedRule = null;
        $appliedNames = [];

        // 聚合键双写：旧名（内部/兼容）+ 任务书 §八 新名（对外）
        $aggs = [
            'sale_amount' => $saleAmount,
            'payment_amount' => $paymentAmount,
            'return_amount' => $returnAmount,
            'gross_profit' => $grossProfit,
            'sales_amount' => $saleAmount,
            'received_amount' => $paymentAmount,
            'profit_amount' => $grossProfit,
        ];

        // 每个方案独立按其方式计算（含方案的商品/客户等级范围过滤）
        $calculated = [];
        foreach ($matched as $rule) {
            [$ruleBase, $ruleAmount, $ruleLines, $scoped] = $this->applyRule($rule, $lines, $aggs);
            if ($ruleAmount <= 0) {
                continue;
            }

            // 扣减：退货按比例扣减（deduct_return）
            $deduct = 0.0;
            if ($rule->deduct_return && $scoped['return_amount'] > 0 && $scoped['sale_amount'] > 0) {
                $deduct = round($ruleAmount * ($scoped['return_amount'] / $scoped['sale_amount']), 2);
            }
            $net = max(0.0, $ruleAmount - $deduct);

            // 封顶 / 保底（按方案各自的 cap/floor）
            if ($rule->cap_amount > 0 && $net > (float) $rule->cap_amount) {
                $net = (float) $rule->cap_amount;
            }
            if ($rule->floor_amount > 0 && $net < (float) $rule->floor_amount) {
                $net = (float) $rule->floor_amount;
            }

            $calculated[] = compact('rule', 'ruleBase', 'ruleAmount', 'ruleLines', 'deduct', 'net');
        }

        if (empty($calculated)) {
            return null;
        }

        // allow_stack=true（任一命中方案允许叠加）→ 求和；默认取净额最高不重复计
        $stack = false;
        foreach ($calculated as $c) {
            if ($c['rule']->allow_stack) {
                $stack = true;
                break;
            }
        }

        if ($stack) {
            foreach ($calculated as $c) {
                $commissionBase += $c['ruleBase'];
                $commissionGross += $c['ruleAmount'];
                $commissionDeduct += $c['deduct'];
                $commissionNet += $c['net'];
                $appliedNames[] = $c['rule']->name;
                $detailLines = array_merge($detailLines, $c['ruleLines']);
            }
            // 主方案取贡献最大的一条（明细/单据展示用）
            $primary = $calculated[0];
            foreach ($calculated as $c) {
                if ($c['net'] > $primary['net']) {
                    $primary = $c;
                }
            }
            $appliedRule = $primary['rule'];
            $appliedRuleName = implode(' + ', $appliedNames);
        } else {
            $primary = $calculated[0];
            foreach ($calculated as $c) {
                if ($c['net'] > $primary['net']) {
                    $primary = $c;
                }
            }
            $appliedRule = $primary['rule'];
            $appliedRuleName = $primary['rule']->name;
            $commissionBase = $primary['ruleBase'];
            $commissionGross = $primary['ruleAmount'];
            $commissionDeduct = $primary['deduct'];
            $commissionNet = $primary['net'];
            $detailLines = $primary['ruleLines'];
        }

        // 任务书 §八 口径：sales_amount 销售额 / received_amount 回款 / return_amount 退货 /
        // profit_amount 毛利 / base_amount 计提基数 / commission_amount 应发 / deduction 扣减 /
        // actual_amount 实发。旧键同包双写，兼容仍在读旧名的调用方。
        return [
            'employee_id' => $empId,
            'employee_name' => $empName,
            'employee_code' => $empCode,
            'department_name' => $dept,
            'period' => $period,
            'sales_amount' => round($saleAmount, 2),
            'received_amount' => round($paymentAmount, 2),
            'return_amount' => round($returnAmount, 2),
            'profit_amount' => round($grossProfit, 2),
            'base_amount' => round($commissionBase, 2),
            'commission_amount' => round($commissionGross, 2),
            'deduction' => round($commissionDeduct, 2),
            'actual_amount' => round($commissionNet, 2),
            'sale_amount' => round($saleAmount, 2),
            'payment_amount' => round($paymentAmount, 2),
            'gross_profit' => round($grossProfit, 2),
            'commission_base' => round($commissionBase, 2),
            'commission_gross' => round($commissionGross, 2),
            'commission_deduct' => round($commissionDeduct, 2),
            'rule_id' => $appliedRule->id,
            'rule_name' => $appliedRuleName,
            'calc_type' => $appliedRule->calc_type,
            'rate' => (float) $appliedRule->rate, // generate 落库 perf_commission_orders.rate（NOT NULL）
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
     * exclude_baddebt=true 时未回款部分不计入基数（与 base_on_payment 独立：
     * 比例类按"已回款销售额"、固定件类按"有回款订单的销量"）。
     * product_scope_type/apply_customer_limits 先按行过滤，只对范围内行计酬。
     *
     * 返回 [base, amount, detailLines, scopedAggs]。
     * scopedAggs 供 calcEmployee 计算退货扣减（扣减只对命中范围的销售/退货生效）。
     */
    private function applyRule(CommissionRule $rule, array $lines, array $agg): array
    {
        // 1) 商品范围过滤（all/category/brand/product）
        $scopedLines = $this->filterProductScope($rule, $lines);

        // 2) 客户等级过滤（apply_customer_limits 空=全部）
        $levels = $rule->apply_customer_levels;
        if (! empty($levels)) {
            $levelSet = array_map('intval', (array) $levels);
            $scopedLines = array_values(array_filter(
                $scopedLines,
                fn ($l) => in_array((int) ($l->customer_level_id ?? 0), $levelSet, true)
            ));
        }

        if (empty($scopedLines)) {
            return [0.0, 0.0, [], $this->scopedAgg($agg, 0, 0, 0, 0)];
        }

        // 范围内聚合（回款/退货按订单去重，与 calcEmployee 同口径）
        $sale = 0.0;
        $cost = 0.0;
        $qty = 0.0;
        $paidByOrder = [];
        $retByOrder = [];
        foreach ($scopedLines as $l) {
            $sale += (float) $l->amount;
            $cost += (float) $l->cost_amount;
            $qty += (float) $l->quantity;
            $paidByOrder[$l->order_id] = (float) $l->paid_amount;
            $retByOrder[$l->order_id] = (float) $l->return_amount;
        }
        $paid = array_sum($paidByOrder);
        $ret = array_sum($retByOrder);
        $gross = $sale - $cost - $ret;
        $scopedAgg = [
            'sale_amount' => $sale,
            'payment_amount' => $paid,
            'return_amount' => $ret,
            'gross_profit' => $gross,
            'sales_amount' => $sale,
            'received_amount' => $paid,
            'profit_amount' => $gross,
        ];

        // 3) exclude_baddebt：未回款不计入基数（比例类把销售基数压到已回款部分）
        $useSale = $sale;
        if ($rule->exclude_baddebt && $sale > 0) {
            $ratio = $sale > 0 ? min(1.0, max(0.0, $paid / $sale)) : 0.0;
            $useSale = $sale * $ratio;
        }

        $base = match ($rule->calc_type) {
            'sale_rate' => $rule->base_on_payment
                ? $paid
                : ($rule->exclude_baddebt ? $useSale : $sale),
            'profit_rate' => $rule->base_on_payment
                ? ($paid - ($sale > 0 ? $paid / $sale * $gross : 0))
                : ($rule->exclude_baddebt ? ($sale > 0 ? $useSale / $sale : 0) * $gross : $gross),
            'fixed_per_unit' => $rule->exclude_baddebt
                ? $this->paidQty($scopedLines)
                : $qty,
            'tiered' => $rule->base_on_payment
                ? $paid
                : ($rule->exclude_baddebt ? $useSale : $sale),
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
                    'sales_amount' => round($sale, 2),
                    'cost_amount' => round(max(0, $sale - $gross), 2),
                    'profit_amount' => round($gross, 2),
                    'quantity' => $qty,
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
                    'sales_amount' => round($sale, 2),
                    'cost_amount' => round(max(0, $sale - $gross), 2),
                    'profit_amount' => round($gross, 2),
                    'quantity' => $qty,
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
                    'sales_amount' => round($sale, 2),
                    'cost_amount' => round(max(0, $sale - $gross), 2),
                    'profit_amount' => round($gross, 2),
                    'quantity' => $qty,
                    'base_amount' => round($base, 2),
                    'rule_name' => $rule->name,
                    'calc_type' => 'tiered',
                    'rate_or_unit' => '阶梯',
                    'amount' => $amount,
                ]];
                break;

            case 'product_specific':
                // 回款基数：回款是订单级金额，按订单内各商品行销售额比例分摊到行
                $orderAmount = [];
                $orderPaid = [];
                foreach ($scopedLines as $l) {
                    $orderAmount[$l->order_id] = ($orderAmount[$l->order_id] ?? 0) + (float) $l->amount;
                    $orderPaid[$l->order_id] = (float) $l->paid_amount;
                }

                foreach ($rule->product_rules ?? [] as $pr) {
                    $pid = (int) ($pr['product_id'] ?? 0);
                    if (! $pid) {
                        continue;
                    }
                    $hit = array_values(array_filter($scopedLines, fn ($l) => (int) $l->product_id === $pid));
                    if (empty($hit)) {
                        continue;
                    }
                    $qtyHit = array_sum(array_map(fn ($l) => (float) $l->quantity, $hit));
                    $saleHit = array_sum(array_map(fn ($l) => (float) $l->amount, $hit));
                    $paidHit = 0.0;
                    $paidTouched = [];
                    foreach ($hit as $l) {
                        if (isset($paidTouched[$l->order_id])) {
                            continue;
                        }
                        $paidTouched[$l->order_id] = true;
                        $orderAmt = (float) ($orderAmount[$l->order_id] ?? 0);
                        $share = $orderAmt > 0 ? $saleHit / $orderAmt : 0.0;
                        $paidHit += (float) ($orderPaid[$l->order_id] ?? 0) * $share;
                    }
                    $lineBase = ($rule->base_on_payment || $rule->exclude_baddebt)
                        ? $paidHit
                        : $saleHit;
                    if ($rule->exclude_baddebt && $rule->base_on_payment) {
                        // 两个开关都开时基数同为已回款部分，无需再压
                        $lineBase = $paidHit;
                    } elseif ($rule->exclude_baddebt && ! $rule->base_on_payment) {
                        $lineBase = $saleHit > 0 ? min($saleHit, $paidHit) : 0;
                    }

                    if (array_key_exists('rate', $pr) && $pr['rate'] !== null && $pr['rate'] !== '') {
                        $lineAmount = round($lineBase * ((float) $pr['rate'] / 100), 2);
                        $rateStr = $pr['rate'].'%';
                    } else {
                        $useQty = $rule->exclude_baddebt ? $this->paidQty(array_values(array_filter($hit, fn ($l) => (float) $l->paid_amount > 0))) : $qtyHit;
                        $lineAmount = round($useQty * (float) ($pr['amount'] ?? 0), 2);
                        $rateStr = '¥'.($pr['amount'] ?? 0).'/件';
                    }
                    $amount += $lineAmount;
                    $hitCost = array_sum(array_map(fn ($l) => (float) ($l->cost_amount ?? 0), $hit));
                    $hitProfit = $saleHit - $hitCost;
                    $detail[] = [
                        'source_type' => 'product_line',
                        'source_no' => $pr['product_name'] ?? ('商品#'.$pid),
                        'product_id' => $pid,
                        'quantity' => $qtyHit,
                        'sales_amount' => round($saleHit, 2),
                        'cost_amount' => round($hitCost, 2),
                        'profit_amount' => round($hitProfit, 2),
                        'base_amount' => round($lineBase, 2),
                        'rule_name' => $rule->name,
                        'calc_type' => 'product_specific',
                        'rate_or_unit' => $rateStr,
                        'amount' => $lineAmount,
                    ];
                }
                break;
        }

        return [round($base, 2), $amount, $detail, $scopedAgg];
    }

    /** applyRule 返回值里的范围聚合（用于退货扣减按命中范围计算） */
    private function scopedAgg(array $agg, float $sale, float $paid, float $ret, float $gross): array
    {
        return [
            'sale_amount' => $sale,
            'payment_amount' => $paid,
            'return_amount' => $ret,
            'gross_profit' => $gross,
            'sales_amount' => $sale,
            'received_amount' => $paid,
            'profit_amount' => $gross,
            '_full' => $agg,
        ];
    }

    /** 已回款订单上的销量（订单有任一回款即整单销量计入——未回款不计提按订单粒度） */
    private function paidQty(array $lines): float
    {
        $seen = [];
        $qty = 0.0;
        foreach ($lines as $l) {
            if (isset($seen[$l->order_id])) {
                continue;
            }
            $seen[$l->order_id] = true;
            if ((float) $l->paid_amount <= 0) {
                continue;
            }
            // 该订单所有行的销量
            foreach ($lines as $x) {
                if ($x->order_id === $l->order_id) {
                    $qty += (float) $x->quantity;
                }
            }
        }

        return $qty;
    }

    /** 按方案 product_scope_type/ids 过滤订单行；scope=all 时原样返回 */
    private function filterProductScope(CommissionRule $rule, array $lines): array
    {
        $type = (string) ($rule->product_scope_type ?? 'all');
        if ($type === 'all' || $type === '' || $type === null) {
            return $lines;
        }
        $ids = array_map('intval', (array) ($rule->product_scope_ids ?? []));
        if (empty($ids)) {
            return $lines; // 选了范围但没选具体项 → 视作全部（前端必填校验兜底）
        }

        return array_values(array_filter($lines, function ($l) use ($type, $ids) {
            $key = match ($type) {
                'category' => (int) ($l->main_category_id ?? 0),
                'brand' => (int) ($l->brand_id ?? 0),
                'product' => (int) ($l->product_id ?? 0),
                default => 0,
            };

            return in_array($key, $ids, true);
        }));
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

    /** totals 汇总键（任务书新名 + 旧名双写；commission_amount=应发、actual_amount=实发） */
    private const TOTAL_KEYS = [
        'sales_amount', 'received_amount', 'return_amount', 'profit_amount',
        'base_amount', 'commission_amount', 'deduction', 'actual_amount',
        'sale_amount', 'payment_amount', 'gross_profit',
        'commission_base', 'commission_gross', 'commission_deduct',
    ];

    private function sumGroups(array $groups): array
    {
        $t = array_fill_keys(self::TOTAL_KEYS, 0);
        foreach ($groups as $g) {
            foreach (self::TOTAL_KEYS as $k) {
                if (isset($g[$k])) {
                    $t[$k] += $g[$k];
                }
            }
        }

        return array_map(fn ($v) => round($v, 2), $t);
    }

    private function emptyTotals(): array
    {
        return array_fill_keys(self::TOTAL_KEYS, 0);
    }
}
