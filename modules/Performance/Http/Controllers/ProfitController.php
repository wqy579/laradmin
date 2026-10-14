<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 多维利润查询（绩效分析 → 利润查询）。
 *
 * 维度：orders / customers / employees / products / brands。
 * 毛利口径与现有报表一致：销售金额 − 销售成本 − 退货金额 − 费用分摊。
 * 费用分摊：按销售额比例分摊 expenses（销售费用）到各维度行，提供开关。
 * 回款 / 未回款：receives 已审核收款按订单汇总。
 */
class ProfitController extends Controller
{
    /** 路径版维度入口：GET perf/profit/{dimension} → 维度名注入后复用 index */
    public function byDimension(Request $request, string $dimension): JsonResponse
    {
        $request->merge(['dimension' => $dimension]);

        return $this->index($request);
    }

    /** 与界面同筛选的服务端导出（循环翻页取全量，避免 page_size 上限截断） */
    public function export(Request $request)
    {
        $dimension = $request->input('dimension', 'customers');
        $headers = ['维度', '数量', '销售金额', '成本金额', '退货金额', '费用分摊', '毛利', '毛利率', '回款金额', '未回款金额'];
        $rows = [];
        $page = 1;
        $total = 0;
        do {
            $request->merge(['page' => $page, 'page_size' => 500]);
            $data = $this->index($request)->getData(true)['data'] ?? [];
            $list = $data['list'] ?? [];
            foreach ($list as $r) {
                $rows[] = [
                    $r['dim_name'] ?? '-',
                    $r['qty'] ?? 0,
                    $r['sale_amount'] ?? 0,
                    $r['cost_amount'] ?? 0,
                    $r['return_amount'] ?? 0,
                    $r['expense_amount'] ?? 0,
                    $r['gross_profit'] ?? 0,
                    $r['profit_rate'] ?? 0,
                    $r['payment_amount'] ?? 0,
                    $r['uncollected_amount'] ?? 0,
                ];
            }
            $total = (int) ($data['total'] ?? 0);
            $got = count($list);
            $page++;
        } while ($got > 0 && count($rows) < $total && $page <= 200);

        $csv = ReportFilter::csv('利润查询-'.$dimension, $headers, $rows);

        return ReportFilter::csvResponse($csv, 'profit_'.$dimension.'_'.date('Ymd_His').'.csv');
    }

