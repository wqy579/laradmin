<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块五：库存报表族（报表管理 → 库存分析）
 *
 * 8 个维度共用一套筛选条件集，切 tab 只换 dimension：
 *   expiry 商品超期预警 / safety 安全库存预警 / sellable 可销天数 /
 *   change 库存变动 / detail 库存明细 / gain_loss 盘盈盘亏汇总 /
 *   assembly 商品拆装 / analysis 库存分析。
 *
 * 口径与告警阈值（执行文档 §3 E5）：
 *   商品超期预警  商品级口径（库内无批次/生产日期字段，与 C 机库存预警中心一致）：
 *              剩余天数 = expiry_date − 今天，四级分级——
 *              剩余 < −severe_days(默认30) 严重超期深红；≤0 超期红；
 *              ≤ near_days(默认30) 临期橙；其余正常灰。阈值请求可配。
 *   安全库存    现库存 < 安全线红（预警）、= 安全线橙（注意）；
 *              建议补货 = 安全线 × 2 − 现库存（与 C 机口径一致）。
 *   可销天数    现库存 ÷ 近 N 天日均出库（stocks_history change_qty<0，默认 30 天），
 *              <7 天红；日均=0 且有库存视为充足，库存=0 视为断档。
 *   盘盈盘亏    盘盈红、盘亏绿 —— 与「增加=绿」的常规金额语义相反：
 *              库存差异代表账实不符，盘多要查账、盘少要赔，都按风险色处理。
 *   商品拆装    组装=子件出库父件入库，拆分=父件出库子件入库（审核时经
 *              StockService 写流水），本表按单据汇总两族。
 *   库存分析    在金额/占比基础上补三个口径（QA-07）：周转率 = 期间出库 ÷ 现库存
 *              （现库存 0 无基准显示「-」）；超期天数 = 今天 − 有效期至（未生效期为
 *              「未设效期」，不入超期口径）；可销天数同 sellable 维度。
 *
 * 日均销量与 C 机库存预警中心同源（stocks_history 出库流水），保证两处「可销天数」一致。
 *
 * 纯读模块：只读 stocks / stocks_history / 盘点单 / 拆装单，不写任何业务表。
 */
class StockAnalysisController extends Controller
{
    /** 维度 key → 中文名（顺序即 tab 顺序） */
    private const DIMENSIONS = [
        'expiry' => '商品超期预警',
        'safety' => '安全库存预警',
        'sellable' => '可销天数',
        'change' => '库存变动',
        'detail' => '库存明细',
        'gain_loss' => '盘盈盘亏汇总',
        'assembly' => '商品拆装',
        'analysis' => '库存分析',
    ];

    /** 库存变动类型 → 中文（与 StockService::recordHistory 写入值一致） */
    private const CHANGE_TYPES = [
        'stock_in' => '入库',
        'stock_out' => '出库',
        'sale' => '销售出库',
        'sale_freeze' => '销售冻结',
        'sale_unfreeze' => '销售解冻',
        'check_in' => '盘盈',
        'check_out' => '盘亏',
        'adjust_in' => '调整增加',
        'adjust_out' => '调整减少',
        'assembly' => '组装',
        'split' => '拆分',
    ];

    /** 需取全量后 PHP 侧 enrich（分级/占比/拼行）再手工分页的维度 */
    private const MANUAL_DIMENSIONS = ['expiry', 'safety', 'sellable', 'analysis', 'assembly'];

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        // 超期/安全/可销/分析/拆装需 PHP 侧分级、算占比、拼 union 行 → 手工分页
        if (in_array($dimension, self::MANUAL_DIMENSIONS, true)) {
            $rows = $this->enrich($this->aggregate($request, $params, $dimension)->get(), $dimension, $request);
            $data = ReportFilter::page($this->paginateRows($rows, $request, $params));
            $summary = $this->summary($rows, $dimension);
        } else {
            $query = $this->aggregate($request, $params, $dimension);
            $data = ReportFilter::page($query->paginate($params['page_size'], ['*'], 'page', $params['page']));
            $summary = $this->summary($query->get(), $dimension);
        }

        $data['dimension'] = $dimension;
        $data['dimensions'] = self::DIMENSIONS;
        $data['columns'] = $this->columns($dimension);
        $data['summary'] = $summary;

        return $this->success($data);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        $rows = $this->enrich(
            $this->aggregate($request, $params, $dimension)->limit(50000)->get(),
            $dimension,
            $request
        )->all();

