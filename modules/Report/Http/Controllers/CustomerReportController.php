<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块：客户类报表（报表管理 → 客户报表）
 *
 * E2 六张客户维度报表共用一个筛选条件集，切换 tab 只换 dimension 参数：
 *   订单明细 / 发货未收款 / 日月增长退货率对比 / 客户销售汇总 / 客户订货汇总 / 退货汇总。
 *
 * 口径与销售报表一致：金额统一按
 *   SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * 单价 END)，
 * 默认剔除红冲单（original_order_id 非空），要核对红冲影响才勾「包含红冲」。
 * 纯读模块：不写业务表，只读销售/发货/退货事实核对数字。
 */
class CustomerReportController extends Controller
{
    /** 维度 key → 中文名（顺序即 tab 顺序，与设计方案一致） */
    private const DIMENSIONS = [
        'order_detail' => '客户订单明细',
        'shipped_uncollected' => '发货未收款',
        'return_rate_mom' => '日月增长退货率对比',
        'customer_sales' => '客户销售汇总',
        'customer_order' => '客户订货汇总',
        'return_summary' => '退货汇总',
    ];

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        // 日月增长退货率对比：按月聚合后行数有限（每月一行），整表取回后在 PHP 里算环比，再手动分页
        if ($dimension === 'return_rate_mom') {
            $rows = $this->monthlyReturnRateQuery($params);

            $page = $params['page'];
            $pageSize = $params['page_size'];
            $total = $rows->count();
            $items = $rows->forPage($page, $pageSize)->values();

            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                $pageSize,
                $page,
                ['path' => request()->url()]
            );

            $data = ReportFilter::page($paginator);
            $data['dimension'] = $dimension;
            $data['columns'] = $this->columns($dimension, $params);
            $data['summary'] = $this->summary($params, $dimension);

