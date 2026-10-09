<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 智慧大屏聚合接口（Dashboard）
 *
 * 只读聚合，复用 Business/Order/Stock 数据：核心指标、实时订单、销售分类占比、
 * 销售趋势、客户/业务员排行、库存概览与预警。前端每 30s 轮询刷新。
 */
class DashboardController extends Controller
{
    use ResponseTrait;

    /** 核心指标看板 */
    public function metrics(Request $request)
    {
        $today = now()->toDateString();
        $approved = ['approved'];

        $todaySales = (float) DB::table('sales_orders')
            ->whereIn('status', $approved)
            ->whereDate('order_date', $today)
            ->sum('total_amount');

        $todayOrderCount = (int) DB::table('sales_orders')
            ->whereIn('status', $approved)
            ->whereDate('order_date', $today)
            ->count();

        $todayCustomerCount = (int) DB::table('sales_orders')
            ->whereIn('status', $approved)
            ->whereDate('order_date', $today)
            ->distinct('customer_id')
            ->count('customer_id');

        $stock = DB::table('stocks')
            ->select(DB::raw('COUNT(DISTINCT product_id) as kinds'), DB::raw('SUM(quantity) as qty'), DB::raw('SUM(quantity * cost_price) as amount'))
            ->first();

        // 收款方式占比（今日）
        $payRows = DB::table('cash_flows')
            ->where('flow_type', 'receive')
            ->whereDate('flow_date', $today)
            ->select('payment_method', DB::raw('SUM(amount) as amount'))
            ->groupBy('payment_method')
            ->pluck('amount', 'payment_method');

        $map = ['现金' => 'cash', '微信' => 'wechat', '支付宝' => 'alipay', '银行卡' => 'bank', '挂账' => 'credit'];
        $salesOverview = [];
        foreach (['现金', '微信', '支付宝', '挂账'] as $label) {
            $salesOverview[] = [
                'label' => $label,
                'key' => $map[$label],
                'amount' => round((float) ($payRows[$label] ?? 0), 2),
            ];
        }

        return $this->success([
            'today_sales' => round($todaySales, 2),
            'today_order_count' => $todayOrderCount,
            'today_customer_count' => $todayCustomerCount,
            'stock_total_amount' => round((float) ($stock->amount ?? 0), 2),
            'stock_kinds' => (int) ($stock->kinds ?? 0),
            'stock_qty' => (int) ($stock->qty ?? 0),
            'sales_overview' => $salesOverview,
        ]);
    }

    /** 实时订单滚动 */
    public function realtimeOrders(Request $request)
    {
        $limit = min(50, max(5, $request->integer('limit', 20)));

        $list = DB::table('sales_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->where('so.status', 'approved')
            ->orderByDesc('so.id')
            ->limit($limit)
            ->get([
                'so.id', 'so.order_no', 'c.name as customer_name',
                'so.total_qty', 'so.total_amount', 'so.status', 'so.order_date', 'so.created_at',
            ])
            ->map(function ($r) {
                return [
                    'order_no' => $r->order_no,
                    'customer_name' => $r->customer_name,
                    'total_qty' => (int) $r->total_qty,
                    'total_amount' => round((float) $r->total_amount, 2),
                    'status' => $r->status,
                    'status_label' => '已审核',
                    'time' => $r->created_at ? date('H:i:s', strtotime($r->created_at)) : '',
                ];
            });

        return $this->success(['list' => $list]);
    }

    /** 商品大类（品牌）销售占比 */
    public function categoryProportion(Request $request)
    {
        $rows = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('so.status', 'approved')
            ->select(DB::raw('COALESCE(b.name, "其他") as category'), DB::raw('SUM(soi.amount) as amount'))
            ->groupBy(DB::raw('COALESCE(b.name, "其他")'))
            ->orderByDesc('amount')
            ->get();

        $total = (float) $rows->sum('amount');
        $list = $rows->map(function ($r) use ($total) {
            $amount = (float) $r->amount;

            return [
                'name' => $r->category,
                'value' => round($amount, 2),
                'percent' => $total > 0 ? round($amount / $total * 100, 1) : 0,
            ];
        });

        return $this->success(['list' => $list, 'total' => round($total, 2)]);
    }

    /** 近 7 天销售趋势 */
    public function salesTrend(Request $request)
    {
        $days = 7;
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = DB::table('sales_orders')
            ->where('status', 'approved')
            ->where('order_date', '>=', $start->toDateString())
            ->select(DB::raw('DATE(order_date) as d'), DB::raw('SUM(total_amount) as amount'), DB::raw('COUNT(*) as orders'))
            ->groupBy('d')
            ->pluck('amount', 'd');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                'label' => substr($date, 5),
                'amount' => round((float) ($rows[$date] ?? 0), 2),
            ];
        }

