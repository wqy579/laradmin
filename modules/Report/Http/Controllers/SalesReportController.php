<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块二：销售报表（报表管理 → 销售报表）
 *
 * 13 个汇总维度共用一个筛选条件集，切换 tab 只换 dimension 参数。
 * 列定义随维度一起下发（columns），前端按 type 渲染，避免 13 套表头在前端各写一遍。
 *
 * 关于「零销售」：追加显示零销售 / 仅显示零销售只对「商品」维度生效——
 * 客户、单据这类维度本身就是从销售事实表反查出来的，没有销售就不存在这一行，
 * 强行补零只会造出没有业务含义的空行。
 */
class SalesReportController extends Controller
{
    /** 维度 key → 中文名（顺序即 tab 顺序，与设计方案一致） */
    private const DIMENSIONS = [
        'product_detail' => '商品明细',
        'customer' => '客户',
        'customer_product' => '客户商品',
        'customer_category_product' => '客户大类商品',
        'product' => '商品',
        'warehouse_product' => '仓库商品',
        'brand' => '品牌',
        'customer_category_sale_type' => '客户大类销售类型',
        'customer_subcategory_sale_type' => '客户小类销售类型',
        'customer_product_sale_type' => '客户商品销售类型',
        'product_sale_type' => '商品销售类型',
        'doc' => '单据',
        'help' => '帮助视频',
    ];

    /** 销售类型：order_type 的三个取值 → 展示文案（设计方案里的标签样式在前端按文案配色） */
    private const SALE_TYPES = [
        'normal' => '正常销售',
        'promotion' => '促销销售',
        'special' => '特价销售',
    ];

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        if ($dimension === 'help') {
            return $this->success([
                'dimension' => 'help',
                'columns' => [],
                'list' => [],
                'total' => 0,
                'summary' => null,
                'help' => $this->helpContent(),
            ]);
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
        $rows = $this->aggregate($params, $dimension)->limit(50000)->get();

        $headers = array_column($columns, 'label');
        $body = [];
        foreach ($rows as $row) {
            $body[] = array_map(fn ($c) => $row->{$c['prop']} ?? '', $columns);
        }

        return ReportFilter::csvResponse(
            ReportFilter::csv('销售报表 - '.(self::DIMENSIONS[$dimension] ?? ''), $headers, $body),
            'sales_report_'.$dimension.'.csv'
        );
    }

    private function dimension(array $params): string
    {
        $dimension = (string) ($params['dimension'] ?: 'product_detail');

        return array_key_exists($dimension, self::DIMENSIONS) ? $dimension : 'product_detail';
    }

    /** 带全部筛选条件的基础事实表（别名 so/soi/p/c/w/b/mc/sc/cl/sm） */
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

    /** 按维度聚合 */
    private function aggregate(array $params, string $dimension)
    {
        if ($dimension === 'product_detail') {
            return $this->baseQuery($params)
                ->select([
                    'soi.id',
                    DB::raw('(SELECT delivery_no FROM deliveries WHERE deliveries.order_id = so.id ORDER BY deliveries.id DESC LIMIT 1) as delivery_no'),
                    DB::raw('(SELECT delivery_date FROM deliveries WHERE deliveries.order_id = so.id ORDER BY deliveries.id DESC LIMIT 1) as delivery_date'),
                    'so.order_date',
                    'c.code as customer_code',
                    'c.name as customer_name',
                    'p.code as product_code',
                    'p.name as product_name',
                    'p.spec',
                    'p.price_unit_small as unit',
                    'soi.quantity as qty',
                    DB::raw('CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END as price'),
                    DB::raw('CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END as amount'),
                    'soi.discount_rate',
                    'w.name as warehouse_name',
                    'sm.real_name as salesman_name',
                    DB::raw("CASE so.order_type WHEN 'promotion' THEN '促销销售' WHEN 'special' THEN '特价销售' ELSE '正常销售' END as sale_type_label"),
                ])
                ->orderBy('so.order_date', 'desc')
                ->orderBy('so.id', 'desc');
        }

        // 商品维度支持「零销售」：以商品全集为驱动表左连销售聚合
        if ($dimension === 'product' && in_array($params['zero_sale'], ['append', 'only'], true)) {
            return $this->zeroSaleProductQuery($params);
        }

        $query = $this->baseQuery($params);

        $selects = [
            DB::raw('SUM(soi.quantity) as qty'),
            DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'),
            DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
            DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) - SUM(soi.quantity * COALESCE(p.cost_price, 0)) as profit'),
            DB::raw('COUNT(DISTINCT so.id) as order_count'),
            DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
            DB::raw('COUNT(DISTINCT soi.product_id) as product_count'),
        ];

        $groupBy = [];

