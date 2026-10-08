<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块五：综合报表（报表管理 → 综合报表）
 *
 * 顶层是 4 张经营概览卡片（今日销售额 / 今日订单数 / 今日客户数 / 库存总金额），
 * 下面是 6 个分析维度。卡片与 tab 用同一套统计日期，互不脱节。
 *
 * 卡片的「较昨日」是同比昨天同一时间段，不是环比上月——日粒度看板用日环比更符合
 * 看数习惯，月环比放在月度利润页。
 */
class CombinedReportController extends Controller
{
    private const DIMENSIONS = [
        'sales' => '销售汇总',
        'stock' => '库存汇总',
        'purchase' => '采购汇总',
        'finance' => '财务汇总',
        'customer' => '客户分析',
        'product' => '商品分析',
    ];

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = (string) ($params['dimension'] ?: 'sales');
        if (! array_key_exists($dimension, self::DIMENSIONS)) {
            $dimension = 'sales';
        }

        $paginator = $this->aggregate($params, $dimension)
            ->paginate($params['page_size'], ['*'], 'page', $params['page']);

        $data = ReportFilter::page($paginator);
        $data['dimension'] = $dimension;
        $data['columns'] = $this->columns($dimension, $params);
        $data['summary'] = $this->summary($params, $dimension);
        $data['overview'] = $this->overview($params);

        return $this->success($data);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $dimension = (string) ($params['dimension'] ?: 'sales');
        $columns = $this->columns($dimension, $params);
        $rows = $this->aggregate($params, $dimension)->limit(50000)->get();

        $body = [];
        foreach ($rows as $row) {
            $body[] = array_map(fn ($c) => $row->{$c['prop']} ?? '', $columns);
        }