        return ReportFilter::excelResponse(
            ReportFilter::excel('库存分析 - '.(self::DIMENSIONS[$dimension] ?? ''), $this->columns($dimension), $rows),
            'stock_analysis_'.$dimension.'.xls'
        );
    }

    private function dimension(array $params): string
    {
        $dimension = (string) ($params['dimension'] ?? 'expiry');

        return array_key_exists($dimension, self::DIMENSIONS) ? $dimension : 'expiry';
    }

    /** 维度调度：统一返回查询构造器 */
    private function aggregate(Request $request, array $params, string $dimension)
    {
        return match ($dimension) {
            'expiry' => $this->expiryQuery($params),
            'safety' => $this->safetyQuery($params),
            'sellable' => $this->sellableQuery($params),
            'change' => $this->changeQuery($params),
            'detail' => $this->detailQuery($params),
            'gain_loss' => $this->gainLossQuery($params),
            'assembly' => $this->assemblyRows($params),
            'analysis' => $this->analysisQuery($params),
            default => $this->expiryQuery($params),
        };
    }

    /** 商品公共筛选（品牌/主副分类/商品多选/关键字）——商品维度查询共用 */
    private function applyProductFilters($query, array $params): void
    {
        if (! empty($params['brand_ids'])) {
            $query->whereIn('p.brand_id', $params['brand_ids']);
        }
        if (! empty($params['main_category_ids'])) {
            $query->whereIn('p.main_category_id', $params['main_category_ids']);
        }
        if (! empty($params['sub_category_ids'])) {
            $query->whereIn('p.sub_category_id', $params['sub_category_ids']);
        }
        if (! empty($params['product_ids'])) {
            $query->whereIn('p.id', $params['product_ids']);
        }
        $kw = trim((string) ($params['product_keyword'] ?? ''));
        if ($kw !== '') {
            $query->where(function ($w) use ($kw) {
                $w->where('p.name', 'like', '%'.$kw.'%')->orWhere('p.code', 'like', '%'.$kw.'%');
            });
        }
    }

    // ================================================================
    // 查询构造（8 个维度）
    // ================================================================

    /**
     * 商品超期预警：商品级，剩余天数按驱动方言计算
     * （SQLite julianday / MySQL DATEDIFF，与 C 机 StockWarningController 同法）。
     */
    private function expiryQuery(array $params)
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $today = now()->toDateString();
        $diffSql = $isSqlite
            ? "CAST(julianday(p.expiry_date) - julianday('{$today}') AS INTEGER)"
            : 'DATEDIFF(p.expiry_date, CURDATE())';

        $query = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->leftJoin('stocks as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', 1)
            ->whereNotNull('p.expiry_date');

        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('s.warehouse_id', $params['warehouse_ids']);
        }
        $this->applyProductFilters($query, $params);

        return $query
            ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'p.expiry_date', 'p.shelf_life_days', 'b.name', 'mc.name')
            ->selectRaw("
                p.id as product_id, p.code as product_code, p.name as product_name,
                p.spec, p.price_unit_small as unit, COALESCE(b.name, '') as brand_name,
                COALESCE(mc.name, '') as category_name,
                p.expiry_date, p.shelf_life_days, {$diffSql} as remaining_days,
                COALESCE(SUM(s.quantity), 0) as current_stock
            ")
            ->when(empty($params['include_zero']), fn ($q) => $q->havingRaw('COALESCE(SUM(s.quantity), 0) > 0'))
            ->orderBy('remaining_days');
    }

    /** 安全库存预警：现库存 vs products.safety_stock */
    private function safetyQuery(array $params)
    {
        $query = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->leftJoin('stocks as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', 1)
            ->where('p.safety_stock', '>', 0);

        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('s.warehouse_id', $params['warehouse_ids']);
        }
        $this->applyProductFilters($query, $params);

        return $query
            ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'p.safety_stock', 'b.name', 'mc.name')
            ->selectRaw("
                p.id as product_id, p.code as product_code, p.name as product_name,
                p.spec, p.price_unit_small as unit, COALESCE(b.name, '') as brand_name,
                COALESCE(mc.name, '') as category_name,
                p.safety_stock, COALESCE(SUM(s.quantity), 0) as current_stock
            ")
            ->orderByRaw('COALESCE(SUM(s.quantity), 0) - p.safety_stock');
    }

    /** 可销天数：现库存 ÷ 近 N 天日均出库（出库流水在 enrich 里批量取） */
    private function sellableQuery(array $params)
    {
        $query = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('stocks as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', 1);

        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('s.warehouse_id', $params['warehouse_ids']);
        }
        $this->applyProductFilters($query, $params);

        return $query
            ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'b.name')
            ->selectRaw("
                p.id as product_id, p.code as product_code, p.name as product_name,
                p.spec, p.price_unit_small as unit, COALESCE(b.name, '') as brand_name,
                COALESCE(SUM(s.quantity), 0) as current_stock
            ")
            ->when(empty($params['include_zero']), fn ($q) => $q->havingRaw('COALESCE(SUM(s.quantity), 0) > 0'))
            ->orderBy('p.code');
    }

    /** 库存变动明细：stocks_history 流水（DB 分页） */
    private function changeQuery(array $params)
    {
        $query = DB::table('stocks_history as sh')
            ->leftJoin('products as p', 'p.id', '=', 'sh.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sh.warehouse_id');

        if (! empty($params['start_date'])) {
            $query->where('sh.created_at', '>=', $params['start_date'].' 00:00:00');
        }
        if (! empty($params['end_date'])) {
            $query->where('sh.created_at', '<=', $params['end_date'].' 23:59:59');
        }
        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('sh.warehouse_id', $params['warehouse_ids']);
        }
        if (! empty($params['product_ids'])) {
            $query->whereIn('sh.product_id', $params['product_ids']);
        }
        $kw = trim((string) ($params['product_keyword'] ?? ''));
        if ($kw !== '') {
            $query->where(function ($w) use ($kw) {
                $w->where('p.name', 'like', '%'.$kw.'%')->orWhere('p.code', 'like', '%'.$kw.'%');
            });
        }

        // 变动类型中文在 SQL 侧映射：分页与导出共用同一份取值，不必两边 enrich
        $label = collect(self::CHANGE_TYPES)
            ->map(fn ($text, $type) => "when '{$type}' then '{$text}'")
            ->implode(' ');

        return $query
            ->selectRaw('
                sh.id, sh.product_id, p.code as product_code, p.name as product_name, p.spec,
                sh.warehouse_id, COALESCE(w.name, \'\') as warehouse_name,
                sh.change_type, sh.change_qty, sh.before_qty, sh.after_qty,
                sh.related_type, sh.related_id, sh.remark, sh.created_at,
                case sh.change_type '.$label.' else sh.change_type end as change_label
            ')
            ->orderByDesc('sh.created_at')->orderByDesc('sh.id');
    }

    /** 库存明细：仓库 × 商品结存快照（成本/零售金额，换算比同 StockReportController） */
    private function detailQuery(array $params)
    {
        $conversion = "CAST(COALESCE(NULLIF(p.unit_conversion, ''), '1') AS DECIMAL(20,6))";
        $lineCostPrice = 'COALESCE(NULLIF(s.cost_price, 0), p.cost_price, 0)';
        $retailPrice = 'COALESCE(p.price_small, p.price_large, 0)';

        $query = DB::table('stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id');

        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('s.warehouse_id', $params['warehouse_ids']);
        }
        if (! empty($params['brand_ids'])) {
            $query->whereIn('p.brand_id', $params['brand_ids']);
        }
        if (! empty($params['main_category_ids'])) {
            $query->whereIn('p.main_category_id', $params['main_category_ids']);
        }
        if (! empty($params['sub_category_ids'])) {
            $query->whereIn('p.sub_category_id', $params['sub_category_ids']);
        }
        if (! empty($params['product_ids'])) {
            $query->whereIn('p.id', $params['product_ids']);
        }
        $kw = trim((string) ($params['product_keyword'] ?? ''));
        if ($kw !== '') {
            $query->where(function ($w) use ($kw) {
                $w->where('p.name', 'like', '%'.$kw.'%')->orWhere('p.code', 'like', '%'.$kw.'%');
            });
        }

        return $query
            ->when(empty($params['include_zero']), fn ($q) => $q->where('s.quantity', '!=', 0))
            ->selectRaw("
                p.id as product_id, p.code as product_code, p.name as product_name, p.spec,
                p.price_unit_small as unit, p.price_unit as unit_large, {$conversion} as conversion_rate,
                COALESCE(w.code, '') as warehouse_code, COALESCE(w.name, '') as warehouse_name,
                COALESCE(b.name, '') as brand_name, COALESCE(mc.name, '') as category_name,
                s.quantity, s.frozen_qty, s.quantity / {$conversion} as qty_large,
                {$lineCostPrice} as cost_price,
                ROUND(s.quantity * {$lineCostPrice}, 2) as cost_amount,
                {$retailPrice} as retail_price,
                ROUND(s.quantity * {$retailPrice}, 2) as retail_amount
            ")
            ->orderBy('w.code')->orderBy('p.code');
    }

    /** 盘盈盘亏汇总：已审核盘点单 × 商品 汇总盈亏数量与金额 */
    private function gainLossQuery(array $params)
    {
        $query = DB::table('stock_check_items as ci')
            ->join('stock_checks as c', 'c.id', '=', 'ci.check_id')
            ->leftJoin('products as p', 'p.id', '=', 'ci.product_id')
            ->where('c.status', 'approved');

        if (! empty($params['start_date'])) {
            $query->where('c.check_date', '>=', $params['start_date']);
        }
        if (! empty($params['end_date'])) {
            $query->where('c.check_date', '<=', $params['end_date']);
        }
        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('c.warehouse_id', $params['warehouse_ids']);
        }
        if (! empty($params['brand_ids'])) {
            $query->whereIn('p.brand_id', $params['brand_ids']);
        }
        if (! empty($params['main_category_ids'])) {
            $query->whereIn('p.main_category_id', $params['main_category_ids']);
        }
        if (! empty($params['sub_category_ids'])) {
            $query->whereIn('p.sub_category_id', $params['sub_category_ids']);
        }
        if (! empty($params['product_ids'])) {
            $query->whereIn('ci.product_id', $params['product_ids']);
        }
        $kw = trim((string) ($params['product_keyword'] ?? ''));
        if ($kw !== '') {
            $query->where(function ($w) use ($kw) {
                $w->where('ci.product_name', 'like', '%'.$kw.'%')->orWhere('ci.product_code', 'like', '%'.$kw.'%');
            });
        }

        return $query
            ->groupBy('ci.product_id', 'ci.product_code', 'ci.product_name', 'ci.spec', 'ci.unit')
            ->selectRaw('
                ci.product_id, ci.product_code, ci.product_name, ci.spec, ci.unit,
                COUNT(DISTINCT c.id) as check_count,
                SUM(CASE WHEN ci.diff_qty > 0 THEN ci.diff_qty ELSE 0 END) as gain_qty,
                SUM(CASE WHEN ci.diff_qty < 0 THEN ABS(ci.diff_qty) ELSE 0 END) as loss_qty,
                SUM(CASE WHEN ci.diff_qty > 0 THEN ci.diff_amount ELSE 0 END) as gain_amount,
                SUM(CASE WHEN ci.diff_qty < 0 THEN ABS(ci.diff_amount) ELSE 0 END) as loss_amount,
                SUM(ci.diff_qty) as net_qty,
                SUM(ci.diff_amount) as net_amount
            ')
            ->orderByDesc('net_qty');
    }

    /** 商品拆装：组装单 + 拆分单（已审核）union，按单据一行 */
    private function assemblyRows(array $params)
    {
        $assembly = DB::table('assembly_orders as ao')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ao.warehouse_id')
            ->where('ao.status', 'approved')
            ->when(! empty($params['start_date']), fn ($q) => $q->where('ao.assembly_date', '>=', $params['start_date']))
            ->when(! empty($params['end_date']), fn ($q) => $q->where('ao.assembly_date', '<=', $params['end_date']))
            ->when(! empty($params['warehouse_ids']), fn ($q) => $q->whereIn('ao.warehouse_id', $params['warehouse_ids']))
            ->when(! empty($params['product_ids']), fn ($q) => $q->whereIn('ao.parent_product_id', $params['product_ids']))
            ->when(! empty($params['product_keyword']), function ($q) use ($params) {
                $kw = trim((string) $params['product_keyword']);
                $q->where(function ($w) use ($kw) {
                    $w->where('ao.parent_product_name', 'like', '%'.$kw.'%')
                        ->orWhere('ao.parent_product_code', 'like', '%'.$kw.'%');
                });
            })
            ->selectRaw("
                '组装' as biz_type, ao.id as order_id, ao.assembly_no as order_no,
                ao.assembly_date as biz_date, ao.parent_product_code as product_code,
                ao.parent_product_name as product_name, ao.quantity, ao.total_cost,
                COALESCE(w.name, '') as warehouse_name
            ");

        $split = DB::table('split_orders as sp')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sp.warehouse_id')
            ->where('sp.status', 'approved')
            ->when(! empty($params['start_date']), fn ($q) => $q->where('sp.split_date', '>=', $params['start_date']))
            ->when(! empty($params['end_date']), fn ($q) => $q->where('sp.split_date', '<=', $params['end_date']))
            ->when(! empty($params['warehouse_ids']), fn ($q) => $q->whereIn('sp.warehouse_id', $params['warehouse_ids']))
            ->when(! empty($params['product_ids']), fn ($q) => $q->whereIn('sp.parent_product_id', $params['product_ids']))
            ->when(! empty($params['product_keyword']), function ($q) use ($params) {
                $kw = trim((string) $params['product_keyword']);
                $q->where(function ($w) use ($kw) {
                    $w->where('sp.parent_product_name', 'like', '%'.$kw.'%')
                        ->orWhere('sp.parent_product_code', 'like', '%'.$kw.'%');
                });
            })
            ->selectRaw("
                '拆分' as biz_type, sp.id as order_id, sp.split_no as order_no,
                sp.split_date as biz_date, sp.parent_product_code as product_code,
                sp.parent_product_name as product_name, sp.quantity, sp.total_cost,
                COALESCE(w.name, '') as warehouse_name
            ");

        // union 上的 orderBy 各家方言行为不一，排序放到 enrichAssembly（PHP 侧）
        return $assembly->unionAll($split);
    }

    /** 库存分析：商品级现库存/成本额/零售额（出库流速与占比在 enrich 阶段补） */
    private function analysisQuery(array $params)
    {
        $query = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->leftJoin('stocks as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', 1);

        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('s.warehouse_id', $params['warehouse_ids']);
        }
        $this->applyProductFilters($query, $params);

        // 超期天数 = 今天 − 有效期至（正数即已超期），与 expiry 维度同用跨方言差值；
        // 未设效期置 NULL，未到期归 0。不用 GREATEST（sqlite 无此函数），CASE 两方言通用。
        $today = now()->toDateString();
        $overdueExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(julianday('{$today}') - julianday(p.expiry_date) AS INTEGER)"
            : 'DATEDIFF(CURDATE(), p.expiry_date)';
        $overdueSql = "CASE WHEN p.expiry_date IS NULL THEN NULL WHEN ({$overdueExpr}) > 0 THEN ({$overdueExpr}) ELSE 0 END";

        return $query
            ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'b.name', 'mc.name', 'p.cost_price', 'p.price_unit', 'p.expiry_date')
            ->selectRaw("
                p.id as product_id, p.code as product_code, p.name as product_name,
                p.spec, p.price_unit_small as unit, COALESCE(b.name, '') as brand_name,
                COALESCE(mc.name, '') as category_name,
                COALESCE(SUM(s.quantity), 0) as current_stock,
                COALESCE(p.cost_price, 0) as cost_price,
                ROUND(COALESCE(SUM(s.quantity), 0) * COALESCE(p.cost_price, 0), 2) as cost_amount,
                ROUND(COALESCE(SUM(s.quantity), 0) * COALESCE(p.price_small, p.price_large, 0), 2) as retail_amount,
                {$overdueSql} as overdue_days
            ")
            ->when(empty($params['include_zero']), fn ($q) => $q->havingRaw('COALESCE(SUM(s.quantity), 0) > 0'))
            ->orderByDesc('retail_amount');
    }

    /** 近 N 天日均出库量（出库流水 change_qty<0），key=product_id —— 与 C 机口径一致 */
    private function dailySalesMap(array $productIds, int $window): array
    {
        if (empty($productIds)) {
            return [];
        }
        $since = now()->subDays(max(1, $window))->startOfDay();

        return DB::table('stocks_history')
            ->whereIn('product_id', $productIds)
            ->where('change_qty', '<', 0)
            ->where('created_at', '>=', $since)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(ABS(change_qty)) as out_qty')
            ->pluck('out_qty', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    // ================================================================
    // PHP 侧 enrich（分级 / 占比 / 补字段）
    // ================================================================

    private function enrich($rows, string $dimension, Request $request)
    {
        $rows = $rows instanceof Collection ? collect($rows) : collect($rows);
        if ($rows->isEmpty()) {
            return $rows;
        }

        return match ($dimension) {
            'expiry' => $this->enrichExpiry($rows, $request),
            'safety' => $this->enrichSafety($rows, $request),
            'sellable' => $this->enrichSellable($rows, $request),
            'assembly' => $this->enrichAssembly($rows),
            'analysis' => $this->enrichAnalysis($rows, $request),
            default => $rows,
        };
    }

    /** 超期四级：严重超期 / 超期 / 临期 / 正常，阈值 near_days、severe_days 可配 */
    private function enrichExpiry($rows, Request $request)
    {
        $nearDays = max(0, (int) $request->input('near_days', 30));
        $severeDays = max(0, (int) $request->input('severe_days', 30));

        return $rows->map(function ($r) use ($nearDays, $severeDays) {
            $remaining = (int) ($r->remaining_days ?? 0);
            $r->level = match (true) {
                $remaining < -$severeDays => '严重超期',
                $remaining <= 0 => '超期',
                $remaining <= $nearDays => '临期',
                default => '正常',
            };

            return $r;
        })->sortBy('remaining_days')->values();
    }

    /** 安全库存：预警（低于）/注意（等于）/正常（高于）；建议补货 = 安全线×2 − 现库存 */
    private function enrichSafety($rows, Request $request)
    {
        $onlyWarning = (int) $request->input('only_warning', 0) === 1;

        return $rows->map(function ($r) {
            $cur = (float) ($r->current_stock ?? 0);
            $safety = (float) ($r->safety_stock ?? 0);
            $r->status = match (true) {
                $cur < $safety => '预警',
                $cur == $safety => '注意',
                default => '正常',
            };
            $r->gap = ReportFilter::num($cur - $safety);
            $r->suggest_qty = (int) max(0, $safety * 2 - $cur);

            return $r;
        })->when($onlyWarning, fn ($c) => $c->where('status', '!=', '正常'))
            ->sortBy('gap')->values();
    }

    /**
     * 可销天数 = 现库存 ÷ 日均出库；<7 天告急红。
     * 排序按可销天数升序（告急在前），断档/无销量（null）沉底。
     */
    private function enrichSellable($rows, Request $request)
    {
        $window = min(365, max(7, (int) $request->input('window', 30)));
        $ids = $rows->pluck('product_id')->all();
        $outMap = $this->dailySalesMap($ids, $window);

        return $rows->map(function ($r) use ($outMap, $window) {
            $cur = (float) ($r->current_stock ?? 0);
            $out = (float) ($outMap[$r->product_id] ?? 0);
            $daily = ReportFilter::num($out / max(1, $window));

            $r->out_qty = (int) $out;
            $r->daily_out = $daily;
            $r->sellable_days = $daily > 0 ? ReportFilter::num($cur / $daily) : null;
            $r->level = match (true) {
                $cur <= 0 => '断档',
                $daily <= 0 => '充足',
                $cur / $daily < 7 => '告急',
                default => '正常',
            };

            return $r;
        })->sortBy([
            [fn ($r) => $r->sellable_days === null ? 1 : 0, 'asc'],
            ['sellable_days', 'asc'],
        ])->values();
    }

    /** 拆装单：union 结果按日期倒序（SQL 端 orderBy 方言行为不一，这里收敛） */
    private function enrichAssembly($rows)
    {
        return $rows->sortByDesc(fn ($r) => $r->biz_date.'#'.str_pad((string) $r->order_id, 10, '0', STR_PAD_LEFT))->values();
    }

    /** 库存分析：金额占比 + 近 N 天出库流速（与可销天数同源） */
    private function enrichAnalysis($rows, Request $request)
    {
        $window = min(365, max(7, (int) $request->input('window', 30)));
        $ids = $rows->pluck('product_id')->all();
        $outMap = $this->dailySalesMap($ids, $window);

        $totalCost = (float) $rows->sum('cost_amount');
        $totalRetail = (float) $rows->sum('retail_amount');

        return $rows->map(function ($r) use ($outMap, $window, $totalCost, $totalRetail) {
            $out = (float) ($outMap[$r->product_id] ?? 0);
            $daily = ReportFilter::num($out / max(1, $window));
            $cur = (float) ($r->current_stock ?? 0);

            $r->out_qty = (int) $out;
            $r->daily_out = $daily;
            $r->sellable_days = $daily > 0 ? ReportFilter::num($cur / $daily) : null;
            $r->cost_share = $totalCost > 0 ? ReportFilter::num((float) $r->cost_amount / $totalCost * 100) : 0;
            $r->retail_share = $totalRetail > 0 ? ReportFilter::num((float) $r->retail_amount / $totalRetail * 100) : 0;

            // 周转率（次/窗口）= 期间出库 ÷ 现库存；现库存 0 无周转基准，置 null 显示为「-」
            $r->turnover_rate = $cur > 0 ? ReportFilter::num($out / $cur) : null;

            $overdue = ($r->overdue_days ?? null) === null ? null : (int) $r->overdue_days;
            $r->overdue_days = $overdue;
            $r->overdue_level = $overdue === null ? '未设效期' : ($overdue > 0 ? '超期' : '正常');

            return $r;
        })->values();
    }

    // ================================================================
    // 分页 / 合计
    // ================================================================

    /** 手工分页：行已在 enrich 阶段定型（含过滤与排序），返回分页器喂给 ReportFilter::page */
    private function paginateRows($rows, Request $request, array $params): LengthAwarePaginator
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = min(500, max(1, (int) ($params['page_size'] ?? 20)));

        return new LengthAwarePaginator(
            $rows->forPage($page, $pageSize)->values(),
            $rows->count(),
            $pageSize,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /**
     * 合计：对全量行（enrich 之后）求和。
     * 键名与各维度列 prop 对齐，前端 ReportTable 自动挑可合计列展示。
     */
    private function summary($rows, string $dimension): array
    {
        $rows = collect($rows);

        switch ($dimension) {
            // 合计键名必须是该维度真实存在的列 prop，前端 summaryKeys 才会展示
            case 'expiry':
                return [
                    'current_stock' => ReportFilter::num($rows->sum('current_stock')),
                ];
            case 'safety':
                return [
                    'current_stock' => ReportFilter::num($rows->sum('current_stock')),
                    'suggest_qty' => (int) $rows->sum('suggest_qty'),
                ];
            case 'sellable':
                return [
                    'current_stock' => ReportFilter::num($rows->sum('current_stock')),
                    'out_qty' => (int) $rows->sum('out_qty'),
                ];
            case 'change':
                // 正负相抵后的净变动
                return [
                    'change_qty' => ReportFilter::num($rows->sum('change_qty')),
                ];
            case 'detail':
                return [
                    'quantity' => ReportFilter::num($rows->sum('quantity')),
                    'cost_amount' => ReportFilter::num($rows->sum('cost_amount')),
                    'retail_amount' => ReportFilter::num($rows->sum('retail_amount')),
                ];
            case 'gain_loss':
                return [
                    'gain_qty' => ReportFilter::num($rows->sum('gain_qty')),
                    'loss_qty' => ReportFilter::num($rows->sum('loss_qty')),
                    'net_qty' => ReportFilter::num($rows->sum('net_qty')),
                    'gain_amount' => ReportFilter::num($rows->sum('gain_amount')),
                    'loss_amount' => ReportFilter::num($rows->sum('loss_amount')),
                    'net_amount' => ReportFilter::num($rows->sum('net_amount')),
                ];
            case 'assembly':
                return [
                    'quantity' => ReportFilter::num($rows->sum('quantity')),
                    'total_cost' => ReportFilter::num($rows->sum('total_cost')),
                ];
            case 'analysis':
                return [
                    'current_stock' => ReportFilter::num($rows->sum('current_stock')),
                    'cost_amount' => ReportFilter::num($rows->sum('cost_amount')),
                    'retail_amount' => ReportFilter::num($rows->sum('retail_amount')),
                    'out_qty' => (int) $rows->sum('out_qty'),
                ];
        }

        return [];
    }

    // ================================================================
    // 列定义（JSON 驱动，前端 ReportTable 按 type 渲染）
    // ================================================================

    private function columns(string $dimension): array
    {
        $productCols = [
            ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
            ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
        ];
        $attrCols = [
            ['prop' => 'brand_name', 'label' => '品牌', 'width' => 110, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'category_name', 'label' => '分类', 'width' => 110, 'align' => 'left', 'type' => 'text'],
        ];

        switch ($dimension) {
            case 'expiry':
                return array_merge($productCols, $attrCols, [
                    ['prop' => 'expiry_date', 'label' => '有效期至', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'shelf_life_days', 'label' => '保质期(天)', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'remaining_days', 'label' => '剩余天数', 'width' => 100, 'align' => 'right', 'type' => 'int', 'bold' => true, 'negative_red' => true],
                    ['prop' => 'level', 'label' => '预警级别', 'width' => 100, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '严重超期' => ['label' => '严重超期', 'color' => 'severe'],
                        '超期' => ['label' => '超期', 'color' => 'danger'],
                        '临期' => ['label' => '临期', 'color' => 'warning'],
                        '正常' => ['label' => '正常', 'color' => 'info'],
                    ]],
                    ['prop' => 'current_stock', 'label' => '现库存', 'width' => 100, 'align' => 'right', 'type' => 'int', 'zero_gray' => true],
                ]);

            case 'safety':
                return array_merge($productCols, $attrCols, [
                    ['prop' => 'safety_stock', 'label' => '安全库存', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'current_stock', 'label' => '现库存', 'width' => 100, 'align' => 'right', 'type' => 'int', 'bold' => true],
                    ['prop' => 'gap', 'label' => '差额(现-安全)', 'width' => 110, 'align' => 'right', 'type' => 'number', 'negative_red' => true],
                    ['prop' => 'status', 'label' => '状态', 'width' => 90, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '预警' => ['label' => '预警', 'color' => 'danger'],
                        '注意' => ['label' => '注意', 'color' => 'warning'],
                        '正常' => ['label' => '正常', 'color' => 'success'],
                    ]],
                    ['prop' => 'suggest_qty', 'label' => '建议补货', 'width' => 100, 'align' => 'right', 'type' => 'int', 'zero_gray' => true],
                ]);

            case 'sellable':
                return array_merge($productCols, [
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'current_stock', 'label' => '现库存', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'out_qty', 'label' => '期间出库', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'daily_out', 'label' => '日均出库', 'width' => 100, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'sellable_days', 'label' => '可销天数', 'width' => 100, 'align' => 'right', 'type' => 'number', 'bold' => true, 'danger_below' => 7],
                    ['prop' => 'level', 'label' => '状态', 'width' => 90, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '断档' => ['label' => '断档', 'color' => 'danger'],
                        '告急' => ['label' => '告急', 'color' => 'danger'],
                        '正常' => ['label' => '正常', 'color' => 'success'],
                        '充足' => ['label' => '充足', 'color' => 'info'],
                    ]],
                ]);

            case 'change':
                return [
                    ['prop' => 'created_at', 'label' => '变动时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'change_label', 'label' => '变动类型', 'width' => 100, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '入库' => ['label' => '入库', 'color' => 'success'],
                        '出库' => ['label' => '出库', 'color' => 'warning'],
                        '销售出库' => ['label' => '销售出库', 'color' => 'warning'],
                        '销售冻结' => ['label' => '销售冻结', 'color' => 'info'],
                        '销售解冻' => ['label' => '销售解冻', 'color' => 'info'],
                        '盘盈' => ['label' => '盘盈', 'color' => 'danger'],
                        '盘亏' => ['label' => '盘亏', 'color' => 'loss'],
                        '调整增加' => ['label' => '调整增加', 'color' => 'success'],
                        '调整减少' => ['label' => '调整减少', 'color' => 'warning'],
                        '组装' => ['label' => '组装', 'color' => 'success'],
                        '拆分' => ['label' => '拆分', 'color' => 'info'],
                    ]],
                    ['prop' => 'change_qty', 'label' => '变动数量', 'width' => 100, 'align' => 'right', 'type' => 'int', 'bold' => true, 'sign_color' => true],
                    ['prop' => 'before_qty', 'label' => '变动前', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'after_qty', 'label' => '变动后', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'related_type', 'label' => '关联单据', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'remark', 'label' => '备注', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                ];

            case 'detail':
                return [
                    ['prop' => 'warehouse_code', 'label' => '仓库编码', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'warehouse_name', 'label' => '仓库名称', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'category_name', 'label' => '分类', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'quantity', 'label' => '数量', 'width' => 100, 'align' => 'right', 'type' => 'int', 'bold' => true, 'zero_gray' => true],
                    ['prop' => 'frozen_qty', 'label' => '冻结数量', 'width' => 90, 'align' => 'right', 'type' => 'int', 'zero_gray' => true],
                    ['prop' => 'qty_large', 'label' => '大单位数量', 'width' => 100, 'align' => 'right', 'type' => 'number', 'zero_gray' => true],
                    ['prop' => 'cost_price', 'label' => '成本单价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'retail_price', 'label' => '零售单价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'retail_amount', 'label' => '零售金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                ];

            case 'gain_loss':
                return array_merge([
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'check_count', 'label' => '盘点单数', 'width' => 95, 'align' => 'right', 'type' => 'int'],
                    // 盘盈红 / 盘亏绿：账实不符都是风险，与「增加=绿」的常规语义相反（E5 规范）
                    ['prop' => 'gain_qty', 'label' => '盘盈数量', 'width' => 100, 'align' => 'right', 'type' => 'number', 'cell_class' => 'rp-gain', 'zero_gray' => true],
                    ['prop' => 'loss_qty', 'label' => '盘亏数量', 'width' => 100, 'align' => 'right', 'type' => 'number', 'cell_class' => 'rp-loss', 'zero_gray' => true],
                    ['prop' => 'gain_amount', 'label' => '盘盈金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'cell_class' => 'rp-gain', 'zero_gray' => true],
                    ['prop' => 'loss_amount', 'label' => '盘亏金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'cell_class' => 'rp-loss', 'zero_gray' => true],
                    ['prop' => 'net_qty', 'label' => '净差异数量', 'width' => 110, 'align' => 'right', 'type' => 'number', 'sign_color' => true],
                    ['prop' => 'net_amount', 'label' => '净差异金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
                ]);

            case 'assembly':
                return [
                    ['prop' => 'biz_type', 'label' => '类型', 'width' => 80, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '组装' => ['label' => '组装', 'color' => 'success'],
                        '拆分' => ['label' => '拆分', 'color' => 'warning'],
                    ]],
                    ['prop' => 'order_no', 'label' => '单据号', 'width' => 160, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'biz_date', 'label' => '日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '主商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '主商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'quantity', 'label' => '数量', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'total_cost', 'label' => '成本金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                ];

            case 'analysis':
                return array_merge($productCols, $attrCols, [
                    ['prop' => 'current_stock', 'label' => '现库存', 'width' => 100, 'align' => 'right', 'type' => 'int', 'zero_gray' => true],
                    ['prop' => 'cost_price', 'label' => '成本单价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'cost_share', 'label' => '成本占比', 'width' => 100, 'align' => 'right', 'type' => 'percent'],
                    ['prop' => 'retail_amount', 'label' => '零售金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'retail_share', 'label' => '零售占比', 'width' => 100, 'align' => 'right', 'type' => 'percent'],
                    ['prop' => 'out_qty', 'label' => '期间出库', 'width' => 100, 'align' => 'right', 'type' => 'int', 'zero_gray' => true],
                    ['prop' => 'daily_out', 'label' => '日均出库', 'width' => 100, 'align' => 'right', 'type' => 'number', 'zero_gray' => true],
                    ['prop' => 'sellable_days', 'label' => '可销天数', 'width' => 100, 'align' => 'right', 'type' => 'number', 'danger_below' => 7],
                    ['prop' => 'turnover_rate', 'label' => '周转率(次)', 'width' => 100, 'align' => 'right', 'type' => 'number', 'zero_gray' => true],
                    ['prop' => 'overdue_days', 'label' => '超期天数', 'width' => 100, 'align' => 'right', 'type' => 'int', 'sign_color' => true, 'zero_gray' => true],
                    ['prop' => 'overdue_level', 'label' => '效期状态', 'width' => 100, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '超期' => ['label' => '超期', 'color' => 'danger'],
                        '正常' => ['label' => '正常', 'color' => 'info'],
                        '未设效期' => ['label' => '未设效期', 'color' => 'info'],
                    ]],
                ]);
        }

        return [];
    }
}
