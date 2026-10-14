<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 销售分析看板（绩效分析 → 销售分析看板）。
 * 顶部六指标卡（value/yoy/mom/text 涨红跌绿）+ 趋势 + 构成环形图 + 排行条形图 + 增长退货率组合图。
 */
class DashboardController extends Controller
{
    /** 涨红跌绿（中国股市惯例），看板专用 */
    private const COLOR_UP = '#F56C6C';

    private const COLOR_DOWN = '#67C23A';

    private const COLOR_FLAT = '#909399';

    public function salesOverview(Request $request): JsonResponse
    {
        $now = now();
        $salesmanIds = ReportFilter::toArray($request->input('salesman_ids'));

        // 本期（本月）/ 上月（环比基期）/ 去年同月（同比基期）
        $periods = [
            'cur' => [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()],
            'mom' => [$now->copy()->subMonth()->startOfMonth()->toDateString(), $now->copy()->subMonth()->endOfMonth()->toDateString()],
            'yoy' => [$now->copy()->subYear()->startOfMonth()->toDateString(), $now->copy()->subYear()->endOfMonth()->toDateString()],
        ];
        $m = [];
        foreach ($periods as $key => [$start, $end]) {
            $sale = $this->saleAmount($start, $end, $salesmanIds);
            $cost = $this->costAmount($start, $end, $salesmanIds);
            $ret = $this->returnAmount($start, $end);
            $pay = $this->paymentAmount($start, $end, $salesmanIds);
            $orders = $this->orderCount($start, $end, $salesmanIds);
            $m[$key] = [
                'sale' => ReportFilter::num($sale),
                'payment' => ReportFilter::num($pay),
                'profit' => ReportFilter::num($sale - $cost - $ret),
                'return_rate' => $sale > 0 ? round($ret / $sale * 100, 2) : 0.0,
                'orders' => $orders,
                'avg' => $orders > 0 ? ReportFilter::num($sale / $orders) : 0.0,
            ];
        }

        // 趋势：range=12m（默认）| 30d；空数据返回空数组不报错
        $range = $request->input('range', '12m');
        $trend = $this->monthlyTrend($salesmanIds, is_string($range) ? $range : '12m');

        // 构成（按品牌，本月）
        [$monthStart, $monthEnd] = $periods['cur'];
        $composition = $this->composition($monthStart, $monthEnd, 'brand');

        // 排行：商品 TOP10、客户/业务员 TOP10
        $topProducts = $this->rank('product', $monthStart, $monthEnd, 10);
        $topCustomers = $this->rank('customer', $monthStart, $monthEnd, 10);
        $topEmployees = $this->rank('employee', $monthStart, $monthEnd, 10);

        // 增长退货率（近 12 月）
        $growthReturn = $this->growthReturn($salesmanIds);

        return $this->success([
            'cards' => [
                $this->card('sale', '本月销售额', '¥', $m['cur']['sale'], $m['cur']['sale'], $m['mom']['sale'], $m['yoy']['sale']),
                $this->card('payment', '本月回款', '¥', $m['cur']['payment'], $m['cur']['payment'], $m['mom']['payment'], $m['yoy']['payment']),
                $this->card('profit', '本月毛利', '¥', $m['cur']['profit'], $m['cur']['profit'], $m['mom']['profit'], $m['yoy']['profit']),
                $this->card('return_rate', '本月退货率', '%', $m['cur']['return_rate'], $m['cur']['return_rate'], $m['mom']['return_rate'], $m['yoy']['return_rate']),
                $this->card('orders', '本月订单数', '', $m['cur']['orders'], (float) $m['cur']['orders'], (float) $m['mom']['orders'], (float) $m['yoy']['orders']),
                $this->card('avg', '客单价', '¥', $m['cur']['avg'], $m['cur']['avg'], $m['mom']['avg'], $m['yoy']['avg']),
            ],
            'range' => in_array($range, ['12m', '30d'], true) ? $range : '12m',
            'trend_12m' => $trend,
            'composition' => $composition,
            'rank' => [
                'product' => $topProducts,
                'customer' => $topCustomers,
                'employee' => $topEmployees,
            ],
            'top_products' => $topProducts,
            'growth_return' => $growthReturn,
        ]);
    }

    /**
     * 指标卡：value + yoy（同比）+ mom（环比）+ text 颜色提示（涨红跌绿）。
     * 同比/环比分母为 0 或基期缺失时返回 null，不返回 0/INF。
     */
    private function card(string $key, string $label, string $unit, float $value, float $cur, ?float $momBase, ?float $yoyBase): array
    {
        $mom = $this->pct($cur, $momBase);
        $yoy = $this->pct($cur, $yoyBase);
        $delta = $mom ?? $yoy;
        $text = ($delta === null || $delta == 0.0)
            ? self::COLOR_FLAT
            : ($delta > 0 ? self::COLOR_UP : self::COLOR_DOWN);

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'yoy' => $yoy,
            'mom' => $mom,
            'text' => $text,
            'color' => $text,
            'unit' => $unit,
        ];
    }

    /** 同比/环比：基期为 null 或 0 时返回 null（避免 0 分母 / INF） */
    private function pct(?float $cur, ?float $base): ?float
    {
        if ($base === null || $base == 0.0) {
            return null;
        }

        return round(($cur - $base) / $base * 100, 2);
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

    /** 趋势：range=12m 按月桶 / 30d 按日桶；查询无任何数据时返回空数组 */
    private function monthlyTrend(array $sm, string $range): array
    {
        if ($range === '30d') {
            $start = now()->subDays(29)->toDateString();
            $end = now()->toDateString();
            $fmt = '%Y-%m-%d';
        } else {
            $start = now()->subMonths(11)->startOfMonth()->toDateString();
            $end = now()->endOfMonth()->toDateString();
            $fmt = '%Y-%m';
            $range = '12m';
        }

        $sales = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm))
            ->select(
                DB::raw("DATE_FORMAT(so.order_date, '{$fmt}') as ym"),
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale')
            )
            ->groupBy('ym')->get()->keyBy('ym');
        $payments = DB::table('receives as r')
            ->join('sales_orders as so', 'so.id', '=', 'r.sales_order_id')
            ->where('r.status', 1)
            ->whereBetween('r.receive_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm))
            ->select(DB::raw("DATE_FORMAT(r.receive_date, '{$fmt}') as ym"), DB::raw('SUM(r.amount) as paid'))
            ->groupBy('ym')->get()->keyBy('ym');

        // 空数据返回空数组，不报错
        if ($sales->isEmpty() && $payments->isEmpty()) {
            return [];
        }

        $out = [];
        $steps = $range === '30d' ? 29 : 11;
        for ($i = $steps; $i >= 0; $i--) {
            $key = $range === '30d'
                ? now()->copy()->subDays($i)->toDateString()
                : now()->copy()->subMonths($i)->format('Y-m');
            $out[] = [
                'month' => $key,
                'sale' => ReportFilter::num((float) ($sales[$key]->sale ?? 0)),
                'payment' => ReportFilter::num((float) ($payments[$key]->paid ?? 0)),
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
