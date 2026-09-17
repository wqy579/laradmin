<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 库存核对与库存监控
 *
 * 两个入口共用同一套数据：`stocks` 当前库存 + `stock_snapshots` 每日快照
 * （见 StockSnapshotService，惰性触发）。核对看「今日 vs 昨日」的商品级明细，
 * 监控看「最新快照 vs 上一快照」的仓库/趋势级对比。
 */
class StockCheckController extends Controller
{
    /**
     * 库存核对总表
     *
     * 数据源：stocks × products × warehouses + 昨日快照(stock_snapshots) + 今日出入库(已审核单据)
     * 筛选：主分类 / 副分类 / 仓库 / 关键词 / 库存状态
     */
    public function index(Request $request)
    {
        try {
            $mainCategoryId = $request->integer('main_category_id');
            $subCategoryId  = $request->integer('sub_category_id');
            $warehouseId    = $request->integer('warehouse_id');
            $keyword        = trim((string) $request->input('keyword', ''));
            $stockFilter    = (string) $request->input('stock_filter', '');
            $page           = max(1, $request->integer('page', 1));
            $pageSize       = min(500, max(10, $request->integer('page_size', 30)));

            $today     = now()->toDateString();
            $yesterday = now()->subDay()->toDateString();

            // 昨日快照
            $yesterdaySnap = DB::table('stock_snapshots')
                ->select('product_id', 'warehouse_id', 'quantity')
                ->where('snapshot_date', $yesterday);

            // 今日已审核入库
            $todayIn = DB::table('stock_in_items as sii')
                ->join('stock_ins as si', 'si.id', '=', 'sii.stock_in_id')
                ->select('sii.product_id', 'si.warehouse_id', DB::raw('SUM(sii.quantity) as total'))
                ->where('si.status', 'approved')
                ->whereDate('si.stock_date', $today)
                ->groupBy('sii.product_id', 'si.warehouse_id');

            // 今日已审核出库
            $todayOut = DB::table('stock_out_items as soi')
                ->join('stock_outs as so', 'so.id', '=', 'soi.stock_out_id')
                ->select('soi.product_id', 'so.warehouse_id', DB::raw('SUM(soi.quantity) as total'))
                ->where('so.status', 'approved')
                ->whereDate('so.stock_date', $today)
                ->groupBy('soi.product_id', 'so.warehouse_id');

            $query = DB::table('stocks as s')
                ->join('products as p', 's.product_id', '=', 'p.id')
                ->leftJoin('warehouses as w', 's.warehouse_id', '=', 'w.id')
                ->leftJoin('product_categories as c1', 'p.main_category_id', '=', 'c1.id')
                ->leftJoin('product_categories as c2', 'p.sub_category_id', '=', 'c2.id')
                ->leftJoinSub($yesterdaySnap, 'ys', function ($join) {
                    $join->on('ys.product_id', '=', 's.product_id')
                         ->on('ys.warehouse_id', '=', 's.warehouse_id');
                })
                ->leftJoinSub($todayIn, 'tin', function ($join) {
                    $join->on('tin.product_id', '=', 's.product_id')
                         ->on('tin.warehouse_id', '=', 's.warehouse_id');
                })
                ->leftJoinSub($todayOut, 'tout', function ($join) {
                    $join->on('tout.product_id', '=', 's.product_id')
                         ->on('tout.warehouse_id', '=', 's.warehouse_id');
                })
                ->select(
                    'p.id as product_id',
                    'p.name',
                    'p.spec',
                    'p.code',
                    'p.barcode_small',
                    'p.unit_conversion',
                    'p.price_small',
                    DB::raw('COALESCE(c1.name, \'\') as main_category'),
                    DB::raw('COALESCE(c2.name, \'\') as sub_category'),
                    's.warehouse_id',
                    'w.name as warehouse_name',
                    's.quantity',
                    's.frozen_qty',
                    's.total_amount',
                    DB::raw('COALESCE(ys.quantity, 0) as yesterday_qty'),
                    DB::raw('COALESCE(tin.total, 0) as today_in'),
                    DB::raw('COALESCE(tout.total, 0) as today_out')
                )
                ->where('p.is_active', 1);

            if ($mainCategoryId) {
                $query->where('p.main_category_id', $mainCategoryId);
            }
            if ($subCategoryId) {
                $query->where('p.sub_category_id', $subCategoryId);
            }
            if ($warehouseId) {
                $query->where('s.warehouse_id', $warehouseId);
            }
            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->where('p.name', 'like', '%' . $keyword . '%')
                      ->orWhere('p.barcode_small', 'like', '%' . $keyword . '%')
                      ->orWhere('p.code', 'like', '%' . $keyword . '%');
                });
            }
            if ($stockFilter === 'positive') {
                $query->where('s.quantity', '>', 0);
            } elseif ($stockFilter === 'zero') {
                $query->where('s.quantity', '=', 0);
            } elseif ($stockFilter === 'negative') {
                $query->where('s.quantity', '<', 0);
            }

            $totalQty    = (clone $query)->sum('s.quantity');
            $totalAmount = (clone $query)->sum('s.total_amount');

            $all   = $query->orderBy('p.name')->orderBy('w.name')->get();
            $total = $all->count();
            $list  = array_slice($all->toArray(), ($page - 1) * $pageSize, $pageSize);

            return response()->json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'list' => $list,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'last_page' => (int) ceil($total / $pageSize),
                    'categories' => $this->buildCategoryTree(),
                    'warehouses' => DB::table('warehouses')
                        ->where('is_active', 1)
                        ->orderBy('name')
                        ->get(['id', 'name']),
                    'stats' => [
                        'total_rows' => $total,
                        'total_qty' => (float) $totalQty,
                        'total_amount' => (float) $totalAmount,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 500,
                'message' => '服务器错误: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 库存监控：基于每日快照对比，监控整个仓库库存变化
     */
    public function monitor(Request $request)
    {
        try {
            $days = min(30, max(2, $request->integer('days', 7)));

            // 近 N 天每日快照趋势
            $trend = DB::table('stock_snapshots')
                ->selectRaw('snapshot_date, COUNT(DISTINCT product_id) as product_count, SUM(quantity) as total_quantity')
                ->groupBy('snapshot_date')
                ->orderBy('snapshot_date', 'desc')
                ->limit($days)
                ->get()
                ->reverse()
                ->values();

            // 最近两个快照日期
            $dates = DB::table('stock_snapshots')
                ->selectRaw('DISTINCT snapshot_date')
                ->orderBy('snapshot_date', 'desc')
                ->limit(2)
                ->pluck('snapshot_date');
            $latestDate = $dates[0] ?? null;
            $prevDate   = $dates[1] ?? null;

            $empty = [
                'summary' => null,
                'trend' => $trend,
                'warehouse_changes' => [],
                'changes' => [],
            ];

            if (!$latestDate) {
                return response()->json(['code' => 200, 'message' => 'success', 'data' => $empty]);
            }

            $latest = DB::table('stock_snapshots')
                ->select('product_id', 'warehouse_id', 'quantity', 'frozen_qty')
                ->where('snapshot_date', $latestDate);

            // 仓库维度变化（最新 vs 前一）
            $warehouseChanges = collect();
            if ($prevDate) {
                $prevSnap = DB::table('stock_snapshots')
                    ->select('warehouse_id', DB::raw('SUM(quantity) as qty'))
                    ->where('snapshot_date', $prevDate)
                    ->groupBy('warehouse_id');

                $warehouseChanges = DB::table('warehouses as w')
                    ->leftJoinSub($latest, 'l', function ($j) {
                        $j->on('l.warehouse_id', '=', 'w.id');
                    })
                    ->leftJoinSub($prevSnap, 'p', function ($j) {
                        $j->on('p.warehouse_id', '=', 'w.id');
                    })
                    ->select(
                        'w.id as warehouse_id',
                        'w.name as warehouse_name',
                        DB::raw('COALESCE(SUM(l.quantity), 0) as today_qty'),
                        DB::raw('COALESCE(p.qty, 0) as yesterday_qty')
                    )
                    ->where('w.is_active', 1)
                    ->groupBy('w.id', 'w.name', 'p.qty')
                    ->orderBy('w.name')
                    ->get()
                    ->map(function ($r) {
                        $r->diff_qty = (float) $r->today_qty - (float) $r->yesterday_qty;
                        return $r;
                    });
            }

            // 商品变动明细（最新 vs 前一）
            $changes = collect();
            if ($prevDate) {
                $prevSnap = DB::table('stock_snapshots')
                    ->select('product_id', 'warehouse_id', 'quantity')
                    ->where('snapshot_date', $prevDate);

                $changes = DB::table('stock_snapshots as l')
                    ->leftJoin('products as pr', 'l.product_id', '=', 'pr.id')
                    ->leftJoin('warehouses as w', 'l.warehouse_id', '=', 'w.id')
                    ->leftJoinSub($prevSnap, 'p', function ($j) {
                        $j->on('p.product_id', '=', 'l.product_id')
                           ->on('p.warehouse_id', '=', 'l.warehouse_id');
                    })
                    ->where('l.snapshot_date', $latestDate)
                    ->select(
                        'pr.name as product_name',
                        'pr.spec',
                        'pr.barcode_small as barcode',
                        'w.name as warehouse_name',
                        DB::raw('COALESCE(p.quantity, 0) as yesterday_qty'),
                        'l.quantity as today_qty',
                        'l.frozen_qty'
                    )
                    ->get()
                    ->map(function ($r) {
                        $r->diff_qty = (float) $r->today_qty - (float) $r->yesterday_qty;
                        return $r;
                    })
                    ->filter(function ($r) {
                        return abs($r->diff_qty) > 0.001;
                    })
                    ->sortByDesc(function ($r) {
                        return abs($r->diff_qty);
                    })
                    ->values()
                    ->take(100);
            }

            $summary = [
                'snapshot_date'   => $latestDate,
                'total_products'  => DB::table('stock_snapshots')->where('snapshot_date', $latestDate)->count(),
                'total_quantity'  => (float) DB::table('stock_snapshots')->where('snapshot_date', $latestDate)->sum('quantity'),
                'frozen_quantity' => (float) DB::table('stock_snapshots')->where('snapshot_date', $latestDate)->sum('frozen_qty'),
                'changed_count'   => $changes->count(),
            ];

            return response()->json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'summary' => $summary,
                    'trend' => $trend,
                    'warehouse_changes' => $warehouseChanges,
                    'changes' => $changes,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 500,
                'message' => '服务器错误: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 构建主/副分类树（带商品数）
     */
    private function buildCategoryTree(): array
    {
        $mainCats = DB::table('product_categories')
            ->where('is_main', 1)
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name']);

        $tree = [];
        foreach ($mainCats as $mc) {
            $children = DB::table('product_categories')
                ->where('parent_id', $mc->id)
                ->where('is_active', 1)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(function ($sc) {
                    $sc->count = DB::table('products')
                        ->where('sub_category_id', $sc->id)
                        ->where('is_active', 1)
                        ->count();
                    return $sc;
                });

            $tree[] = [
                'id' => $mc->id,
                'name' => $mc->name,
                'count' => DB::table('products')
                    ->where('main_category_id', $mc->id)
                    ->where('is_active', 1)
                    ->count(),
                'children' => $children,
            ];
        }

        return $tree;
    }
}