    public function index(Request $request): JsonResponse
    {
        $dimension = $request->input('dimension', 'customers');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $apportion = $request->boolean('apportion', true);
        $onlyProfit = $request->boolean('only_profit');
        $onlyLoss = $request->boolean('only_loss');

        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id');

        if ($startDate && $endDate) {
            $query->whereBetween('so.order_date', [$startDate, $endDate]);
        }
        if ($request->filled('customer_ids')) {
            $query->whereIn('so.customer_id', ReportFilter::toArray($request->input('customer_ids')));
        }
        if ($request->filled('salesman_ids')) {
            $query->whereIn('so.salesman_id', ReportFilter::toArray($request->input('salesman_ids')));
        }
        if ($request->filled('brand_ids')) {
            $query->whereIn('p.brand_id', ReportFilter::toArray($request->input('brand_ids')));
        }
        if ($request->filled('main_category_ids')) {
            $query->whereIn('p.main_category_id', ReportFilter::toArray($request->input('main_category_ids')));
        }
        if ($request->filled('product_ids')) {
            $query->whereIn('soi.product_id', ReportFilter::toArray($request->input('product_ids')));
        }

        // 退货按「订单+商品」聚合，回款按「订单」聚合：销售明细行 join 到订单级金额会按行数放大
        // （订单1两行 → 退货4变8、回款100变200）。退货按商品行数均分；回款按行金额占比分摊
        // （订单1：矿泉水20/100×100=20、饼干80/100×100=80），合计还原真实总额。
        $query->leftJoinSub(
            DB::table('sales_order_items')
                ->select('sales_order_id', DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE quantity * (CASE WHEN price > 0 THEN price ELSE price_small END) END) as amt_sum'))
                ->groupBy('sales_order_id'),
            'lc', 'lc.sales_order_id', '=', 'so.id'
        );
        $query->leftJoinSub(
            DB::table('sales_return_items as sri')
                ->join('sales_returns as sr', 'sr.id', '=', 'sri.return_id')
                ->where('sr.status', 'approved')
                ->groupBy('sr.order_id', 'sri.product_id')
                ->select('sr.order_id', 'sri.product_id', DB::raw('SUM(sri.return_amount) as ret_amt')),
            'ret', fn ($j) => $j->on('ret.order_id', '=', 'so.id')->on('ret.product_id', '=', 'soi.product_id')
        );
        $query->leftJoinSub(
            DB::table('sales_order_items')
                ->select('sales_order_id', 'product_id', DB::raw('COUNT(*) as pcnt'))
                ->groupBy('sales_order_id', 'product_id'),
            'plc', fn ($j) => $j->on('plc.sales_order_id', '=', 'so.id')->on('plc.product_id', '=', 'soi.product_id')
        );
        // 回款
        $query->leftJoinSub(
            DB::table('receives')->select('sales_order_id', DB::raw('SUM(amount) as paid'))
                ->where('status', 1)->groupBy('sales_order_id'),
            'rcv', 'rcv.sales_order_id', '=', 'so.id'
        );

        $lineAmount = 'CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END';

        $measures = [
            DB::raw('SUM(soi.quantity) as qty'),
            DB::raw('SUM('.$lineAmount.') as sale_amount'),
            DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
            DB::raw('COALESCE(SUM(COALESCE(ret.ret_amt, 0) / NULLIF(plc.pcnt, 0)), 0) as return_amount'),
            DB::raw('COALESCE(SUM(COALESCE(rcv.paid, 0) * ('.$lineAmount.') / NULLIF(lc.amt_sum, 0)), 0) as payment_amount'),
        ];

        [$dimSelect, $dimGroup, $dimKey, $dimLabel] = $this->dimensionDef($dimension);
        $query->select(array_merge($dimSelect, $measures));

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(500, max(1, (int) $request->input('page_size', 30)));

        $total = $query->count();
        $rows = $query->groupBy($dimGroup)->orderByDesc('sale_amount')
            ->forPage($page, $pageSize)->get();

        // 费用分摊：销售费用按销售额比例分摊
        $expenseTotal = 0;
        if ($apportion && $startDate && $endDate) {
            $expenseTotal = (float) DB::table('expenses')
                ->whereBetween('expense_date', [$startDate, $endDate])
                ->whereIn('status', [1, 2])
                ->sum('amount');
        }
        $sumSale = $rows->sum('sale_amount');

        $list = $rows->map(function ($r) use ($expenseTotal, $sumSale, $dimKey, $dimLabel) {
            $sale = (float) $r->sale_amount;
            $cost = (float) $r->cost_amount;
            $ret = (float) $r->return_amount;
            $paid = (float) $r->payment_amount;
            $apportioned = $sumSale > 0 ? $expenseTotal * ($sale / $sumSale) : 0;
            $profit = $sale - $cost - $ret - $apportioned;
            $rate = $sale > 0 ? $profit / $sale * 100 : 0;

            return [
                'dim_id' => $r->{$dimKey} ?? null,
                'dim_name' => $r->{$dimLabel} ?? '-',
                'qty' => ReportFilter::num((float) $r->qty),
                'sale_amount' => ReportFilter::num($sale),
                'cost_amount' => ReportFilter::num($cost),
                'return_amount' => ReportFilter::num($ret),
                'expense_amount' => ReportFilter::num($apportioned),
                'gross_profit' => ReportFilter::num($profit),
                'profit_rate' => round($rate, 2),
                'payment_amount' => ReportFilter::num($paid),
                'uncollected_amount' => ReportFilter::num($sale - $ret - $paid),
            ];
        })->filter(function ($r) use ($onlyProfit, $onlyLoss) {
            if ($onlyProfit && $r['gross_profit'] <= 0) {
                return false;
            }
            if ($onlyLoss && $r['gross_profit'] >= 0) {
                return false;
            }

            return true;
        })->values();

        // 合计行（全量不分页聚合，由后端返回）
        $aggQuery = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id');
        if ($startDate && $endDate) {
            $aggQuery->whereBetween('so.order_date', [$startDate, $endDate]);
        }
        if ($request->filled('customer_ids')) {
            $aggQuery->whereIn('so.customer_id', ReportFilter::toArray($request->input('customer_ids')));
        }
        if ($request->filled('salesman_ids')) {
            $aggQuery->whereIn('so.salesman_id', ReportFilter::toArray($request->input('salesman_ids')));
        }
        if ($request->filled('brand_ids')) {
            $aggQuery->whereIn('p.brand_id', ReportFilter::toArray($request->input('brand_ids')));
        }
        if ($request->filled('main_category_ids')) {
            $aggQuery->whereIn('p.main_category_id', ReportFilter::toArray($request->input('main_category_ids')));
        }
        if ($request->filled('product_ids')) {
            $aggQuery->whereIn('soi.product_id', ReportFilter::toArray($request->input('product_ids')));
        }
        $aggQuery->leftJoinSub(
            DB::table('sales_order_items')
                ->select('sales_order_id', DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE quantity * (CASE WHEN price > 0 THEN price ELSE price_small END) END) as amt_sum'))
                ->groupBy('sales_order_id'),
            'alc', 'alc.sales_order_id', '=', 'so.id'
        );
        $aggQuery->leftJoinSub(
            DB::table('sales_return_items as sri')
                ->join('sales_returns as sr', 'sr.id', '=', 'sri.return_id')
                ->where('sr.status', 'approved')
                ->groupBy('sr.order_id', 'sri.product_id')
                ->select('sr.order_id', 'sri.product_id', DB::raw('SUM(sri.return_amount) as ret_amt')),
            'aret', fn ($j) => $j->on('aret.order_id', '=', 'so.id')->on('aret.product_id', '=', 'soi.product_id')
        );
        $aggQuery->leftJoinSub(
            DB::table('sales_order_items')
                ->select('sales_order_id', 'product_id', DB::raw('COUNT(*) as pcnt'))
                ->groupBy('sales_order_id', 'product_id'),
            'aplc', fn ($j) => $j->on('aplc.sales_order_id', '=', 'so.id')->on('aplc.product_id', '=', 'soi.product_id')
        );
        $aggQuery->leftJoinSub(
            DB::table('receives')->select('sales_order_id', DB::raw('SUM(amount) as paid'))
                ->where('status', 1)->groupBy('sales_order_id'),
            'arcv', 'arcv.sales_order_id', '=', 'so.id'
        );
        $lineExpr = 'CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END';
        $totals = $aggQuery->select(
            DB::raw('SUM('.$lineExpr.') as t_sale'),
            DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as t_cost'),
            DB::raw('COALESCE(SUM(COALESCE(aret.ret_amt, 0) / NULLIF(aplc.pcnt, 0)), 0) as t_ret'),
            DB::raw('COALESCE(SUM(COALESCE(arcv.paid, 0) * ('.$lineExpr.') / NULLIF(alc.amt_sum, 0)), 0) as t_paid')
        )->first();
        $tSale = (float) ($totals->t_sale ?? 0);
        $tCost = (float) ($totals->t_cost ?? 0);
        $tRet = (float) ($totals->t_ret ?? 0);
        $tPaid = (float) ($totals->t_paid ?? 0);

        return $this->success([
            'dimension' => $dimension,
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'summary' => [
                'sale_amount' => ReportFilter::num($tSale),
                'cost_amount' => ReportFilter::num($tCost),
                'return_amount' => ReportFilter::num($tRet),
                'payment_amount' => ReportFilter::num($tPaid),
                'gross_profit' => ReportFilter::num($tSale - $tCost - $tRet - $expenseTotal),
                'profit_rate' => $tSale > 0 ? round(($tSale - $tCost - $tRet - $expenseTotal) / $tSale * 100, 2) : 0,
                'expense_amount' => ReportFilter::num($expenseTotal),
            ],
        ]);
    }

