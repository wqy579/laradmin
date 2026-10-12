<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 销售分析看板（绩效分析 → 销售分析看板）。
 * 顶部六指标卡 + 12 个月双折线 + 构成环形图 + 排行条形图 + 增长退货率组合图。
 */
class DashboardController extends Controller
{
    public function salesOverview(Request $request): JsonResponse
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth()->toDateString();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth()->toDateString();
        $salesmanIds = ReportFilter::toArray($request->input('salesman_ids'));

        $monthSale = $this->saleAmount($monthStart, $monthEnd, $salesmanIds);
        $lastSale = $this->saleAmount($lastMonthStart, $lastMonthEnd, $salesmanIds);
        $monthPayment = $this->paymentAmount($monthStart, $monthEnd, $salesmanIds);
        $lastPayment = $this->paymentAmount($lastMonthStart, $lastMonthEnd, $salesmanIds);
        $monthCost = $this->costAmount($monthStart, $monthEnd, $salesmanIds);
        $lastReturn = $this->returnAmount($lastMonthStart, $lastMonthEnd);
        $monthReturn = $this->returnAmount($monthStart, $monthEnd);
        $monthOrders = $this->orderCount($monthStart, $monthEnd, $salesmanIds);

        $grossProfit = $monthSale - $monthCost - $monthReturn;
        $grossRate = $monthSale > 0 ? round($grossProfit / $monthSale * 100, 2) : 0;
        $avgPrice = $monthOrders > 0 ? round($monthSale / $monthOrders, 2) : 0;

        // 环比
        $saleMoM = $lastSale > 0 ? round(($monthSale - $lastSale) / $lastSale * 100, 2) : 0;
        $payMoM = $lastPayment > 0 ? round(($monthPayment - $lastPayment) / $lastPayment * 100, 2) : 0;

        // 12 个月销售回款
        $trend = $this->monthlyTrend($salesmanIds);

        // 构成（按品牌）
        $composition = $this->composition($monthStart, $monthEnd, 'brand');

        // 排行
        $topProducts = $this->rank('product', $monthStart, $monthEnd, 20);
        $topCustomers = $this->rank('customer', $monthStart, $monthEnd, 10);
        $topEmployees = $this->rank('employee', $monthStart, $monthEnd, 10);

        // 增长退货率（近 12 月）
        $growthReturn = $this->growthReturn($salesmanIds);