        switch ($dimension) {
            case 'customer':
                $selects = array_merge($selects, [
                    'c.id as customer_id', 'c.code as customer_code', 'c.name as customer_name',
                    DB::raw('MAX(so.order_date) as last_order_date'),
                ]);
                $groupBy = ['c.id', 'c.code', 'c.name'];
                break;

            case 'customer_product':
                $selects = array_merge($selects, [
                    'c.id as customer_id', 'c.code as customer_code', 'c.name as customer_name',
                    'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                    'p.spec', 'p.price_unit_small as unit', 'mc.name as main_category_name',
                ]);
                $groupBy = ['c.id', 'c.code', 'c.name', 'p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'mc.name'];
                break;

            case 'customer_category_product':
                $selects = array_merge($selects, [
                    DB::raw('COALESCE(c.category, "未分类") as customer_category'),
                    'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                    'p.spec', 'p.price_unit_small as unit',
                ]);
                $groupBy = ['c.category', 'p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small'];
                break;

            case 'product':
                $selects = array_merge($selects, [
                    'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                    'p.spec', 'p.price_unit_small as unit', 'mc.name as main_category_name',
                    'sc.name as sub_category_name', 'b.name as brand_name',
                    DB::raw('MAX(so.order_date) as last_order_date'),
                ]);
                $groupBy = ['p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'mc.name', 'sc.name', 'b.name'];
                break;

            case 'warehouse_product':
                $selects = array_merge($selects, [
                    'w.id as warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name',
                    'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                    'p.spec', 'p.price_unit_small as unit',
                ]);
                $groupBy = ['w.id', 'w.code', 'w.name', 'p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small'];
                break;

            case 'brand':
                $selects = array_merge($selects, [
                    'b.id as brand_id', DB::raw('COALESCE(b.name, "未指定品牌") as brand_name'),
                ]);
                $groupBy = ['b.id', 'b.name'];
                break;

            case 'customer_category_sale_type':
            case 'customer_subcategory_sale_type':
            case 'customer_product_sale_type':
            case 'product_sale_type':
                $label = DB::raw("CASE so.order_type WHEN 'promotion' THEN '促销销售' WHEN 'special' THEN '特价销售' ELSE '正常销售' END as sale_type_label");
                $selects[] = $label;

                if ($dimension === 'customer_category_sale_type') {
                    $selects[] = DB::raw('COALESCE(c.category, "未分类") as customer_category');
                    $groupBy = ['c.category', 'so.order_type'];
                } elseif ($dimension === 'customer_subcategory_sale_type') {
                    $selects[] = DB::raw('COALESCE(cl.name, "未分级") as customer_level_name');
                    $groupBy = ['cl.name', 'so.order_type'];
                } elseif ($dimension === 'customer_product_sale_type') {
                    $selects = array_merge($selects, [
                        'c.id as customer_id', 'c.code as customer_code', 'c.name as customer_name',
                        'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                    ]);
                    $groupBy = ['c.id', 'c.code', 'c.name', 'p.id', 'p.code', 'p.name', 'so.order_type'];
                } else {
                    $selects = array_merge($selects, [
                        'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                        'p.spec', 'p.price_unit_small as unit',
                    ]);
                    $groupBy = ['p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'so.order_type'];
                }
                break;

            case 'doc':
                $selects = [
                    'so.id as order_id', 'so.order_no', 'so.order_date',
                    'c.code as customer_code', 'c.name as customer_name',
                    'w.name as warehouse_name', 'sm.real_name as salesman_name',
                    DB::raw('SUM(soi.quantity) as qty'),
                    DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'),
                    DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                    DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) - SUM(soi.quantity * COALESCE(p.cost_price, 0)) as profit'),
                    DB::raw('COUNT(DISTINCT soi.product_id) as product_count'),
                    DB::raw("CASE so.order_type WHEN 'promotion' THEN '促销销售' WHEN 'special' THEN '特价销售' ELSE '正常销售' END as sale_type_label"),
                    'so.status',
                ];
                $groupBy = ['so.id', 'so.order_no', 'so.order_date', 'c.code', 'c.name', 'w.name', 'sm.real_name', 'so.order_type', 'so.status'];
                break;
        }

        $query->select($selects);

        foreach ($groupBy as $column) {
            $query->groupBy($column);
        }

        return $query->orderByDesc('amount');
    }

    /**
     * 商品维度 + 零销售选项：商品全集左连销售聚合
     *
     * 不 leftJoin 事实表再 group by（那样会把商品筛选条件写成 HAVING，
     * 且「没有销售的商品」根本不会出现在事实表里），而是以 products 为驱动表，
     * 把聚合结果做成子查询右挂，零销售行自然落在 NULL 侧。
     */
    private function zeroSaleProductQuery(array $params)
    {
        $sold = $this->baseQuery($params)
            ->select([
                'p.id as product_id',
                DB::raw('SUM(soi.quantity) as qty'),
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'),
                DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
                DB::raw('MAX(so.order_date) as last_order_date'),
            ])
            ->groupBy('p.id');

        $query = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->leftJoin('product_categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->leftJoinSub($sold, 's', 's.product_id', '=', 'p.id')
            ->select([
                'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                'p.spec', 'p.price_unit_small as unit',
                'mc.name as main_category_name', 'sc.name as sub_category_name',
                DB::raw('COALESCE(b.name, "未指定品牌") as brand_name'),
                DB::raw('COALESCE(s.qty, 0) as qty'),
                DB::raw('COALESCE(s.amount, 0) as amount'),
                DB::raw('COALESCE(s.cost_amount, 0) as cost_amount'),
                DB::raw('COALESCE(s.amount, 0) - COALESCE(s.cost_amount, 0) as profit'),
                DB::raw('COALESCE(s.order_count, 0) as order_count'),
                DB::raw('COALESCE(s.customer_count, 0) as customer_count'),
                DB::raw('1 as product_count'),
                's.last_order_date',
            ])
            ->where('p.is_active', 1);

        // 商品维度筛选：事实表筛选在外层商品表上重放一次
        if ($params['brand_ids']) {
            $query->whereIn('p.brand_id', $params['brand_ids']);
        }
        if ($params['main_category_ids']) {
            $query->whereIn('p.main_category_id', $params['main_category_ids']);
        }
        if ($params['sub_category_ids']) {
            $query->whereIn('p.sub_category_id', $params['sub_category_ids']);
        }
        if ($params['product_ids']) {
            $query->whereIn('p.id', $params['product_ids']);
        }
        if (! empty($params['product_name'])) {
            $query->where('p.name', 'like', '%'.$params['product_name'].'%');
        }
        if (! empty($params['product_code'])) {
            $query->where('p.code', 'like', '%'.$params['product_code'].'%');
        }
        if ($params['is_new']) {
            $query->where('p.is_new', 1);
        }
        if ($params['is_key']) {
            $query->where('p.is_key', 1);
        }

        if ($params['zero_sale'] === 'only') {
            $query->whereNull('s.product_id');
        }

        return $query->orderByDesc('amount');
    }

    /** 汇总行：数量 / 金额 / 成本 / 毛利 / 毛利率 */
    private function summary(array $params, string $dimension): array
    {
        if ($dimension === 'product' && in_array($params['zero_sale'], ['append', 'only'], true)) {
            // 零销售模式下事实表被商品全集驱动，逐行汇总即可；
            // 客户数不能按行相加（同一客户买多个商品会被重复计），单独查一次去重值。
            $rows = $this->zeroSaleProductQuery($params)->get();
            $row = (object) [
                'qty' => $rows->sum('qty'),
                'amount' => $rows->sum('amount'),
                'cost_amount' => $rows->sum('cost_amount'),
                'order_count' => $rows->sum('order_count'),
                'customer_count' => $this->baseQuery($params)->distinct()->count('so.customer_id'),
                'product_count' => $rows->count(),
            ];
        } else {
            $row = $this->baseQuery($params)
                ->select([
                    DB::raw('SUM(soi.quantity) as qty'),
                    DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'),
                    DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                    DB::raw('COUNT(DISTINCT so.id) as order_count'),
                    DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
                    DB::raw('COUNT(DISTINCT soi.product_id) as product_count'),
                ])
                ->first();
        }

        $qty = ReportFilter::num($row->qty ?? 0);
        $amount = ReportFilter::num($row->amount ?? 0);
        $cost = ReportFilter::num($row->cost_amount ?? 0);
        $profit = round($amount - $cost, 2);

        return [
            'qty' => $qty,
            'amount' => $amount,
            'cost_amount' => $cost,
            'profit' => $profit,
            'profit_rate' => $amount > 0 ? round($profit / $amount * 100, 2) : 0,
            'order_count' => (int) ($row->order_count ?? 0),
            'customer_count' => (int) ($row->customer_count ?? 0),
            'product_count' => (int) ($row->product_count ?? 0),
        ];
    }

    /**
     * 各维度的列定义
     *
     * type 决定前端渲染方式：text / number / money / percent / tag / date。
     * 金额类统一 right 对齐，这是设计方案里写死的版式要求。
     */
    private function columns(string $dimension, array $params): array
    {
        $withPrice = (bool) $params['with_price'];

        $qty = ['prop' => 'qty', 'label' => '数量', 'width' => 90, 'align' => 'right', 'type' => 'number'];
        $amount = ['prop' => 'amount', 'label' => '金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true];
        $cost = ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 110, 'align' => 'right', 'type' => 'money'];
        $profit = ['prop' => 'profit', 'label' => '毛利', 'width' => 110, 'align' => 'right', 'type' => 'money', 'sign_color' => true];
        $rate = ['prop' => 'profit_rate', 'label' => '毛利率', 'width' => 90, 'align' => 'right', 'type' => 'percent'];
        $saleType = [
            'prop' => 'sale_type_label', 'label' => '销售类型', 'width' => 90, 'align' => 'center', 'type' => 'tag',
            'tag_map' => ['正常销售' => 'success', '促销销售' => 'warning', '特价销售' => 'danger'],
        ];

        // 不含单价时，单价/成本/毛利类列全部撤掉——隐藏的是价格信息本身
        $priceColumns = $withPrice ? [$cost, $profit, $rate] : [];

        switch ($dimension) {
            case 'product_detail':
                return array_values(array_filter([
                    ['prop' => 'delivery_no', 'label' => '出库单号', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'delivery_date', 'label' => '出库日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 50, 'align' => 'center', 'type' => 'text'],
                    $qty,
                    $withPrice ? ['prop' => 'price', 'label' => '单价', 'width' => 90, 'align' => 'right', 'type' => 'money'] : null,
                    $amount,
                    $withPrice ? ['prop' => 'discount_rate', 'label' => '折扣', 'width' => 70, 'align' => 'right', 'type' => 'percent'] : null,
                    ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 80, 'align' => 'center', 'type' => 'text'],
                    $saleType,
                ]));

            case 'customer':
                return array_merge([
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'product_count', 'label' => '商品数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                ], [$qty, $amount], $priceColumns, [
                    ['prop' => 'last_order_date', 'label' => '最近销售日期', 'width' => 120, 'align' => 'center', 'type' => 'text'],
                ]);

            case 'customer_product':
                return array_merge([
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 60, 'align' => 'center', 'type' => 'text'],
                ], [$qty, $amount], $priceColumns);

            case 'customer_category_product':
                return array_merge([
                    ['prop' => 'customer_category', 'label' => '客户大类', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 60, 'align' => 'center', 'type' => 'text'],
                ], [$qty, $amount], $priceColumns);

            case 'product':
                return array_merge([
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 60, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'main_category_name', 'label' => '商品大类', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'sub_category_name', 'label' => '商品小类', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'customer_count', 'label' => '客户数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                ], [$qty, $amount], $priceColumns, [
                    ['prop' => 'last_order_date', 'label' => '最近销售日期', 'width' => 120, 'align' => 'center', 'type' => 'text'],
                ]);

            case 'warehouse_product':
                return array_merge([
                    ['prop' => 'warehouse_code', 'label' => '仓库编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'warehouse_name', 'label' => '仓库名称', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 60, 'align' => 'center', 'type' => 'text'],
                ], [$qty, $amount], $priceColumns);

            case 'brand':
                return array_merge([
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_count', 'label' => '商品数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'customer_count', 'label' => '客户数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                ], [$qty, $amount], $priceColumns);

            case 'customer_category_sale_type':
                return array_merge([
                    ['prop' => 'customer_category', 'label' => '客户大类', 'width' => 140, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    $saleType,
                ], [$qty, $amount], $priceColumns);

            case 'customer_subcategory_sale_type':
                return array_merge([
                    ['prop' => 'customer_level_name', 'label' => '客户小类', 'width' => 140, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    $saleType,
                ], [$qty, $amount], $priceColumns);

            case 'customer_product_sale_type':
                return array_merge([
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    $saleType,
                ], [$qty, $amount], $priceColumns);

            case 'product_sale_type':
                return array_merge([
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 60, 'align' => 'center', 'type' => 'text'],
                    $saleType,
                ], [$qty, $amount], $priceColumns);

            case 'doc':
                return array_merge([
                    ['prop' => 'order_no', 'label' => '单据号', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_date', 'label' => '单据日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 150, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 90, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 80, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'product_count', 'label' => '商品数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                ], [$qty, $amount], $priceColumns, [$saleType]);
        }

        return [];
    }

    /** 帮助视频 tab：没有视频源时给出可落地的说明，不留白页 */
    private function helpContent(): array
    {
        return [
            'title' => '销售报表使用说明',
            'steps' => [
                '先选「日期类型」再选日期范围——付款/回款看资金流，申报/配货看业务流，两套口径不要混用。',
                '「商品明细」是原始流水，其余 12 个 tab 都是它在不同维度上的聚合结果。',
                '默认剔除红冲单（负单），要核对红冲影响请勾选「包含红冲」。',
                '「零销售」只在「商品」维度生效：追加显示未销售商品，或只看未销售商品。',
                '「含单价」取消勾选后，单价、成本、毛利相关列整体隐藏，用于给无价格权限的账号看数。',
                '查询条件可存为模版（右上角「查询模版」），下次一键复用。',
            ],
            'video_url' => null,
        ];
    }
}