        return ReportFilter::csvResponse(
            ReportFilter::csv('综合报表 - '.(self::DIMENSIONS[$dimension] ?? ''), array_column($columns, 'label'), $body),
            'combined_report_'.$dimension.'.csv'
        );
    }

    /** 顶层 4 张概览卡片 */
    private function overview(array $params): array
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $salesOf = function (string $date) use ($params) {
            $query = DB::table('sales_orders as so')
                ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
                ->whereDate('so.order_date', $date)
                ->whereNull('so.original_order_id');

            if ($params['warehouse_ids']) {
                $query->whereIn('so.warehouse_id', $params['warehouse_ids']);
            }
            if ($params['salesman_ids']) {
                $query->whereIn('so.salesman_id', $params['salesman_ids']);
            }

            return [
                'amount' => ReportFilter::num($query->sum(DB::raw('CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END'))),
                'orders' => (int) $query->distinct()->count('so.id'),
                'customers' => (int) $query->distinct()->count('so.customer_id'),
            ];
        };

        $todayData = $salesOf($today);
        $yesterdayData = $salesOf($yesterday);

        $stockQuery = DB::table('stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('p.is_active', 1);
        if ($params['warehouse_ids']) {
            $stockQuery->whereIn('s.warehouse_id', $params['warehouse_ids']);
        }
        $stockAmount = ReportFilter::num($stockQuery->sum(DB::raw('s.quantity * COALESCE(NULLIF(s.cost_price, 0), p.cost_price, 0)')));
        $skuCount = (int) (clone $stockQuery)->where('s.quantity', '!=', 0)->distinct()->count('s.product_id');

        return [
            [
                'key' => 'today_amount',
                'title' => '今日销售额',
                'value' => $todayData['amount'],
                'format' => 'money',
                'compare' => $this->compareText($todayData['amount'], $yesterdayData['amount'], 'percent'),
                'trend' => $todayData['amount'] >= $yesterdayData['amount'] ? 'up' : 'down',
            ],
            [
                'key' => 'today_orders',
                'title' => '今日订单数',
                'value' => $todayData['orders'],
                'format' => 'int',
                'compare' => $this->compareText($todayData['orders'], $yesterdayData['orders'], 'diff'),
                'trend' => $todayData['orders'] >= $yesterdayData['orders'] ? 'up' : 'down',
            ],
            [
                'key' => 'today_customers',
                'title' => '今日客户数',
                'value' => $todayData['customers'],
                'format' => 'int',
                'compare' => $this->compareText($todayData['customers'], $yesterdayData['customers'], 'diff'),
                'trend' => $todayData['customers'] >= $yesterdayData['customers'] ? 'up' : 'down',
            ],
            [
                'key' => 'stock_amount',
                'title' => '库存总金额',
                'value' => $stockAmount,
                'format' => 'money',
                'compare' => '共 '.$skuCount.' 个 SKU',
                'trend' => 'neutral',
            ],
        ];
    }

    /** 「较昨日 +12.5%」/「较昨日 +8」这类文案 */
    private function compareText(float|int $today, float|int $yesterday, string $mode): string
    {
        $diff = $today - $yesterday;
        $sign = $diff >= 0 ? '+' : '';

        if ($mode === 'percent') {
            if ($yesterday == 0) {
                return $today > 0 ? '较昨日 新增' : '较昨日 持平';
            }

            return '较昨日 '.$sign.number_format($diff / $yesterday * 100, 1).'%';
        }

        return '较昨日 '.$sign.(is_float($diff) ? number_format($diff, 0) : $diff);
    }

    private function aggregate(array $params, string $dimension)
    {
        [$start, $end] = $this->range($params);

        switch ($dimension) {
            case 'sales':
                return DB::table('sales_orders as so')
                    ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
                    ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
                    ->whereBetween('so.order_date', [$start, $end])
                    ->whereNull('so.original_order_id')
                    ->when($params['warehouse_ids'], fn ($q) => $q->whereIn('so.warehouse_id', $params['warehouse_ids']))
                    ->when($params['salesman_ids'], fn ($q) => $q->whereIn('so.salesman_id', $params['salesman_ids']))
                    ->select([
                        'so.order_date as stat_date',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
                        DB::raw('COUNT(DISTINCT soi.product_id) as product_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'),
                        DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                        DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) - SUM(soi.quantity * COALESCE(p.cost_price, 0)) as profit'),
                    ])
                    ->groupBy('so.order_date')
                    ->orderByDesc('stat_date');

            case 'stock':
                return DB::table('stocks as s')
                    ->join('products as p', 'p.id', '=', 's.product_id')
                    ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
                    ->where('p.is_active', 1)
                    ->when($params['warehouse_ids'], fn ($q) => $q->whereIn('s.warehouse_id', $params['warehouse_ids']))
                    ->select([
                        'w.code as warehouse_code',
                        'w.name as warehouse_name',
                        DB::raw('COUNT(DISTINCT s.product_id) as product_count'),
                        DB::raw('SUM(s.quantity) as qty'),
                        DB::raw('SUM(s.quantity * COALESCE(NULLIF(s.cost_price, 0), p.cost_price, 0)) as cost_amount'),
                        DB::raw('SUM(s.quantity * COALESCE(p.price_small, p.price_large, 0)) as retail_amount'),
                    ])
                    ->groupBy('w.id', 'w.code', 'w.name')
                    ->orderByDesc('cost_amount');

            case 'purchase':
                return DB::table('stock_ins as si')
                    ->join('stock_in_items as sii', 'sii.stock_in_id', '=', 'si.id')
                    ->leftJoin('warehouses as w', 'w.id', '=', 'si.warehouse_id')
                    ->leftJoin('suppliers as sup', 'sup.id', '=', 'si.supplier_id')
                    ->whereBetween('si.stock_date', [$start, $end])
                    ->when($params['warehouse_ids'], fn ($q) => $q->whereIn('si.warehouse_id', $params['warehouse_ids']))
                    ->select([
                        'si.stock_date as stat_date',
                        DB::raw('COUNT(DISTINCT si.id) as order_count'),
                        DB::raw('COUNT(DISTINCT si.supplier_id) as supplier_count'),
                        DB::raw('COUNT(DISTINCT sii.product_id) as product_count'),
                        DB::raw('SUM(sii.quantity) as qty'),
                        DB::raw('SUM(sii.amount) as amount'),
                    ])
                    ->groupBy('si.stock_date')
                    ->orderByDesc('stat_date');

            case 'finance':
                // 收款 / 付款 / 费用三张表日期粒度不同，按日期 UNION 后在外层聚合，
                // 避免用三个子查询互相 leftJoin 产生笛卡尔积
                $receive = DB::table('receives')->whereBetween('receive_date', [$start, $end])
                    ->where('status', 1)
                    ->selectRaw('receive_date as stat_date, SUM(amount) as receive_amount, 0 as pay_amount, 0 as expense_amount')
                    ->groupBy('receive_date');

                $pay = DB::table('pays')->whereBetween('pay_date', [$start, $end])
                    ->where('status', 1)
                    ->selectRaw('pay_date as stat_date, 0 as receive_amount, SUM(amount) as pay_amount, 0 as expense_amount')
                    ->groupBy('pay_date');

                $expense = DB::table('expenses')->whereBetween('expense_date', [$start, $end])
                    ->where('status', 1)
                    ->selectRaw('expense_date as stat_date, 0 as receive_amount, 0 as pay_amount, SUM(amount) as expense_amount')
                    ->groupBy('expense_date');

                return DB::table(DB::raw("({$receive->toSql()} UNION ALL {$pay->toSql()} UNION ALL {$expense->toSql()}) as f"))
                    ->mergeBindings($receive)
                    ->mergeBindings($pay)
                    ->mergeBindings($expense)
                    ->selectRaw('stat_date, SUM(receive_amount) as receive_amount, SUM(pay_amount) as pay_amount, SUM(expense_amount) as expense_amount')
                    ->groupBy('stat_date')
                    ->orderByDesc('stat_date');

            case 'customer':
                return DB::table('sales_orders as so')
                    ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
                    ->join('customers as c', 'c.id', '=', 'so.customer_id')
                    ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
                    ->whereBetween('so.order_date', [$start, $end])
                    ->whereNull('so.original_order_id')
                    ->when($params['warehouse_ids'], fn ($q) => $q->whereIn('so.warehouse_id', $params['warehouse_ids']))
                    ->when($params['salesman_ids'], fn ($q) => $q->whereIn('so.salesman_id', $params['salesman_ids']))
                    ->select([
                        'c.id as customer_id', 'c.code as customer_code', 'c.name as customer_name',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'),
                        DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                        DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) - SUM(soi.quantity * COALESCE(p.cost_price, 0)) as profit'),
                    ])
                    ->groupBy('c.id', 'c.code', 'c.name')
                    ->orderByDesc('amount');

            case 'product':
                return DB::table('sales_orders as so')
                    ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
                    ->join('products as p', 'p.id', '=', 'soi.product_id')
                    ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
                    ->whereBetween('so.order_date', [$start, $end])
                    ->whereNull('so.original_order_id')
                    ->when($params['warehouse_ids'], fn ($q) => $q->whereIn('so.warehouse_id', $params['warehouse_ids']))
                    ->when($params['salesman_ids'], fn ($q) => $q->whereIn('so.salesman_id', $params['salesman_ids']))
                    ->select([
                        'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                        'p.spec', 'p.price_unit_small as unit',
                        DB::raw('COALESCE(b.name, "未指定品牌") as brand_name'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'),
                        DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                        DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) - SUM(soi.quantity * COALESCE(p.cost_price, 0)) as profit'),
                    ])
                    ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'b.name')
                    ->orderByDesc('amount');
        }

        return DB::table('sales_orders')->selectRaw('1')->whereRaw('1 = 0');
    }

    /** 统计日期范围：默认当月 1 号到今天 */
    private function range(array $params): array
    {
        return [
            $params['start_date'] ?: now()->startOfMonth()->toDateString(),
            $params['end_date'] ?: now()->toDateString(),
        ];
    }

    private function summary(array $params, string $dimension): array
    {
        $rows = $this->aggregate($params, $dimension)->get();

        if ($dimension === 'finance') {
            $receive = (float) $rows->sum('receive_amount');
            $pay = (float) $rows->sum('pay_amount');
            $expense = (float) $rows->sum('expense_amount');

            return [
                'receive_amount' => ReportFilter::num($receive),
                'pay_amount' => ReportFilter::num($pay),
                'expense_amount' => ReportFilter::num($expense),
                'net_amount' => ReportFilter::num($receive - $pay - $expense),
            ];
        }

        $amount = (float) $rows->sum('amount');
        $cost = (float) $rows->sum('cost_amount');

        return [
            'qty' => ReportFilter::num($rows->sum('qty')),
            'amount' => ReportFilter::num($amount),
            'cost_amount' => ReportFilter::num($cost),
            'profit' => ReportFilter::num($amount - $cost),
            'profit_rate' => $amount > 0 ? round(($amount - $cost) / $amount * 100, 2) : 0,
            'order_count' => (int) $rows->sum('order_count'),
            'product_count' => (int) $rows->sum('product_count'),
        ];
    }

    private function columns(string $dimension, array $params): array
    {
        $withPrice = (bool) $params['with_price'];
        $money = fn (string $prop, string $label, int $width = 120, bool $bold = false) => [
            'prop' => $prop, 'label' => $label, 'width' => $width, 'align' => 'right', 'type' => 'money', 'bold' => $bold,
        ];

        switch ($dimension) {
            case 'sales':
                return array_merge([
                    ['prop' => 'stat_date', 'label' => '日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '订单数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'customer_count', 'label' => '客户数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'product_count', 'label' => '商品数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '销售数量', 'width' => 110, 'align' => 'right', 'type' => 'number'],
                    $money('amount', '销售金额', 130, true),
                ], $withPrice ? [
                    $money('cost_amount', '成本金额', 120),
                    ['prop' => 'profit', 'label' => '毛利', 'width' => 110, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
                    ['prop' => 'profit_rate', 'label' => '毛利率', 'width' => 90, 'align' => 'right', 'type' => 'percent'],
                ] : []);

            case 'stock':
                return [
                    ['prop' => 'warehouse_code', 'label' => '仓库编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'warehouse_name', 'label' => '仓库名称', 'width' => 140, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_count', 'label' => '商品数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '库存数量', 'width' => 110, 'align' => 'right', 'type' => 'number'],
                    $money('cost_amount', '成本金额', 130, true),
                    $money('retail_amount', '零售金额', 130),
                ];

            case 'purchase':
                return [
                    ['prop' => 'stat_date', 'label' => '日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '入库单数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'supplier_count', 'label' => '供应商数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'product_count', 'label' => '商品数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '采购数量', 'width' => 110, 'align' => 'right', 'type' => 'number'],
                    $money('amount', '采购金额', 130, true),
                ];

            case 'finance':
                return [
                    ['prop' => 'stat_date', 'label' => '日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    $money('receive_amount', '收款金额', 130),
                    $money('pay_amount', '付款金额', 130),
                    $money('expense_amount', '费用金额', 130),
                    $money('net_amount', '净额', 130, true),
                ];

            case 'customer':
                return array_merge([
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '订单数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '销售数量', 'width' => 110, 'align' => 'right', 'type' => 'number'],
                    $money('amount', '销售金额', 130, true),
                ], $withPrice ? [
                    ['prop' => 'profit', 'label' => '毛利', 'width' => 110, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
                    ['prop' => 'profit_rate', 'label' => '毛利率', 'width' => 90, 'align' => 'right', 'type' => 'percent'],
                ] : []);

            case 'product':
                return array_merge([
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 60, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'qty', 'label' => '销售数量', 'width' => 110, 'align' => 'right', 'type' => 'number'],
                    $money('amount', '销售金额', 130, true),
                ], $withPrice ? [
                    ['prop' => 'profit', 'label' => '毛利', 'width' => 110, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
                    ['prop' => 'profit_rate', 'label' => '毛利率', 'width' => 90, 'align' => 'right', 'type' => 'percent'],
                ] : []);
        }

        return [];
    }
}