        return $this->success([
            'cards' => [
                ['key' => 'sale', 'label' => '本月销售额', 'value' => ReportFilter::num($monthSale), 'mom' => $saleMoM, 'unit' => '¥'],
                ['key' => 'payment', 'label' => '本月回款', 'value' => ReportFilter::num($monthPayment), 'mom' => $payMoM, 'unit' => '¥'],
                ['key' => 'profit', 'label' => '本月毛利', 'value' => ReportFilter::num($grossProfit), 'mom' => 0, 'unit' => '¥'],
                ['key' => 'rate', 'label' => '毛利率', 'value' => $grossRate, 'mom' => 0, 'unit' => '%'],
                ['key' => 'orders', 'label' => '订单数', 'value' => $monthOrders, 'mom' => 0, 'unit' => ''],
                ['key' => 'avg', 'label' => '客单价', 'value' => ReportFilter::num($avgPrice), 'mom' => 0, 'unit' => '¥'],
            ],
            'trend_12m' => $trend,
            'composition' => $composition,
            'rank' => [
                'product' => $topProducts,
                'customer' => $topCustomers,
                'employee' => $topEmployees,
            ],
            'growth_return' => $growthReturn,
        ]);
    }

    private function saleAmount(string $start, string $end, array $sm): float
    {
        $q = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm));

        return (float) $q->sum(DB::raw('CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END'));
    }

    private function costAmount(string $start, string $end, array $sm): float
    {
        $q = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm));

        return (float) $q->sum(DB::raw('soi.quantity * COALESCE(p.cost_price, 0)'));
    }

    private function paymentAmount(string $start, string $end, array $sm): float
    {
        $q = DB::table('receives as r')
            ->join('sales_orders as so', 'so.id', '=', 'r.sales_order_id')
            ->where('r.status', 1)
            ->whereBetween('r.receive_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm));

        return (float) $q->sum('r.amount');
    }

    private function returnAmount(string $start, string $end): float
    {
        return (float) DB::table('sales_returns as sr')
            ->where('sr.status', 'approved')
            ->whereBetween('sr.return_date', [$start, $end])
            ->sum('sr.total_amount');
    }

    private function orderCount(string $start, string $end, array $sm): int
    {
        return (int) DB::table('sales_orders as so')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm))
            ->count();
    }

    private function monthlyTrend(array $sm): array
    {
        $start = now()->subMonths(11)->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();
        $rows = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm))
            ->select(
                DB::raw("DATE_FORMAT(so.order_date, '%Y-%m') as ym"),
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale')
            )
            ->groupBy('ym')->get()->keyBy('ym');
        $payments = DB::table('receives as r')
            ->join('sales_orders as so', 'so.id', '=', 'r.sales_order_id')
            ->where('r.status', 1)
            ->whereBetween('r.receive_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm))
            ->select(DB::raw("DATE_FORMAT(r.receive_date, '%Y-%m') as ym"), DB::raw('SUM(r.amount) as paid'))
            ->groupBy('ym')->get()->keyBy('ym');

        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->copy()->subMonths($i)->format('Y-m');
            $out[] = [
                'month' => $m,
                'sale' => ReportFilter::num((float) ($rows[$m]->sale ?? 0)),
                'payment' => ReportFilter::num((float) ($payments[$m]->paid ?? 0)),
            ];
        }

        return $out;
    }

    private function composition(string $start, string $end, string $by): array
    {
        $rows = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->select(
                'b.name as name',
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount')
            )
            ->groupBy('b.name')->orderByDesc('amount')->limit(8)->get();

        return $rows->map(fn ($r) => ['name' => $r->name ?: '无品牌', 'value' => ReportFilter::num((float) $r->amount)])->values()->all();
    }

    private function rank(string $type, string $start, string $end, int $limit): array
    {
        $q = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end]);

        $selects = [DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'), DB::raw('SUM(soi.quantity) as qty')];
        $group = [];
        switch ($type) {
            case 'product':
                $q->leftJoin('products as p', 'p.id', '=', 'soi.product_id');
                $selects = array_merge($selects, ['p.id as id', 'p.name as name']);
                $group = ['p.id', 'p.name'];
                break;
            case 'customer':
                $q->leftJoin('customers as c', 'c.id', '=', 'so.customer_id');
                $selects = array_merge($selects, ['c.id as id', 'c.name as name']);
                $group = ['c.id', 'c.name'];
                break;
            case 'employee':
                $q->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id');
                $selects = array_merge($selects, ['so.salesman_id as id', DB::raw('COALESCE(sm.real_name, sm.username, "未指派") as name')]);
                $group = ['so.salesman_id', 'sm.username', 'sm.real_name'];
                break;
        }
        $rows = $q->select($selects)->groupBy($group)->orderByDesc('amount')->limit($limit)->get();

        return $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name ?: '-', 'amount' => ReportFilter::num((float) $r->amount), 'qty' => (int) $r->qty])->values()->all();
    }

    private function growthReturn(array $sm): array
    {
        $start = now()->subMonths(11)->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();
        $sales = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm))
            ->select(DB::raw("DATE_FORMAT(so.order_date, '%Y-%m') as ym"), DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale'))
            ->groupBy('ym')->get()->keyBy('ym');
        $returns = DB::table('sales_returns')->where('status', 'approved')
            ->whereBetween('return_date', [$start, $end])
            ->select(DB::raw("DATE_FORMAT(return_date, '%Y-%m') as ym"), DB::raw('SUM(total_amount) as ret'))
            ->groupBy('ym')->get()->keyBy('ym');

        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->copy()->subMonths($i)->format('Y-m');
            $sale = (float) ($sales[$m]->sale ?? 0);
            $ret = (float) ($returns[$m]->ret ?? 0);
            $out[] = [
                'month' => $m,
                'sale' => ReportFilter::num($sale),
                'return' => ReportFilter::num($ret),
                'return_rate' => $sale > 0 ? round($ret / $sale * 100, 2) : 0,
            ];
        }

        return $out;
    }
}