    /** 近 12 个月毛利趋势 */
    public function trend(Request $request): JsonResponse
    {
        $months = [];
        $now = now();
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'sale' => 0, 'profit' => 0];
        }

        $start = now()->subMonths(11)->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();

        $rows = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoinSub(
                DB::table('sales_return_items as sri')
                    ->join('sales_returns as sr', 'sr.id', '=', 'sri.return_id')
                    ->where('sr.status', 'approved')
                    ->groupBy('sr.order_id', 'sri.product_id')
                    ->select('sr.order_id', 'sri.product_id', DB::raw('SUM(sri.return_amount) as ret_amt')),
                'ret', fn ($j) => $j->on('ret.order_id', '=', 'so.id')->on('ret.product_id', '=', 'soi.product_id')
            )
            ->leftJoinSub(
                DB::table('sales_order_items')
                    ->select('sales_order_id', 'product_id', DB::raw('COUNT(*) as pcnt'))
                    ->groupBy('sales_order_id', 'product_id'),
                'plc', fn ($j) => $j->on('plc.sales_order_id', '=', 'so.id')->on('plc.product_id', '=', 'soi.product_id')
            )
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->select(
                DB::raw("DATE_FORMAT(so.order_date, '%Y-%m') as ym"),
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale'),
                DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost'),
                DB::raw('COALESCE(SUM(COALESCE(ret.ret_amt, 0) / NULLIF(plc.pcnt, 0)), 0) as ret')
            )
            ->groupBy('ym')
            ->get();

        foreach ($rows as $r) {
            if (isset($months[$r->ym])) {
                $months[$r->ym]['sale'] = ReportFilter::num((float) $r->sale);
                $months[$r->ym]['profit'] = ReportFilter::num((float) $r->sale - (float) $r->cost - (float) $r->ret);
            }
        }

        return $this->success(array_values($months));
    }

    private function dimensionDef(string $dimension): array
    {
        return match ($dimension) {
            'orders' => [
                ['so.id as dim_id', 'so.order_no as dim_name', DB::raw('so.order_date as order_date')],
                ['so.id', 'so.order_no'], 'dim_id', 'dim_name',
            ],
            'customers' => [
                ['c.id as dim_id', 'c.name as dim_name'],
                ['c.id', 'c.name'], 'dim_id', 'dim_name',
            ],
            'employees' => [
                ['so.salesman_id as dim_id', DB::raw('COALESCE(sm.real_name, sm.username, "未指派") as dim_name')],
                ['so.salesman_id', 'sm.username', 'sm.real_name'], 'dim_id', 'dim_name',
            ],
            'products' => [
                ['p.id as dim_id', 'p.name as dim_name'],
                ['p.id', 'p.name'], 'dim_id', 'dim_name',
            ],
            'brands' => [
                ['b.id as dim_id', DB::raw('COALESCE(b.name, "无品牌") as dim_name')],
                ['b.id', 'b.name'], 'dim_id', 'dim_name',
            ],
            default => $this->dimensionDef('customers'),
        };
    }
}