        return $this->success(['list' => $series]);
    }

    /** 今日客户销售 TOP10 */
    public function customerRank(Request $request)
    {
        $today = now()->toDateString();

        $rows = DB::table('sales_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->where('so.status', 'approved')
            ->whereDate('so.order_date', $today)
            ->select('so.customer_id', 'c.name as customer_name', DB::raw('SUM(so.total_amount) as amount'))
            ->groupBy('so.customer_id', 'c.name')
            ->orderByDesc('amount')
            ->limit(10)
            ->get();

        return $this->success(['list' => $rows->map(function ($r, $i) {
            return [
                'rank' => $i + 1,
                'customer_id' => $r->customer_id,
                'customer_name' => $r->customer_name,
                'amount' => round((float) $r->amount, 2),
            ];
        })]);
    }

    /** 库存概览 */
    public function inventoryOverview(Request $request)
    {
        $stock = DB::table('stocks')
            ->select(DB::raw('COUNT(DISTINCT product_id) as kinds'), DB::raw('SUM(quantity) as qty'), DB::raw('SUM(quantity * cost_price) as amount'))
            ->first();

        $stockAmount = (float) ($stock->amount ?? 0);
        $monthSales = (float) DB::table('sales_orders')
            ->where('status', 'approved')
            ->where('order_date', '>=', now()->subDays(30)->toDateString())
            ->sum('total_amount');

        $turnoverRate = $stockAmount > 0 ? round($monthSales / $stockAmount, 2) : 0;
        $turnoverDays = $turnoverRate > 0 ? round(30 / $turnoverRate, 1) : 0;

        return $this->success([
            'kinds' => (int) ($stock->kinds ?? 0),
            'qty' => (int) ($stock->qty ?? 0),
            'amount' => round($stockAmount, 2),
            'turnover_rate' => $turnoverRate,
            'turnover_days' => $turnoverDays,
        ]);
    }

    /** 库存预警：低库存 / 超库存 / 临期 */
    public function inventoryWarning(Request $request)
    {
        $low = DB::table('stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.quantity', '>', 0)
            ->whereColumn('s.quantity', '<=', DB::raw('COALESCE(p.safety_stock, 0)'))
            ->where('p.safety_stock', '>', 0)
            ->orderBy('s.quantity')
            ->limit(5)
            ->get(['p.name as product_name', 's.quantity', 'p.safety_stock'])
            ->map(fn ($r) => ['product_name' => $r->product_name, 'quantity' => (int) $r->quantity, 'safety_stock' => (int) $r->safety_stock]);

        $over = DB::table('stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('p.max_stock', '>', 0)
            ->whereColumn('s.quantity', '>=', 'p.max_stock')
            ->orderByDesc('s.quantity')
            ->limit(5)
            ->get(['p.name as product_name', 's.quantity', 'p.max_stock'])
            ->map(fn ($r) => ['product_name' => $r->product_name, 'quantity' => (int) $r->quantity, 'max_stock' => (int) $r->max_stock]);

        $nearRows = DB::table('products as p')
            ->join('stocks as s', 's.product_id', '=', 'p.id')
            ->whereNotNull('p.expiry_date')
            ->where('p.expiry_date', '<=', now()->addDays(30)->toDateString())
            ->where('p.expiry_date', '>=', now()->toDateString())
            ->where('s.quantity', '>', 0)
            ->orderBy('p.expiry_date')
            ->limit(5)
            ->get(['p.name as product_name', 'p.expiry_date']);

        $today = now()->startOfDay();
        $near = $nearRows->map(function ($r) use ($today) {
            $days = $today->diffInDays($r->expiry_date, false);

            return ['product_name' => $r->product_name, 'expiry_date' => $r->expiry_date, 'days' => (int) $days];
        });

        return $this->success(['low' => $low, 'over' => $over, 'near' => $near]);
    }

    /** 今日业务员销售排行（前 5） */
    public function salesmanRank(Request $request)
    {
        $today = now()->toDateString();

        $rows = DB::table('sales_orders as so')
            ->leftJoin('auth_user as u', 'u.id', '=', 'so.salesman_id')
            ->where('so.status', 'approved')
            ->whereDate('so.order_date', $today)
            ->select('so.salesman_id', 'u.real_name as salesman_name', DB::raw('SUM(so.total_amount) as amount'))
            ->groupBy('so.salesman_id', 'u.real_name')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        return $this->success(['list' => $rows->map(function ($r, $i) {
            return [
                'rank' => $i + 1,
                'salesman_id' => $r->salesman_id,
                'salesman_name' => $r->salesman_name ?? '未知',
                'amount' => round((float) $r->amount, 2),
                'rate' => 0,
            ];
        })]);
    }
}
