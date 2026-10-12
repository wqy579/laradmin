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

        // 退货金额（按订单汇总）
        $query->leftJoinSub(
            DB::table('sales_returns as sr')
                ->leftJoin('sales_return_items as sri', 'sri.return_id', '=', 'sr.id')
                ->select('sr.order_id', DB::raw('SUM(sri.return_amount) as ret_amt'))
                ->where('sr.status', 'approved')
                ->groupBy('sr.order_id'),
            'ret', 'ret.order_id', '=', 'so.id'
        );
        // 回款
        $query->leftJoinSub(
            DB::table('receives')->select('sales_order_id', DB::raw('SUM(amount) as paid'))
                ->where('status', 1)->groupBy('sales_order_id'),
            'rcv', 'rcv.sales_order_id', '=', 'so.id'
        );

        $measures = [
            DB::raw('SUM(soi.quantity) as qty'),
            DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale_amount'),
            DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
            DB::raw('COALESCE(SUM(ret.ret_amt), 0) as return_amount'),
            DB::raw('COALESCE(SUM(rcv.paid), 0) as payment_amount'),
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
            DB::table('sales_returns as sr')
                ->leftJoin('sales_return_items as sri', 'sri.return_id', '=', 'sr.id')
                ->select('sr.order_id', DB::raw('SUM(sri.return_amount) as ret_amt'))
                ->where('sr.status', 'approved')
                ->groupBy('sr.order_id'),
            'aret', 'aret.order_id', '=', 'so.id'
        );
        $aggQuery->leftJoinSub(
            DB::table('receives')->select('sales_order_id', DB::raw('SUM(amount) as paid'))
                ->where('status', 1)->groupBy('sales_order_id'),
            'arcv', 'arcv.sales_order_id', '=', 'so.id'
        );
        $totals = $aggQuery->select(
            DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as t_sale'),
            DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as t_cost'),
            DB::raw('COALESCE(SUM(aret.ret_amt), 0) as t_ret'),
            DB::raw('COALESCE(SUM(arcv.paid), 0) as t_paid')
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
            ->leftJoin('sales_returns as sr', function ($j) {
                $j->on('sr.order_id', '=', 'so.id')->where('sr.status', 'approved');
            })
            ->leftJoin('sales_return_items as sri', 'sri.return_id', '=', 'sr.id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->select(
                DB::raw("DATE_FORMAT(so.order_date, '%Y-%m') as ym"),
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale'),
                DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost'),
                DB::raw('COALESCE(SUM(sri.return_amount), 0) as ret')
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
