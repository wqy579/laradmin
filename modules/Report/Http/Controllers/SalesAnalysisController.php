<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块：销售分析族报表（报表管理 → 销售分析）
 *
 * E3 七张销售分析报表共用一个筛选条件集，切换 tab 只换 dimension 参数：
 *   业务员销售分析 / 商品销售分析 / 销售类排行 / 特价统计 / 促销分析 / 业务品项销售 / 业务品项铺市。
 *
 * 口径与客户报表一致：金额统一按
 *   SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * 单价 END)，
 * 默认剔除红冲单（original_order_id 非空）。
 * 纯读模块：不写业务表，只读销售/促销事实核对数字。
 *
 * 本族特色渲染（由后端下发列 type + 前端 ReportTable 渲染）：
 *   rank     销售类排行 TOP10 徽标（金/银/铜小圆点）
 *   tag      商品销售分析 ABC 分类 Tag（按累计金额占比算 A/B/C）
 *   coverage 业务品项铺市 铺市率（<阈值 红、≥阈值 蓝）
 *   progress 业务员销售分析 完成率进度条（<60% 橙、≥100% 绿）
 */
class SalesAnalysisController extends Controller
{
    /** 维度 key → 中文名（顺序即 tab 顺序，与设计方案一致） */
    private const DIMENSIONS = [
        'salesman_sales' => '业务员销售分析',
        'product_sales' => '商品销售分析',
        'sale_rank' => '销售类排行',
        'special_price' => '特价统计',
        'promotion' => '促销分析',
        'salesman_item_sales' => '业务品项销售',
        'salesman_item_coverage' => '业务品项铺市',
    ];

    /** 销售金额统一口径（处理 0 价/赠品行回退单价） */
    private const AMOUNT_EXPR = 'SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END)';

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        $rows = $this->aggregate($params, $dimension);

        // 业务员销售分析 / 商品销售分析 / 销售类排行：整表取回后在 PHP 里算完成率 / ABC / 名次
        if (in_array($dimension, ['salesman_sales', 'product_sales', 'sale_rank'], true)) {
            return $this->success($this->manuallyPaged($params, $dimension, $rows));
        }

        $paginator = $rows->paginate($params['page_size'], ['*'], 'page', $params['page']);

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

        $rows = $this->aggregate($params, $dimension);

        // 手工整形维度（含完成率/ABC/名次计算）统一走 collect 整形
        if (in_array($dimension, ['salesman_sales', 'product_sales', 'sale_rank'], true)) {
            $rows = $this->enrich($rows, $dimension);
        }

