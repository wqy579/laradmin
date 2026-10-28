<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块：排行 + 价格类报表（报表管理 → 排行/价格）
 *
 * E4 四张报表共用一个筛选条件集，切换 tab 只换 dimension 参数：
 *   销售排行总表 / 商品价格一览 / 客户特价价格一览 / 促销价格一览。
 *
 * 核心口径：价格优先级 特价 > 等级价 > 默认价。
 *   特价   进行中促销对商品设置的特价（promotion_items.special_price）；
 *   等级价 商品对客户等级的单独价（product_level_prices）或等级默认折扣价
 *          （products.price_small × customer_levels.default_discount）；
 *   默认价 商品标准售价（products.price_small，与商品模块口径一致）。
 * 客户特价价格一览里，凡命中 特价/等级价 的行（即非默认价）标「价格来源」Tag 并整行高亮，
 * 价格列一律右对齐等宽（前端 money 类型）。
 *
 * 纯读模块：只读 商品/客户/等级/促销 主数据与销售事实，不写业务表。
 */
class PriceRankController extends Controller
{
    /** 维度 key → 中文名（顺序即 tab 顺序） */
    private const DIMENSIONS = [
        'sales_rank_total' => '销售排行总表',
        'product_price_list' => '商品价格一览',
        'customer_special_price' => '客户特价价格一览',
        'promotion_price_list' => '促销价格一览',
    ];

    /** 销售金额统一口径（处理 0 价/赠品行回退单价，与客户/销售分析族一致） */
    private const AMOUNT_EXPR = 'SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END)';

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        // 销售排行总表需整表整形补「名次」，客户特价一览需 PHP 解析价格命中 → 都走手工分页
        if (in_array($dimension, ['sales_rank_total', 'customer_special_price'], true)) {
            $rows = $this->aggregate($params, $dimension)->get();

            return $this->success($this->manuallyPaged($params, $dimension, $rows));
        }

        $paginator = $this->aggregate($params, $dimension)
            ->paginate($params['page_size'], ['*'], 'page', $params['page']);

        $data = ReportFilter::page($paginator);
        $data['dimension'] = $dimension;
        $data['columns'] = $this->columns($dimension);
        $data['summary'] = $this->summary($params, $dimension);

        return $this->success($data);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        $columns = $this->columns($dimension);

        if ($dimension === 'customer_special_price') {
            $rows = $this->enrich($this->aggregate($params, $dimension)->get(), $dimension)->take(50000);
        } else {
            $rows = $this->aggregate($params, $dimension)->limit(50000)->get();
        }