            return $this->success($data);
        }

        $query = $this->aggregate($params, $dimension);
        $paginator = $query->paginate($params['page_size'], ['*'], 'page', $params['page']);

        $data = ReportFilter::page($paginator);
        $data['dimension'] = $dimension;
        $data['columns'] = $this->columns($dimension, $params);
        $data['summary'] = $this->summary($params, $dimension);

        return $this->success($data);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        $columns = $this->columns($dimension, $params);

        // 日月增长退货率对比：monthlyReturnRateQuery 返回 Collection，直接整表导出
        $rows = $dimension === 'return_rate_mom'
            ? $this->monthlyReturnRateQuery($params)
            : $this->aggregate($params, $dimension)->limit(50000)->get();

        return ReportFilter::excelResponse(
            ReportFilter::excel('客户报表 - '.(self::DIMENSIONS[$dimension] ?? ''), $columns, $rows),
            'customer_report_'.$dimension.'.xls'
        );
    }

    private function dimension(array $params): string
    {
        $dimension = (string) ($params['dimension'] ?: 'order_detail');

        return array_key_exists($dimension, self::DIMENSIONS) ? $dimension : 'order_detail';
    }

    /** 带全部筛选条件的销售事实基础查询（别名 so/soi/p/c/w/b/mc/sc/cl/sm，与销售报表一致） */
    private function baseQuery(array $params)
    {
        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'so.warehouse_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->leftJoin('product_categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->leftJoin('customer_levels as cl', 'cl.id', '=', 'c.level_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id');

        ReportFilter::applyDateRange($query, $params);
        ReportFilter::applyDimensions($query, $params);
        ReportFilter::applyOrderFilters($query, $params);

        return $query;
    }

    /** 销售退货事实查询（按客户归集，别名 sr） */
    private function returnQuery(array $params)
    {
        $query = DB::table('sales_returns as sr')
            ->leftJoin('customers as c', 'c.id', '=', 'sr.customer_id')
            ->whereNotIn('sr.status', ['cancelled', 'draft']);

        $start = $params['start_date'] ?? null;
        $end = $params['end_date'] ?? null;
        if ($start || $end) {
            $query->whereBetween('sr.return_date', [$start ?: '1970-01-01', $end ?: '2999-12-31']);
        }
        if ($params['customer_ids']) {
            $query->whereIn('sr.customer_id', $params['customer_ids']);
        }
        if ($params['customer_category']) {
            $query->whereIn('c.category', $params['customer_category']);
        }
        if ($params['customer_level_ids']) {
            $query->whereIn('c.level_id', $params['customer_level_ids']);
        }
        if ($params['warehouse_ids']) {
            $query->whereIn('sr.warehouse_id', $params['warehouse_ids']);
        }
        if (! empty($params['customer_name'])) {
            $query->where('c.name', 'like', '%'.$params['customer_name'].'%');
        }

        return $query;
    }

    /** 单维度聚合后的数据查询 */
    private function aggregate(array $params, string $dimension)
    {
        $amountExpr = 'SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END)';

        switch ($dimension) {
            case 'order_detail':
                return $this->baseQuery($params)
                    ->select([
                        'so.id as order_id',
                        'so.order_no',
                        'so.order_date',
                        'so.dispatch_date',
                        'so.payment_status',
                        'c.code as customer_code',
                        'c.name as customer_name',
                        'p.code as product_code',
                        'p.name as product_name',
                        'p.spec',
                        'p.price_unit_small as unit',
                        'soi.quantity as qty',
                        DB::raw('CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END as price'),
                        DB::raw($amountExpr.' as amount'),
                        'w.name as warehouse_name',
                        'sm.real_name as salesman_name',
                    ])
                    ->orderBy('so.order_date', 'desc')
                    ->orderBy('so.id', 'desc');

            case 'shipped_uncollected':
                $query = DB::table('sales_orders as so')
                    ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
                    ->leftJoin('warehouses as w', 'w.id', '=', 'so.warehouse_id')
                    ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
                    ->whereExists(function ($sub) {
                        $sub->from('deliveries')
                            ->whereColumn('deliveries.order_id', 'so.id')
                            ->where('deliveries.status', '!=', 3);
                    })
                    ->whereRaw('COALESCE(so.total_amount,0) - COALESCE(so.paid_amount,0) > 0');

                ReportFilter::applyDateRange($query, $params);
                ReportFilter::applyDimensions($query, $params);
                ReportFilter::applyOrderFilters($query, $params);

                $asOf = $params['as_of_date'] ?: now()->toDateString();
                $query->select([
                    'so.id as order_id',
                    'so.order_no',
                    'so.order_date',
                    'so.dispatch_date',
                    'so.delivery_date',
                    'c.code as customer_code',
                    'c.name as customer_name',
                    DB::raw('so.total_amount as order_amount'),
                    DB::raw('so.paid_amount as paid_amount'),
                    DB::raw('ROUND(COALESCE(so.total_amount,0) - COALESCE(so.paid_amount,0), 2) as unpaid_amount'),
                    DB::raw('julianday(\''.$asOf.'\') - julianday(COALESCE(so.dispatch_date, so.order_date)) as age_days'),
                    'so.payment_status',
                ]);

                return $query->orderByDesc('unpaid_amount');

            case 'return_rate_mom':
                return $this->monthlyReturnRateQuery($params);

            case 'customer_sales':
                return $this->baseQuery($params)
                    ->select([
                        'c.id as customer_id',
                        'c.code as customer_code',
                        'c.name as customer_name',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('COUNT(DISTINCT soi.product_id) as product_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw($amountExpr.' as amount'),
                        DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                        DB::raw($amountExpr.' - SUM(soi.quantity * COALESCE(p.cost_price, 0)) as profit'),
                        DB::raw('MAX(so.order_date) as last_order_date'),
                    ])
                    ->groupBy('c.id', 'c.code', 'c.name')
                    ->orderByDesc('amount');

            case 'customer_order':
                $query = DB::table('sales_orders as so')
                    ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
                    ->leftJoin('warehouses as w', 'w.id', '=', 'so.warehouse_id')
                    ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id');

                ReportFilter::applyDateRange($query, $params);
                ReportFilter::applyDimensions($query, $params);
                ReportFilter::applyOrderFilters($query, $params);

                return $query
                    ->select([
                        'c.id as customer_id',
                        'c.code as customer_code',
                        'c.name as customer_name',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw('ROUND(SUM(COALESCE(so.total_amount,0)), 2) as order_amount'),
                        DB::raw('ROUND(SUM(COALESCE(so.paid_amount,0)), 2) as paid_amount'),
                        DB::raw('ROUND(SUM(COALESCE(so.total_amount,0)) - SUM(COALESCE(so.paid_amount,0)), 2) as unpaid_amount'),
                    ])
                    ->groupBy('c.id', 'c.code', 'c.name')
                    ->orderByDesc('order_amount');

            case 'return_summary':
                return $this->returnQuery($params)
                    ->select([
                        'c.id as customer_id',
                        'c.code as customer_code',
                        'c.name as customer_name',
                        DB::raw('COUNT(DISTINCT sr.id) as return_count'),
                        DB::raw('SUM(sr.total_qty) as return_qty'),
                        DB::raw('ROUND(SUM(COALESCE(sr.total_amount,0)), 2) as return_amount'),
                        DB::raw('ROUND(SUM(COALESCE(sr.refund_amount,0)), 2) as refund_amount'),
                    ])
                    ->groupBy('c.id', 'c.code', 'c.name')
                    ->orderByDesc('return_amount');
        }

        return $this->baseQuery($params);
    }

    /**
     * 日月增长退货率对比：按月聚合销售与退货，求每月退货率与上月环比。
     *
     * 销售金额 = 该月订单金额，退货金额 = 该月退货单金额。
     * 环比 = 本月退货率 - 上月退货率；> 阈值时前端标红并给上涨箭头。
     * 阈值由请求参数 return_rate_threshold 提供（默认 5%），前端用列定义里的 threshold 渲染。
     * 返回 Collection（每月一行），由 index/export 统一处理。
     */
    private function monthlyReturnRateQuery(array $params)
    {
        $amountExpr = 'SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END)';

        $salesSub = $this->baseQuery($params)
            ->select(
                DB::raw("strftime('%Y-%m', so.order_date) as period"),
                DB::raw($amountExpr.' as sales_amount')
            )
            ->groupBy(DB::raw("strftime('%Y-%m', so.order_date)"));

        $returnSub = DB::table('sales_returns as sr')
            ->leftJoin('customers as c', 'c.id', '=', 'sr.customer_id')
            ->whereNotIn('sr.status', ['cancelled', 'draft'])
            ->select(
                DB::raw("strftime('%Y-%m', sr.return_date) as period"),
                DB::raw('SUM(COALESCE(sr.total_amount,0)) as return_amount')
            )
            ->groupBy(DB::raw("strftime('%Y-%m', sr.return_date)"));

        if ($params['customer_ids']) {
            $returnSub->whereIn('sr.customer_id', $params['customer_ids']);
        }
        if ($params['customer_category']) {
            $returnSub->whereIn('c.category', $params['customer_category']);
        }
        if ($params['customer_level_ids']) {
            $returnSub->whereIn('c.level_id', $params['customer_level_ids']);
        }
        if ($params['start_date'] || $params['end_date']) {
            $returnSub->whereBetween('sr.return_date', [
                $params['start_date'] ?: '1970-01-01',
                $params['end_date'] ?: '2999-12-31',
            ]);
        }

        $rows = DB::table(DB::raw('('.$salesSub->toSql().') as s'))
            ->mergeBindings($salesSub)
            ->leftJoinSub($returnSub, 'r', 'r.period', '=', 's.period')
            ->select(
                's.period',
                's.sales_amount',
                DB::raw('COALESCE(r.return_amount,0) as return_amount'),
                DB::raw('CASE WHEN s.sales_amount > 0 THEN ROUND(COALESCE(r.return_amount,0) / s.sales_amount * 100, 2) ELSE 0 END as return_rate')
            )
            ->orderByDesc('s.period')
            ->get();

        // 逐行回填上月退货率与环比
        $rateByPeriod = [];
        foreach ($rows as $row) {
            $rateByPeriod[$row->period] = (float) $row->return_rate;
        }
        $periods = array_keys($rateByPeriod);

        $result = [];
        foreach ($periods as $i => $period) {
            $prevRate = $i + 1 < count($periods) ? $rateByPeriod[$periods[$i + 1]] : null;
            $result[] = (object) [
                'period' => $period,
                'sales_amount' => $rows[$i]->sales_amount,
                'return_amount' => $rows[$i]->return_amount,
                'return_rate' => $rateByPeriod[$period],
                'prev_return_rate' => $prevRate,
                'mom_change' => $prevRate === null ? 0 : round($rateByPeriod[$period] - $prevRate, 2),
            ];
        }

        return collect($result);
    }

    /** 汇总行：按维度给合计 */
    private function summary(array $params, string $dimension): array
    {
        $amountExpr = 'SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END)';

        if ($dimension === 'return_rate_mom') {
            $row = $this->baseQuery($params)
                ->select([DB::raw($amountExpr.' as amount')])
                ->first();
            $rRow = $this->returnQuery($params)
                ->select([DB::raw('SUM(COALESCE(sr.total_amount,0)) as return_amount')])
                ->first();

            $amount = ReportFilter::num($row->amount ?? 0);
            $returnAmount = ReportFilter::num($rRow->return_amount ?? 0);

            return [
                'amount' => $amount,
                'return_amount' => $returnAmount,
                'return_rate' => $amount > 0 ? round($returnAmount / $amount * 100, 2) : 0,
            ];
        }

        if ($dimension === 'shipped_uncollected') {
            $rows = $this->aggregate($params, $dimension)->get();
            $unpaid = ReportFilter::num($rows->sum('unpaid_amount'));

            return [
                'unpaid_amount' => $unpaid,
                'overdue_unpaid_amount' => ReportFilter::num($rows->where('age_days', '>', 60)->sum('unpaid_amount')),
                'order_count' => $rows->count(),
            ];
        }

        if ($dimension === 'return_summary') {
            $rows = $this->aggregate($params, $dimension)->get();

            return [
                'return_amount' => ReportFilter::num($rows->sum('return_amount')),
                'refund_amount' => ReportFilter::num($rows->sum('refund_amount')),
                'return_count' => $rows->sum('return_count'),
                'return_qty' => ReportFilter::num($rows->sum('return_qty')),
            ];
        }

        if ($dimension === 'customer_order') {
            $rows = $this->aggregate($params, $dimension)->get();

            return [
                'order_amount' => ReportFilter::num($rows->sum('order_amount')),
                'paid_amount' => ReportFilter::num($rows->sum('paid_amount')),
                'unpaid_amount' => ReportFilter::num($rows->sum('unpaid_amount')),
                'order_count' => $rows->sum('order_count'),
                'qty' => ReportFilter::num($rows->sum('qty')),
            ];
        }

        // order_detail / customer_sales 走统一销售汇总
        $row = $this->baseQuery($params)
            ->select([
                DB::raw('SUM(soi.quantity) as qty'),
                DB::raw($amountExpr.' as amount'),
                DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('COUNT(DISTINCT soi.product_id) as product_count'),
            ])
            ->first();

        $qty = ReportFilter::num($row->qty ?? 0);
        $amount = ReportFilter::num($row->amount ?? 0);
        $cost = ReportFilter::num($row->cost_amount ?? 0);

        return [
            'qty' => $qty,
            'amount' => $amount,
            'cost_amount' => $cost,
            'profit' => round($amount - $cost, 2),
            'order_count' => (int) ($row->order_count ?? 0),
            'product_count' => (int) ($row->product_count ?? 0),
        ];
    }

    /** 各维度的列定义（JSON 驱动，前端按 type 渲染） */
    private function columns(string $dimension, array $params): array
    {
        $threshold = (float) ($params['return_rate_threshold'] ?: 5);

        switch ($dimension) {
            case 'order_detail':
                return [
                    ['prop' => 'order_no', 'label' => '订单号', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_date', 'label' => '订单日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 60, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'qty', 'label' => '数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'price', 'label' => '单价', 'width' => 90, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'amount', 'label' => '金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 80, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 80, 'align' => 'center', 'type' => 'text'],
                ];

            case 'shipped_uncollected':
                return [
                    ['prop' => 'order_no', 'label' => '订单号', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'dispatch_date', 'label' => '发货日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_amount', 'label' => '订单金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'paid_amount', 'label' => '已收金额', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'unpaid_amount', 'label' => '未收金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'age_days', 'label' => '未收天数', 'width' => 90, 'align' => 'right', 'type' => 'int', 'cell_class' => 'unpaid-age', 'overdue_threshold' => 60],
                ];

            case 'return_rate_mom':
                return [
                    ['prop' => 'period', 'label' => '月份', 'width' => 120, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'sales_amount', 'label' => '销售金额', 'width' => 130, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'return_amount', 'label' => '退货金额', 'width' => 130, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'return_rate', 'label' => '退货率', 'width' => 100, 'align' => 'right', 'type' => 'rate', 'threshold' => $threshold],
                    ['prop' => 'prev_return_rate', 'label' => '上月退货率', 'width' => 110, 'align' => 'right', 'type' => 'rate'],
                    ['prop' => 'mom_change', 'label' => '环比', 'width' => 90, 'align' => 'right', 'type' => 'rate', 'arrow' => true, 'threshold' => $threshold],
                ];

            case 'customer_sales':
                return [
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'product_count', 'label' => '商品数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'amount', 'label' => '金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'profit', 'label' => '毛利', 'width' => 120, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
                    ['prop' => 'last_order_date', 'label' => '最近销售日期', 'width' => 120, 'align' => 'center', 'type' => 'text'],
                ];

            case 'customer_order':
                return [
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '订货单数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '订货数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'order_amount', 'label' => '订货金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'paid_amount', 'label' => '已收金额', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'unpaid_amount', 'label' => '未收金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                ];

            case 'return_summary':
                return [
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'return_count', 'label' => '退货单数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'return_qty', 'label' => '退货数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'return_amount', 'label' => '退货金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'refund_amount', 'label' => '退款金额', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                ];
        }

        return [];
    }

    /**
     * 穿透：客户维度双击 → 该客户的订单列表抽屉（E2）。
     *
     * 传 customer_id（或 customer_code），返回客户表头 + 订单列表，
     * 供 360px 抽屉渲染，让报表上的客户销售数字可追到具体订单。
     */
    public function drill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|integer',
            'customer_code' => 'nullable|string|max:50',
            'start_date' => 'nullable|string',
            'end_date' => 'nullable|string',
        ]);

        if (! $validated['customer_id'] && ! $validated['customer_code']) {
            return $this->error('缺少客户 ID 或客户编码', 422);
        }

        $query = DB::table('customers as c')
            ->select(
                'c.id as customer_id', 'c.code as customer_code', 'c.name as customer_name',
                'c.category', 'c.contact', 'c.phone', 'c.address', 'c.balance'
            );

        if ($validated['customer_id']) {
            $query->where('c.id', $validated['customer_id']);
        } else {
            $query->where('c.code', $validated['customer_code']);
        }

        $header = $query->first();
        if (! $header) {
            return $this->error('客户不存在', 404);
        }

        $orders = DB::table('sales_orders as so')
            ->leftJoin('warehouses as w', 'w.id', '=', 'so.warehouse_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.customer_id', $header->customer_id)
            ->whereNull('so.original_order_id')
            ->select(
                'so.order_no', 'so.order_date', 'so.dispatch_date',
                'so.order_type', 'so.status', 'so.payment_status',
                'so.total_amount', 'so.paid_amount',
                'w.name as warehouse_name', 'sm.real_name as salesman_name'
            )
            ->orderByDesc('so.order_date')
            ->orderByDesc('so.id');

        if ($validated['start_date'] || $validated['end_date']) {
            $orders->whereBetween('so.order_date', [
                $validated['start_date'] ?: '1970-01-01',
                $validated['end_date'] ?: '2999-12-31',
            ]);
        }

        return $this->success([
            'header' => $header,
            'items' => $orders->get(),
            'columns' => [
                ['prop' => 'order_no', 'label' => '订单号', 'width' => 140, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'order_date', 'label' => '订单日期', 'width' => 100, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'order_type', 'label' => '类型', 'width' => 80, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'status', 'label' => '状态', 'width' => 80, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'total_amount', 'label' => '订单金额', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                ['prop' => 'paid_amount', 'label' => '已收金额', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 80, 'align' => 'center', 'type' => 'text'],
            ],
        ]);
    }
}