        return ReportFilter::excelResponse(
            ReportFilter::excel('销售分析 - '.(self::DIMENSIONS[$dimension] ?? ''), $columns, $rows->limit(50000)->get()),
            'sales_analysis_'.$dimension.'.xls'
        );
    }

    private function dimension(array $params): string
    {
        $dimension = (string) ($params['dimension'] ?: 'salesman_sales');

        return array_key_exists($dimension, self::DIMENSIONS) ? $dimension : 'salesman_sales';
    }

    /** 手工分页包装（完成率/ABC/TOP10 需整表整形后取页） */
    private function manuallyPaged(array $params, string $dimension, $rows): array
    {
        $rows = $this->enrich($rows, $dimension);

        $page = $params['page'];
        $pageSize = $params['page_size'];
        $total = $rows->count();
        $items = $rows->forPage($page, $pageSize)->values();

        $paginator = new LengthAwarePaginator(
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

        return $data;
    }

    /**
     * 整表整形：业务员完成率（按最高业务员归一 → 进度条）、销售类排行 TOP10 名次徽标。
     * 返回 Collection。
     */
    private function enrich($rows, string $dimension)
    {
        if ($dimension === 'salesman_sales') {
            // 补齐毛利/毛利率；完成率按最高业务员金额归一 → 进度条（top 业务员 100%）
            $max = max(1, (float) $rows->max('amount'));

            return $rows->map(function ($r) use ($max) {
                $r->profit = round((float) $r->amount - (float) $r->cost_amount, 2);
                $r->profit_rate = $r->amount > 0 ? round((float) $r->profit / $r->amount * 100, 2) : 0;
                $r->completion = round((float) $r->amount / $max * 100, 2);

                return $r;
            });
        }

        if ($dimension === 'sale_rank') {
            $rank = 0;

            return $rows->map(function ($r) use (&$rank) {
                $rank++;
                $r->rank = $rank;

                return $r;
            });
        }

        if ($dimension === 'product_sales') {
            // 商品 ABC 分类：按累计销售金额占比，A 前 70%、B 前 90%、其余 C
            return $this->assignAbc($rows);
        }

        return $rows;
    }

    /** 商品 ABC 分类：按累计销售金额占比 A/B/C（A≤70%、B≤90%、C 其余） */
    private function assignAbc($rows)
    {
        $total = (float) $rows->sum('amount');
        if ($total <= 0) {
            return $rows->map(function ($r) {
                $r->abc = 'C';

                return $r;
            });
        }

        $cum = 0.0;

        return $rows->map(function ($r) use ($total, &$cum) {
            $cum += (float) $r->amount;
            $share = $cum / $total * 100;
            $r->abc = $share <= 70 ? 'A' : ($share <= 90 ? 'B' : 'C');

            return $r;
        });
    }

    /** 带全部筛选条件的销售事实基础查询（别名 so/soi/p/c/w/b/sm，与客户报表一致） */
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
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->leftJoin('employees as e', 'e.user_id', '=', 'so.salesman_id');

        ReportFilter::applyDateRange($query, $params);
        ReportFilter::applyDimensions($query, $params);
        ReportFilter::applyOrderFilters($query, $params);

        return $query;
    }

    /** 各维度聚合查询 */
    private function aggregate(array $params, string $dimension)
    {
        switch ($dimension) {
            case 'salesman_sales':
                return $this->baseQuery($params)
                    ->select([
                        'so.salesman_id as salesman_id',
                        DB::raw('COALESCE(e.code, "") as salesman_code'),
                        DB::raw('COALESCE(sm.real_name, "未分配") as salesman_name'),
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('COUNT(DISTINCT soi.product_id) as product_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw(self::AMOUNT_EXPR.' as amount'),
                        DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                    ])
                    ->groupBy('so.salesman_id', 'e.code', 'sm.real_name')
                    ->orderByDesc(DB::raw(self::AMOUNT_EXPR));

            case 'product_sales':
                return $this->baseQuery($params)
                    ->select([
                        'p.id as product_id',
                        'p.code as product_code',
                        'p.name as product_name',
                        'p.spec',
                        'p.is_new',
                        'p.is_key',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw(self::AMOUNT_EXPR.' as amount'),
                        DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                    ])
                    ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.is_new', 'p.is_key')
                    ->orderByDesc(DB::raw(self::AMOUNT_EXPR));

            case 'sale_rank':
                return $this->baseQuery($params)
                    ->select([
                        'p.id as product_id',
                        'p.code as product_code',
                        'p.name as product_name',
                        'p.spec',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw(self::AMOUNT_EXPR.' as amount'),
                    ])
                    ->groupBy('p.id', 'p.code', 'p.name', 'p.spec')
                    ->orderByDesc(DB::raw(self::AMOUNT_EXPR))
                    ->orderByDesc('qty')
                    ->limit(10);

            case 'special_price':
                // 特价统计：只算特价销售行（sale_mode='特价销售' 或 price_source='特殊'）
                $query = $this->baseQuery($params);
                $query->where(function ($q) {
                    $q->where('soi.sale_mode', '特价销售')
                        ->orWhere('soi.price_source', '特殊');
                });

                return $query
                    ->select([
                        'p.id as product_id',
                        'p.code as product_code',
                        'p.name as product_name',
                        'p.spec',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw('SUM(soi.amount) as amount'),
                        DB::raw('AVG(soi.price) as avg_price'),
                    ])
                    ->groupBy('p.id', 'p.code', 'p.name', 'p.spec')
                    ->orderByDesc(DB::raw('SUM(soi.amount)'));

            case 'promotion':
                return $this->promotionQuery($params);

            case 'salesman_item_sales':
                return $this->baseQuery($params)
                    ->select([
                        'so.salesman_id as salesman_id',
                        DB::raw('COALESCE(e.code, "") as salesman_code'),
                        DB::raw('COALESCE(sm.real_name, "未分配") as salesman_name'),
                        'p.id as product_id',
                        'p.code as product_code',
                        'p.name as product_name',
                        'p.spec',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw(self::AMOUNT_EXPR.' as amount'),
                    ])
                    ->groupBy('so.salesman_id', 'e.code', 'sm.real_name', 'p.id', 'p.code', 'p.name', 'p.spec')
                    ->orderByDesc(DB::raw(self::AMOUNT_EXPR));

            case 'salesman_item_coverage':
                return $this->coverageQuery($params);
        }

        return $this->baseQuery($params);
    }

    /** 促销分析：按促销方案汇总使用单数 / 优惠金额 / 销售额 */
    private function promotionQuery(array $params)
    {
        $query = DB::table('promotions as pm')
            ->leftJoin('promotion_orders as po', 'po.promotion_id', '=', 'pm.id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'po.order_id')
            ->where('pm.status', '!=', 'draft')
            ->whereNull('so.original_order_id');

        // 促销活动时间与筛选日期区间取交集
        $start = $params['start_date'] ?? null;
        $end = $params['end_date'] ?? null;
        if ($start) {
            $query->where('pm.end_time', '>=', $start);
        }
        if ($end) {
            $query->where('pm.start_time', '<=', $end);
        }

        return $query
            ->select([
                'pm.id as promotion_id',
                'pm.promotion_no',
                'pm.name as promotion_name',
                'pm.type',
                'pm.start_time',
                'pm.end_time',
                DB::raw('COUNT(DISTINCT po.order_id) as order_count'),
                DB::raw('COALESCE(SUM(po.discount_amount), 0) as discount_amount'),
                DB::raw('COALESCE(SUM(so.total_amount), 0) as order_amount'),
            ])
            ->groupBy('pm.id', 'pm.promotion_no', 'pm.name', 'pm.type', 'pm.start_time', 'pm.end_time')
            ->orderByDesc('discount_amount');
    }

    /**
     * 业务品项铺市：每个业务员 × 商品，铺市率 =
     *   销售该商品的客户数 / 该业务员覆盖的全部客户数。
     * 客户数只统计期间内有下单的客户（有业务往来的可铺对象）。
     */
    private function coverageQuery(array $params)
    {
        // 子查询1：每业务员 × 商品 → 销售该商品的客户数
        $prodCust = $this->baseQuery($params)
            ->select(
                'so.salesman_id',
                DB::raw('COALESCE(e.code, "") as salesman_code'),
                'p.id as product_id',
                DB::raw('COUNT(DISTINCT so.customer_id) as prod_cust_count')
            )
            ->whereNotNull('so.customer_id')
            ->groupBy('so.salesman_id', 'e.code', 'p.id');

        // 子查询2：每业务员 → 覆盖的全部客户数（同套筛选区间，分母与分子口径一致）
        $totCust = $this->baseQuery($params)
            ->select(
                'so.salesman_id',
                DB::raw('COUNT(DISTINCT so.customer_id) as total_cust_count')
            )
            ->whereNotNull('so.customer_id')
            ->groupBy('so.salesman_id');

        $sub1 = DB::table(DB::raw('('.$prodCust->toSql().') as pc'))
            ->mergeBindings($prodCust);

        $sub2 = DB::table(DB::raw('('.$totCust->toSql().') as tc'))
            ->mergeBindings($totCust);

        return DB::table('products as p')
            ->joinSub($sub1, 'pc', function ($join) {
                $join->on('pc.product_id', '=', 'p.id');
            })
            ->joinSub($sub2, 'tc', 'tc.salesman_id', '=', 'pc.salesman_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'pc.salesman_id')
            ->select([
                'pc.salesman_id',
                'pc.salesman_code',
                DB::raw('COALESCE(sm.real_name, "未分配") as salesman_name'),
                'p.id as product_id',
                'p.code as product_code',
                'p.name as product_name',
                'p.spec',
                DB::raw('pc.prod_cust_count as cust_count'),
                DB::raw('tc.total_cust_count as total_cust_count'),
                DB::raw('ROUND(COALESCE(pc.prod_cust_count, 0) / NULLIF(tc.total_cust_count, 0) * 100, 2) as coverage'),
            ])
            ->orderByDesc('coverage');
    }

    /** 汇总行：按维度给合计 */
    private function summary(array $params, string $dimension): array
    {
        if ($dimension === 'salesman_sales') {
            $rows = $this->aggregate($params, $dimension)->get();

            return [
                'order_count' => $rows->sum('order_count'),
                'qty' => ReportFilter::num($rows->sum('qty')),
                'amount' => ReportFilter::num($rows->sum('amount')),
                'cost_amount' => ReportFilter::num($rows->sum('cost_amount')),
                'profit' => round($rows->sum('amount') - $rows->sum('cost_amount'), 2),
            ];
        }

        if ($dimension === 'product_sales' || $dimension === 'sale_rank') {
            $rows = $this->aggregate($params, $dimension)->get();

            return [
                'order_count' => $rows->sum('order_count'),
                'qty' => ReportFilter::num($rows->sum('qty')),
                'amount' => ReportFilter::num($rows->sum('amount')),
                'cost_amount' => ReportFilter::num($rows->sum('cost_amount')),
                'profit' => round($rows->sum('amount') - $rows->sum('cost_amount'), 2),
            ];
        }

        if ($dimension === 'special_price') {
            $rows = $this->aggregate($params, $dimension)->get();

            return [
                'order_count' => $rows->sum('order_count'),
                'qty' => ReportFilter::num($rows->sum('qty')),
                'amount' => ReportFilter::num($rows->sum('amount')),
            ];
        }

        if ($dimension === 'promotion') {
            $rows = $this->aggregate($params, $dimension)->get();

            return [
                'order_count' => $rows->sum('order_count'),
                'discount_amount' => ReportFilter::num($rows->sum('discount_amount')),
                'order_amount' => ReportFilter::num($rows->sum('order_amount')),
            ];
        }

        if ($dimension === 'salesman_item_sales') {
            $rows = $this->aggregate($params, $dimension)->get();

            return [
                'order_count' => $rows->sum('order_count'),
                'qty' => ReportFilter::num($rows->sum('qty')),
                'amount' => ReportFilter::num($rows->sum('amount')),
            ];
        }

        if ($dimension === 'salesman_item_coverage') {
            $rows = $this->aggregate($params, $dimension)->get();

            return [
                'cust_count' => $rows->sum('cust_count'),
                'coverage' => $rows->count() ? ReportFilter::num($rows->avg('coverage')) : 0,
            ];
        }

        return [];
    }

    /** 各维度的列定义（JSON 驱动，前端按 type 渲染） */
    private function columns(string $dimension, array $params): array
    {
        switch ($dimension) {
            case 'salesman_sales':
                return [
                    ['prop' => 'salesman_code', 'label' => '业务员编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 120, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'product_count', 'label' => '品项数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'amount', 'label' => '销售金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'profit', 'label' => '毛利', 'width' => 120, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
                    ['prop' => 'profit_rate', 'label' => '毛利率', 'width' => 90, 'align' => 'right', 'type' => 'rate'],
                    ['prop' => 'completion', 'label' => '完成率', 'width' => 140, 'align' => 'left', 'type' => 'progress'],
                ];

            case 'product_sales':
                return [
                    ['prop' => 'abc', 'label' => 'ABC', 'width' => 70, 'align' => 'center', 'type' => 'tag', 'tag_map' => ['A' => 'A', 'B' => 'B', 'C' => 'C']],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'customer_count', 'label' => '客户数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'amount', 'label' => '销售金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'profit', 'label' => '毛利', 'width' => 120, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
                ];

            case 'sale_rank':
                return [
                    ['prop' => 'rank', 'label' => '排名', 'width' => 90, 'align' => 'center', 'type' => 'rank', 'rank_label' => '名'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'amount', 'label' => '销售金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                ];

            case 'special_price':
                return [
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '特价数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'amount', 'label' => '特价金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'avg_price', 'label' => '特价均价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                ];

            case 'promotion':
                return [
                    ['prop' => 'promotion_no', 'label' => '促销单号', 'width' => 130, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'promotion_name', 'label' => '促销方案', 'width' => 180, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'type', 'label' => '类型', 'width' => 90, 'align' => 'center', 'type' => 'tag', 'tag_map' => [
                        'discount' => '折扣', 'full_reduction' => '满减', 'buy_gift' => '买赠', 'special_price' => '特价',
                    ]],
                    ['prop' => 'start_time', 'label' => '开始时间', 'width' => 140, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'end_time', 'label' => '结束时间', 'width' => 140, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '使用单数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'discount_amount', 'label' => '优惠金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'order_amount', 'label' => '带动销售', 'width' => 130, 'align' => 'right', 'type' => 'money'],
                ];

            case 'salesman_item_sales':
                return [
                    ['prop' => 'salesman_code', 'label' => '业务员编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'amount', 'label' => '销售金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                ];

            case 'salesman_item_coverage':
                return [
                    ['prop' => 'salesman_code', 'label' => '业务员编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'cust_count', 'label' => '覆盖客户数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'total_cust_count', 'label' => '可铺客户数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'coverage', 'label' => '铺市率', 'width' => 100, 'align' => 'right', 'type' => 'coverage', 'danger_below' => 50],
                ];
        }

        return [];
    }
}
