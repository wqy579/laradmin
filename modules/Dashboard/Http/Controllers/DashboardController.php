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

    /**
     * 计入销售统计的订单状态。
     *
     * ⚠️ 销售单实际用的是中文状态（pending / 配货中 / 待调度 / 待配送 / 配送中 /
     * 已收款 / 待收款），只有「借货转销售」生成的单是 approved。早期这里只写
     * approved，导致大屏的今日销售额、订单数永远是 0——正常销售单一条都没统计到。
     * 这里与 SalesOrderController 的口径（已收款 / 待收款）对齐，并兼容转销售单。
     */
    private const SALES_STATUS = ['已收款', '待收款', 'approved', 'completed'];

    /** 核心指标看板 */
    public function metrics(Request $request)
    {
        $today = now()->toDateString();
        $approved = self::SALES_STATUS;

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
            ->whereIn('so.status', self::SALES_STATUS)
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
            ->whereIn('so.status', self::SALES_STATUS)
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
            ->whereIn('status', self::SALES_STATUS)
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
            ->whereIn('so.status', self::SALES_STATUS)
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
            ->whereIn('so.status', self::SALES_STATUS)
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

    /**
     * 核心指标（中间栏 4 张卡）：总客户数 / 总订单数 / 总销售额 / 总利润 + 环比。
     * 环比 = 今日较昨日的变化率（%）；客户数用今日新增数表示增长。
     */
    public function coreMetrics(Request $request)
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $totalCustomerCount = (int) DB::table('customers')->where('is_active', 1)->count();

        $agg = DB::table('sales_orders')
            ->whereIn('status', self::SALES_STATUS)
            ->select(DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total_amount) as amount'))
            ->first();
        $totalOrderCount = (int) ($agg->cnt ?? 0);
        $totalSalesAmount = (float) ($agg->amount ?? 0);

        // 利润 = 销售额 - 销售成本（数量 × 商品成本价），成本口径同 ProfitController
        $totalCost = $this->costBetween(null, null);
        $todayCost = $this->costBetween($today, $today);
        $yesterdayCost = $this->costBetween($yesterday, $yesterday);

        $dayAgg = function (string $date) {
            return DB::table('sales_orders')
                ->whereIn('status', self::SALES_STATUS)
                ->whereDate('order_date', $date)
                ->select(DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total_amount) as amount'))
                ->first();
        };
        $t = $dayAgg($today);
        $y = $dayAgg($yesterday);

        $todayOrders = (int) ($t->cnt ?? 0);
        $todaySales = (float) ($t->amount ?? 0);
        $yOrders = (int) ($y->cnt ?? 0);
        $ySales = (float) ($y->amount ?? 0);

        return $this->success([
            'total_customer_count' => $totalCustomerCount,
            'total_order_count' => $totalOrderCount,
            'total_sales_amount' => round($totalSalesAmount, 2),
            'total_profit' => round($totalSalesAmount - $totalCost, 2),
            'order_ratio' => $this->growthRate($todayOrders, $yOrders),
            'sales_ratio' => $this->growthRate($todaySales, $ySales),
            'profit_ratio' => $this->growthRate($todaySales - $todayCost, $ySales - $yesterdayCost),
            'customer_growth' => (int) DB::table('customers')->whereDate('created_at', $today)->count(),
            // 客单价 = 今日销售额 / 今日订单数
            'avg_order_amount' => $todayOrders > 0 ? round($todaySales / $todayOrders, 2) : 0,
        ]);
    }

    /** 销售成本：可限定日期区间（传 null 表示不限），用于算利润 */
    private function costBetween(?string $from, ?string $to): float
    {
        $q = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->whereIn('so.status', self::SALES_STATUS);

        if ($from !== null) {
            $q->whereDate('so.order_date', '>=', $from);
        }
        if ($to !== null) {
            $q->whereDate('so.order_date', '<=', $to);
        }

        return (float) $q->sum(DB::raw('soi.quantity * COALESCE(p.cost_price, 0)'));
    }

    /** 增长率（%）：基期为 0 时，本期 >0 记 100（全量增长），否则 0 */
    private function growthRate(float $current, float $base): float
    {
        if ($base <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round(($current - $base) / $base * 100, 1);
    }

    /** 商品销量排行 TOP10（按销售数量，横向柱状图用） */
    public function productRank(Request $request)
    {
        $limit = min(20, max(5, $request->integer('limit', 10)));

        $rows = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->whereIn('so.status', self::SALES_STATUS)
            ->select('p.id as product_id', 'p.name as product_name', DB::raw('SUM(soi.quantity) as qty'), DB::raw('SUM(soi.amount) as amount'))
            ->groupBy('p.id', 'p.name')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();

        return $this->success(['list' => $rows->map(fn ($r) => [
            'product_id' => $r->product_id,
            'product_name' => $r->product_name,
            'qty' => (int) $r->qty,
            'amount' => round((float) $r->amount, 2),
        ])]);
    }

    /**
     * 按地区（省份）汇总销售额，供大屏地图热力使用。
     *
     * 客户表没有独立省份字段，只有 address，这里用省级行政区名匹配地址文本，
     * 匹配不到归入「未知」。是尽力而为的解析——将来客户表若加 province 字段，
     * 改成按该字段 group by 即可。返回名与前端 china.json 的省份名保持一致。
     */
    public function regionSales(Request $request)
    {
        $provinces = ['北京', '天津', '上海', '重庆', '河北', '山西', '辽宁', '吉林', '黑龙江', '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '海南', '四川', '贵州', '云南', '陕西', '甘肃', '青海', '内蒙古', '广西', '西藏', '宁夏', '新疆'];

        $rows = DB::table('sales_orders as so')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->whereIn('so.status', self::SALES_STATUS)
            ->get(['c.address', 'so.total_amount']);

        $bucket = [];
        foreach ($rows as $r) {
            $addr = (string) ($r->address ?? '');
            $hit = null;
            foreach ($provinces as $pv) {
                if ($addr !== '' && str_contains($addr, $pv)) {
                    $hit = $pv;
                    break;
                }
            }
            $key = $hit ?? '未知';
            $bucket[$key] = ($bucket[$key] ?? 0) + (float) $r->total_amount;
        }

        $list = [];
        foreach ($bucket as $name => $amount) {
            $list[] = ['name' => $name, 'value' => round($amount, 2)];
        }
        usort($list, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $this->success(['list' => $list, 'max' => $list[0]['value'] ?? 0]);
    }

    /** 配送状态统计：待配送 / 配送中 / 已完成（环形图展示占比） */
    public function deliveryStatus(Request $request)
    {
        // 已完成包含借货转销售生成的 approved，避免这部分单子在哪都不显示
        $groups = [
            '待配送' => ['待配送'],
            '配送中' => ['配送中'],
            '已完成' => ['已收款', 'approved', 'completed'],
        ];

        $list = [];
        foreach ($groups as $label => $statuses) {
            $list[] = [
                'name' => $label,
                'value' => (int) DB::table('sales_orders')->whereIn('status', $statuses)->count(),
            ];
        }

        return $this->success(['list' => $list, 'total' => array_sum(array_column($list, 'value'))]);
    }

    /** 财务概览：今日收款 / 今日付款 / 应收账款 / 应付账款 */
    public function financeOverview(Request $request)
    {
        $today = now()->toDateString();

        // 收款/付款口径同 ProfitController：status=1 表示已生效
        $todayReceive = (float) DB::table('receives')->where('status', 1)->whereDate('receive_date', $today)->sum('amount');
        $todayPay = (float) DB::table('pays')->where('status', 1)->whereDate('pay_date', $today)->sum('amount');

        // 应收账款 = 已发货未收款订单的未收部分
        $receivable = (float) DB::table('sales_orders')
            ->whereIn('status', ['配送中', '待收款'])
            ->whereRaw('paid_amount < total_amount')
            ->sum(DB::raw('total_amount - paid_amount'));

        // 应付账款 = 未付款的付款单
        $payable = (float) DB::table('pays')->where('status', 0)->sum('amount');

        return $this->success([
            'today_receive' => round($todayReceive, 2),
            'today_pay' => round($todayPay, 2),
            'receivable' => round($receivable, 2),
            'payable' => round($payable, 2),
        ]);
    }
}