        return ReportFilter::excelResponse(
            ReportFilter::excel('价格/排行 - '.(self::DIMENSIONS[$dimension] ?? ''), $columns, $rows),
            'price_rank_'.$dimension.'.xls'
        );
    }

    private function dimension(array $params): string
    {
        $dimension = (string) ($params['dimension'] ?? '');

        return array_key_exists($dimension, self::DIMENSIONS) ? $dimension : 'sales_rank_total';
    }

    /** 手工分页包装（名次 / 价格命中需要整表整形后取页） */
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
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $data = ReportFilter::page($paginator);
        $data['dimension'] = $dimension;
        $data['columns'] = $this->columns($dimension);
        $data['summary'] = $this->summary($params, $dimension);

        return $data;
    }

    /** 整表整形：销售排行总表补名次、客户特价一览解析价格命中与来源。返回 Collection。 */
    private function enrich($rows, string $dimension)
    {
        if ($dimension === 'sales_rank_total') {
            $rank = 0;

            return $rows->map(function ($r) use (&$rank) {
                $rank++;
                $r->rank = $rank;
                $r->amount = ReportFilter::num($r->amount);
                $r->cost_amount = ReportFilter::num($r->cost_amount);
                $r->qty = ReportFilter::num($r->qty);
                $r->profit = ReportFilter::num((float) $r->amount - (float) $r->cost_amount);

                return $r;
            });
        }

        if ($dimension === 'customer_special_price') {
            return $this->resolveCustomerPrices($rows);
        }

        return $rows;
    }

    /**
     * 客户特价价格一览：跨 客户×商品 解析有效售价。
     *
     * $rows 已按 客户×商品 聚合（含默认价/等级价/特价 三档候选），
     * 这里按优先级 特价 > 等级价 > 默认价 落定 effective_price，
     * 并标记命中来源（price_source）与是否命中非默认规则（rule_hit，供前端整行高亮）。
     */
    private function resolveCustomerPrices($rows)
    {
        return $rows->map(function ($r) {
            $standard = ReportFilter::num($r->standard_price ?? 0);
            $level = ReportFilter::num($r->level_price ?? 0);
            $special = ReportFilter::num($r->special_price ?? 0);

            if ($special > 0) {
                $effective = $special;
                $source = '特价';
                $hit = true;
            } elseif ($level > 0) {
                $effective = $level;
                $source = '等级价';
                $hit = true;
            } else {
                $effective = $standard;
                $source = '默认价';
                $hit = false;
            }

            $r->standard_price = $standard;
            $r->level_price = $level;
            $r->special_price = $special;
            $r->effective_price = ReportFilter::num($effective);
            $r->price_source = $source;
            $r->rule_hit = $hit;

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
            case 'sales_rank_total':
                return $this->baseQuery($params)
                    ->select([
                        'p.id as product_id',
                        'p.code as product_code',
                        'p.name as product_name',
                        'p.spec',
                        DB::raw('COUNT(DISTINCT so.id) as order_count'),
                        DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
                        DB::raw('SUM(soi.quantity) as qty'),
                        DB::raw(self::AMOUNT_EXPR.' as amount'),
                        DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                    ])
                    ->groupBy('p.id', 'p.code', 'p.name', 'p.spec')
                    ->orderByDesc(DB::raw(self::AMOUNT_EXPR))
                    ->orderByDesc('qty');

            case 'product_price_list':
                return $this->productPriceQuery($params);

            case 'customer_special_price':
                return $this->customerPriceQuery($params);

            case 'promotion_price_list':
                return $this->promotionPriceQuery($params);
        }

        return $this->baseQuery($params);
    }

    /** 商品价格一览：标准价三档 + 成本 + 单位，与商品模块口径一致。只列启用中的商品。 */
    private function productPriceQuery(array $params)
    {
        $query = DB::table('products as p')
            ->where('p.is_active', 1);

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
        if (! empty($params['product_keyword'])) {
            $kw = '%'.$params['product_keyword'].'%';
            $query->where(function ($q) use ($kw) {
                $q->where('p.name', 'like', $kw)->orWhere('p.code', 'like', $kw);
            });
        }

        return $query
            ->select([
                'p.id as product_id',
                'p.code as product_code',
                'p.name as product_name',
                'p.spec',
                'p.price_unit_small as unit',
                'p.price_large',
                'p.price_medium',
                'p.price_small',
                'p.cost_price',
            ])
            ->orderBy('p.code');
    }

    /**
     * 客户特价价格一览：客户 × 商品 的价格矩阵。
     *
     * 以客户为主键，对每个客户 × 商品 带出 默认价 / 等级价 / 特价 三档候选，
     * 交给 PHP 按优先级落定有效价并打命中 Tag。为控制体量，只枚举启用中的客户与商品。
     */
    private function customerPriceQuery(array $params)
    {
        $now = now()->toDateTimeString();
        $query = DB::table('customers as c')
            ->crossJoin('products as p')
            ->leftJoin('customer_levels as cl', 'cl.id', '=', 'c.level_id')
            ->leftJoin('product_level_prices as plp', function ($join) {
                $join->on('plp.product_id', '=', 'p.id')
                    ->on('plp.level_id', '=', 'c.level_id');
            })
            // 特价：仅取进行中且非草稿的特价促销所设置的特价（SQLite 下 ON 只引用前置表 p，
            // 促销生效窗口用 whereIn 子查询折叠进 ON，规避“outer join 表被后续 ON 引用”的限制）
            ->leftJoin('promotion_items as pi', function ($join) use ($now) {
                $join->on('pi.product_id', '=', 'p.id')
                    ->where('pi.special_price', '>', 0)
                    ->whereIn('pi.promotion_id', function ($sub) use ($now) {
                        $sub->select('id')->from('promotions')
                            ->where('type', 'special_price')
                            ->where('status', '!=', 'draft')
                            ->where('start_time', '<=', $now)
                            ->where('end_time', '>=', $now);
                    });
            });

        $query->where('c.is_active', 1)
            ->where('p.is_active', 1);

        // 客户维度
        if ($params['customer_ids']) {
            $query->whereIn('c.id', $params['customer_ids']);
        }
        if ($params['customer_level_ids']) {
            $query->whereIn('c.level_id', $params['customer_level_ids']);
        }
        if (! empty($params['customer_name'])) {
            $query->where('c.name', 'like', '%'.$params['customer_name'].'%');
        }
        // 商品维度
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
        if (! empty($params['product_keyword'])) {
            $kw = '%'.$params['product_keyword'].'%';
            $query->where(function ($q) use ($kw) {
                $q->where('p.name', 'like', $kw)->orWhere('p.code', 'like', $kw);
            });
        }

        return $query->select([
            'c.id as customer_id',
            'c.code as customer_code',
            'c.name as customer_name',
            DB::raw('COALESCE(cl.name, "-") as level_name'),
            'p.id as product_id',
            'p.code as product_code',
            'p.name as product_name',
            'p.spec',
            'p.price_unit_small as unit',
            DB::raw('p.price_small as standard_price'),
            // 等级价：有显式商品×等级价用显式价，否则回落等级默认折扣价（price_small × default_discount），
            // 无等级客户回落 0 → 命中优先级里落到默认价
            DB::raw('CASE WHEN cl.id IS NOT NULL THEN COALESCE(plp.price, ROUND(p.price_small * 1.0 * cl.default_discount / 10, 2)) ELSE 0 END as level_price'),
            DB::raw('COALESCE(pi.special_price, 0) as special_price'),
        ])
            ->orderBy('c.code')
            ->orderBy('p.code');
    }

    /** 促销价格一览：促销方案 × 商品 的折扣/特价对比原价。期间与筛选日期区间取交集。 */
    private function promotionPriceQuery(array $params)
    {
        $query = DB::table('promotions as pm')
            ->join('promotion_items as pi', 'pi.promotion_id', '=', 'pm.id')
            ->leftJoin('products as p', 'p.id', '=', 'pi.product_id')
            ->where('pm.status', '!=', 'draft');

        if ($params['start_date']) {
            $query->where('pm.end_time', '>=', $params['start_date']);
        }
        if ($params['end_date']) {
            $query->where('pm.start_time', '<=', $params['end_date']);
        }
        if ($params['product_ids']) {
            $query->whereIn('pi.product_id', $params['product_ids']);
        }
        if ($params['brand_ids']) {
            $query->whereIn('p.brand_id', $params['brand_ids']);
        }
        if ($params['main_category_ids']) {
            $query->whereIn('p.main_category_id', $params['main_category_ids']);
        }
        if (! empty($params['product_keyword'])) {
            $kw = '%'.$params['product_keyword'].'%';
            $query->where(function ($q) use ($kw) {
                $q->where('p.name', 'like', $kw)->orWhere('p.code', 'like', $kw);
            });
        }

        return $query->select([
            'pm.promotion_no',
            'pm.name as promotion_name',
            'pm.type',
            'pm.start_time',
            'pm.end_time',
            'p.id as product_id',
            'p.code as product_code',
            'p.name as product_name',
            'pi.spec',
            'pi.original_price',
            'pi.discount_rate',
            'pi.special_price',
        ])
            ->orderBy('pm.promotion_no')
            ->orderBy('p.code');
    }

    /** 汇总行：按维度给合计 */
    private function summary(array $params, string $dimension): array
    {
        if ($dimension === 'sales_rank_total') {
            $rows = $this->aggregate($params, $dimension)->get();
            $amount = $rows->sum('amount');
            $cost = $rows->sum('cost_amount');

            return [
                'order_count' => (int) $rows->sum('order_count'),
                'qty' => ReportFilter::num($rows->sum('qty')),
                'amount' => ReportFilter::num($amount),
                'cost_amount' => ReportFilter::num($cost),
                'profit' => ReportFilter::num($amount - $cost),
            ];
        }

        if ($dimension === 'promotion_price_list') {
            return [
                'item_count' => $this->aggregate($params, $dimension)->count(),
            ];
        }

        return [];
    }

    /** 各维度的列定义（JSON 驱动，前端按 type 渲染） */
    private function columns(string $dimension): array
    {
        switch ($dimension) {
            case 'sales_rank_total':
                return [
                    ['prop' => 'rank', 'label' => '排名', 'width' => 80, 'align' => 'center', 'type' => 'rank', 'rank_label' => '名'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'customer_count', 'label' => '客户数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'qty', 'label' => '数量', 'width' => 100, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'amount', 'label' => '销售金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 130, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'profit', 'label' => '毛利', 'width' => 130, 'align' => 'right', 'type' => 'money'],
                ];

            case 'product_price_list':
                return [
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'price_large', 'label' => '大码价', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'price_medium', 'label' => '中码价', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'price_small', 'label' => '小码价', 'width' => 110, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'cost_price', 'label' => '成本价', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 80, 'align' => 'center', 'type' => 'text'],
                ];

            case 'customer_special_price':
                return [
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 160, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'level_name', 'label' => '客户等级', 'width' => 100, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'standard_price', 'label' => '默认价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'level_price', 'label' => '等级价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'special_price', 'label' => '特价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'effective_price', 'label' => '有效价', 'width' => 110, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'price_source', 'label' => '价格来源', 'width' => 100, 'align' => 'center', 'type' => 'price_tag', 'hit_type' => 'rule_hit'],
                ];

            case 'promotion_price_list':
                return [
                    ['prop' => 'promotion_no', 'label' => '促销单号', 'width' => 130, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'promotion_name', 'label' => '促销方案', 'width' => 170, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'type', 'label' => '类型', 'width' => 90, 'align' => 'center', 'type' => 'tag', 'tag_map' => [
                        'discount' => '折扣', 'full_reduction' => '满减', 'buy_gift' => '买赠', 'special_price' => '特价',
                    ]],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'original_price', 'label' => '原价', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'discount_rate', 'label' => '折扣率', 'width' => 90, 'align' => 'right', 'type' => 'rate'],
                    ['prop' => 'special_price', 'label' => '促销价', 'width' => 110, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'start_time', 'label' => '开始时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'end_time', 'label' => '结束时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                ];
        }

        return [];
    }
}
