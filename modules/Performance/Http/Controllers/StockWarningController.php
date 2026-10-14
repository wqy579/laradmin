<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 库存预警中心（绩效分析 → 库存预警中心）。
 *
 * 四类预警按 type 分发（GET perf/warning/{type}）：
 *   safety-stock   当前库存 ≤ products.safety_stock（< 红 / = 橙）；建议补货 = 安全线×2 − 当前库存，min 0
 *   expiry         商品级临期（products.expiry_date / shelf_life_days，库内无批次·生产日期字段，口径见响应 hint）
 *   sellable-days  近 30 天日均销量（stocks_history change_qty<0 合计 ÷ 30）算可销天数：
 *                  <7 缺货风险 / 7–90 正常 / >90 滞销（口径以任务书 §六 为准）；日均=0 → 可销天数 null
 *   stock-exception 库存负数（红）+ 盘亏未处理（stock_adjusts.adjust_type=stock_loss 或盘点 diff<0 且未审核，橙）
 *
 * 日均销量按商品一次批量取（无 N+1）；库存按商品聚合后一次 join（多仓合计）。
 */
class StockWarningController extends Controller
{
    /** GET perf/warning/ —— 兼容 ?type=；无 type 返回 overview 结构 */
    public function index(Request $request): JsonResponse
    {
        $type = $request->input('type');
        if ($type !== null && $type !== '') {
            return $this->byType($request, (string) $type);
        }

        return $this->overview($request);
    }

    /** GET perf/warning/{type} —— 非法 type 返回 400 */
    public function byType(Request $request, string $type): JsonResponse
    {
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        return match ($type) {
            'safety-stock' => $this->success($this->safetyStockData($request, $warehouseId)),
            'expiry' => $this->success($this->expiryData($request, $warehouseId)),
            'sellable-days' => $this->success($this->sellableDaysData($request, $warehouseId)),
            'stock-exception' => $this->success($this->stockExceptionData($request, $warehouseId)),
            default => $this->error('未知预警类型: '.$type, 400),
        };
    }

    /** GET perf/warning/overview —— 四类计数 + 总数（保留原字段名，另补 counts） */
    public function overview(Request $request): JsonResponse
    {
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        // 四类各构建一次完整列表（每个内部 join 一次取齐、日均批量查询），计数直接取列表条数，
        // 保证 overview 与 byType 逐条一致，且无 N+1。
        $safety = $this->safetyStockData($request, $warehouseId);
        $expiry = $this->expiryData($request, $warehouseId);
        $sellable = $this->sellableDaysData($request, $warehouseId);
        $exception = $this->stockExceptionData($request, $warehouseId);

        $lowSellCount = $sellable['list']->filter(fn ($r) => $r['status'] === 'short')->count();
        $total = $safety['total'] + $expiry['total'] + $lowSellCount + $exception['total'];

        return $this->success([
            // 原字段名（前端概览卡片直接消费）
            'safety_stock' => $safety['total'],
            'expiry' => $expiry['total'],
            'low_sellable' => $lowSellCount,
            'exception' => $exception['total'],
            'turnover_days' => $this->turnoverDays(),
            // 补充：byType 各类预警计数 + 总数
            'counts' => [
                'safety-stock' => $safety['total'],
                'expiry' => $expiry['total'],
                'sellable-days' => $lowSellCount,
                'stock-exception' => $exception['total'],
                'total' => $total,
            ],
            'total' => $total,
        ]);
    }

    /** 一键转采购草稿：建议补货量 = 安全库存×2 − 当前库存（min 0）；items/ids 二选一入参 */
    public function purchaseDraft(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'nullable|array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'nullable|integer|min:0',
            'ids' => 'nullable|array',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $inputItems = [];
        foreach ((array) $request->input('items', []) as $it) {
            $inputItems[(int) $it['product_id']] = array_key_exists('quantity', $it) && $it['quantity'] !== null
                ? (int) $it['quantity']
                : null;
        }
        foreach ((array) $request->input('ids', []) as $id) {
            $inputItems[(int) $id] = ($inputItems[(int) $id] ?? null);
        }

        if (empty($inputItems)) {
            return $this->error('请提供商品 ID 列表（items 或 ids）', 400);
        }

        $productIds = array_keys($inputItems);
        $products = DB::table('products as p')
            ->leftJoin('stocks as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', 1)
            ->whereIn('p.id', $productIds)
            ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.safety_stock')
            ->selectRaw(
                'p.id, p.code, p.name, p.spec, p.safety_stock, COALESCE(SUM(s.quantity), 0) as current_stock'
            )
            ->get();

        $rows = [];
        foreach ($products as $p) {
            $safety = (int) $p->safety_stock;
            $cur = (int) $p->current_stock;
            $qty = array_key_exists((int) $p->id, $inputItems) && $inputItems[(int) $p->id] !== null
                ? max(0, $inputItems[(int) $p->id])
                : max(0, $safety * 2 - $cur);

            $rows[] = [
                'product_id' => (int) $p->id,
                'product_code' => $p->code,
                'product_name' => $p->name,
                'spec' => $p->spec,
                'quantity' => $qty,
                'current_stock' => $cur,
                'safety_stock' => $safety,
                'supplier' => null, // 商品档案无供应商关联
            ];
        }

        // 不直接 create：purchase_applications.store 强制 supplier_id + approver_id（exists:employees,id），
        // 当前商品无供应商映射、employees 为空，强行建单必造脏数据 → 返回建议清单由前端跳转采购申请页预填。
        return $this->success([
            'items' => $rows,
            'count' => count($rows),
            'redirect' => '/business/purchase-application',
        ], empty($rows) ? '无需要补货的商品' : '已生成补货建议，请在采购申请页选择供应商与审批人后提交');
    }

    // ------------------------------------------------------------------
    // 四类构建器（返回数组；byType 包一层 success）
    // ------------------------------------------------------------------

    private function safetyStockData(Request $request, ?int $warehouseId): array
    {
        $warehouseName = $warehouseId
            ? (string) (DB::table('warehouses')->where('id', $warehouseId)->value('name') ?? '-')
            : '-';

        $rows = DB::table('products as p')
            ->leftJoin('stocks as s', function ($j) use ($warehouseId) {
                $j->on('s.product_id', '=', 'p.id');
                if ($warehouseId) {
                    $j->where('s.warehouse_id', $warehouseId);
                }
            })
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->where('p.is_active', 1)
            ->where('p.safety_stock', '>', 0)
            ->groupBy(
                'p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit',
                'p.safety_stock', 'b.name', 'mc.name'
            )
            ->havingRaw('COALESCE(SUM(s.quantity), 0) <= MAX(p.safety_stock)')
            ->selectRaw(
                'p.id, p.code, p.name, p.spec, COALESCE(p.price_unit, "") as unit,
                 b.name as brand_name, mc.name as category_name,
                 COALESCE(SUM(s.quantity), 0) as current_stock, MAX(p.safety_stock) as safety_stock'
            )
            ->orderBy('p.id')
            ->get();

        $dailyMap = $this->dailySalesMap($rows->pluck('id')->map(fn ($v) => (int) $v)->all());

        $list = $rows->map(function ($r) use ($dailyMap, $warehouseName) {
            $daily = $dailyMap[(int) $r->id] ?? ['d7' => 0, 'd30' => 0];
            $safety = (int) $r->safety_stock;
            $cur = (int) $r->current_stock;
            // 任务书 §六：建议补货 = 安全库存线 × 2 − 当前库存，最小 0
            $sug = max(0, $safety * 2 - $cur);
            $isRed = $cur < $safety; // < 红（预警）；= 线为橙（注意）

            return [
                'product_id' => (int) $r->id,
                'product_code' => $r->code,
                'product_name' => $r->name,
                'brand_name' => $r->brand_name,
                'category_name' => $r->category_name,
                'spec' => $r->spec,
                'unit' => $r->unit,
                'warehouse_name' => $warehouseName,
                'current_stock' => $cur,
                'safety_stock' => $safety,
                'suggest_replenish' => $sug,
                'daily_7' => $daily['d7'],
                'daily_30' => $daily['d30'],
                'sellable_days' => $daily['d30'] > 0 ? round($cur / ($daily['d30'] / 30), 1) : 999999,
                'status' => $isRed ? 'danger' : 'warning',
                'status_label' => $isRed ? '预警' : '注意',
                'hex' => $isRed ? '#F56C6C' : '#E6A23E',
                'supplier_name' => '',
            ];
        })->values();

        return ['list' => $list, 'total' => $list->count()];
    }

    private function expiryData(Request $request, ?int $warehouseId): array
    {
        $rows = DB::table('products as p')
            ->leftJoin('stocks as s', function ($j) use ($warehouseId) {
                $j->on('s.product_id', '=', 'p.id');
                if ($warehouseId) {
                    $j->where('s.warehouse_id', $warehouseId);
                }
            })
            ->where('p.is_active', 1)
            ->whereNotNull('p.expiry_date')
            ->whereRaw('DATEDIFF(p.expiry_date, CURDATE()) <= 60')
            ->groupBy(
                'p.id', 'p.code', 'p.name', 'p.spec', 'p.shelf_life_days', 'p.expiry_date'
            )
            ->selectRaw(
                'p.id, p.code, p.name, p.spec, p.shelf_life_days, p.expiry_date,
                 COALESCE(SUM(s.quantity), 0) as stock_qty,
                 DATEDIFF(p.expiry_date, CURDATE()) as remaining_days'
            )
            ->orderBy('remaining_days')
            ->get();

        $list = $rows->map(function ($r) {
            $remaining = (int) $r->remaining_days;
            // 无批次表/字段（stock_in_items、stocks 均无 batch/production_date）→ 商品级口径：
            // 生产日期 = 到期日 − 保质天数（档案字段推算）
            $prodDate = null;
            if ($r->expiry_date && $r->shelf_life_days) {
                $prodDate = date('Y-m-d', strtotime($r->expiry_date.' - '.(int) $r->shelf_life_days.' days'));
            }
            $status = $remaining <= 0 ? 'expired' : ($remaining <= 30 ? 'near_expiry' : 'normal');
            $hex = $remaining <= 0 ? '#F56C6C' : ($remaining <= 30 ? '#E6A23E' : '#67C23A');

            return [
                'product_id' => (int) $r->id,
                'product_code' => $r->code,
                'product_name' => $r->name,
                'spec' => $r->spec,
                'batch' => null, // 库内无批次字段
                'production_date' => $prodDate, // 生产日期（推算）
                'stock_qty' => (int) $r->stock_qty, // 库存数量（多仓合计）
                'shelf_life_days' => $r->shelf_life_days !== null ? (int) $r->shelf_life_days : null,
                'expiry_date' => (string) $r->expiry_date,
                'remaining_days' => $remaining,
                'status' => $status,
                'status_label' => $remaining <= 0 ? '已过期' : ($remaining <= 30 ? '临期' : '正常'),
                'hex' => $hex,
            ];
        })->values();

        return [
            'list' => $list,
            'total' => $list->count(),
            'hint' => '口径：商品级预警（products.expiry_date / shelf_life_days，库内无批次·生产日期字段，生产日期=到期日−保质天数，库存为多仓合计）；≤30 天临期橙、已过期红',
        ];
    }

    private function sellableDaysData(Request $request, ?int $warehouseId): array
    {
        $days = max(1, (int) $request->input('days', 30)); // 近 N 天，默认 30

        $rows = DB::table('products as p')
            ->leftJoin('stocks as s', function ($j) use ($warehouseId) {
                $j->on('s.product_id', '=', 'p.id');
                if ($warehouseId) {
                    $j->where('s.warehouse_id', $warehouseId);
                }
            })
            ->where('p.is_active', 1)
            ->groupBy('p.id', 'p.code', 'p.name', 'p.spec')
            ->selectRaw(
                'p.id, p.code, p.name, p.spec, COALESCE(SUM(s.quantity), 0) as current_stock'
            )
            ->orderBy('p.id')
            ->get();

        $dailyMap = $this->dailySalesMap($rows->pluck('id')->map(fn ($v) => (int) $v)->all(), $days);

        $list = $rows->map(function ($r) use ($dailyMap, $days) {
            $cur = (int) $r->current_stock;
            $dTotal = $dailyMap[(int) $r->id]['d'.$days] ?? 0;
            $avg = $dTotal / $days; // 日均销量
            $sellable = $avg > 0 ? round($cur / $avg, 1) : null;

            if ($sellable === null) {
                // 日均=0：可销天数 null；库存 0 标缺货，否则正常
                $status = $cur <= 0 ? 'short' : 'ok';
            } elseif ($sellable < 7) {
                $status = 'short';   // 任务书：低于 7 天标缺货风险
            } elseif ($sellable > 90) {
                $status = 'slow';    // 任务书：高于 90 天标滞销
            } else {
                $status = 'ok';
            }
            $hex = $status === 'short' ? '#F56C6C' : ($status === 'slow' ? '#E6A23E' : '#67C23A');
            $label = $status === 'short' ? '缺货风险' : ($status === 'slow' ? '滞销' : '正常');

            return [
                'product_id' => (int) $r->id,
                'product_code' => $r->code,
                'product_name' => $r->name,
                'spec' => $r->spec,
                'current_stock' => $cur,
                'daily_30' => round($avg, 2),
                'daily_avg' => round($avg, 2),
                'days_window' => $days,
                'sellable_days' => $sellable,
                'status' => $status,
                'status_label' => $label,
                'hex' => $hex,
                'action' => $status === 'short' ? 'replenish' : ($status === 'slow' ? 'promote' : 'observe'),
            ];
        })->values();

        return ['list' => $list, 'total' => $list->count()];
    }

    private function stockExceptionData(Request $request, ?int $warehouseId): array
    {
        // 1) 库存负数（红）
        $negatives = DB::table('stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.quantity', '<', 0)
            ->when($warehouseId, fn ($q) => $q->where('s.warehouse_id', $warehouseId))
            ->select(
                'p.id as product_id', 'p.code as product_code', 'p.name as product_name', 'p.spec',
                'w.name as warehouse_name',
                's.quantity as current_stock'
            )
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'spec' => $r->spec,
                'warehouse_name' => $r->warehouse_name ?? '-',
                'current_stock' => (int) $r->current_stock,
                'issue' => 'negative_stock',
                'issue_label' => '负库存',
                'level' => 'danger',
                'hex' => '#F56C6C',
                'doc_nos' => null,
            ]);

        // 2) 盘亏未处理（橙）——读实际单据：stock_adjusts.adjust_type=stock_loss 且未审核/未取消
        $lossAdjusts = DB::table('stock_adjust_items as ai')
            ->join('stock_adjusts as a', 'a.id', '=', 'ai.adjust_id')
            ->join('products as p', 'p.id', '=', 'ai.product_id')
            ->where('a.adjust_type', 'stock_loss')
            ->whereNotIn('a.status', ['approved', 'cancelled'])
            ->when($warehouseId, fn ($q) => $q->where('a.warehouse_id', $warehouseId))
            ->groupBy('ai.product_id', 'p.code', 'p.name', 'p.spec')
            ->selectRaw(
                'ai.product_id, p.code, p.name, p.spec,
                 ABS(SUM(ai.adjust_qty)) as loss_qty,
                 GROUP_CONCAT(DISTINCT a.adjust_no) as nos'
            )
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_code' => $r->code,
                'product_name' => $r->name,
                'spec' => $r->spec,
                'warehouse_name' => '-',
                'current_stock' => null,
                'issue' => 'stock_loss_pending',
                'issue_label' => '盘亏未处理',
                'level' => 'warning',
                'hex' => '#E6A23E',
                'loss_qty' => (int) $r->loss_qty,
                'doc_nos' => (string) $r->nos,
            ]);

        // 3) 盘点盘亏未审核（diff<0 且单据未审核/未取消）（橙）
        $lossChecks = DB::table('stock_check_items as ci')
            ->join('stock_checks as c', 'c.id', '=', 'ci.check_id')
            ->join('products as p', 'p.id', '=', 'ci.product_id')
            ->where('ci.diff_qty', '<', 0)
            ->whereNotIn('c.status', ['approved', 'cancelled'])
            ->when($warehouseId, fn ($q) => $q->where('c.warehouse_id', $warehouseId))
            ->groupBy('ci.product_id', 'p.code', 'p.name', 'p.spec')
            ->selectRaw(
                'ci.product_id, p.code, p.name, p.spec,
                 SUM(ci.diff_qty) as loss_qty,
                 GROUP_CONCAT(DISTINCT c.check_no) as nos'
            )
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_code' => $r->code,
                'product_name' => $r->name,
                'spec' => $r->spec,
                'warehouse_name' => '-',
                'current_stock' => null,
                'issue' => 'check_loss_pending',
                'issue_label' => '盘亏未处理',
                'level' => 'warning',
                'hex' => '#E6A23E',
                'loss_qty' => (int) $r->loss_qty,
                'doc_nos' => (string) $r->nos,
            ]);

        // 盘盈/其它调整不进异常清单（按现有逻辑，仅负库存为异常）
        $list = $negatives->concat($lossAdjusts)->concat($lossChecks)->values();

        return ['list' => $list, 'total' => $list->count()];
    }

    // ------------------------------------------------------------------
    // 批量日均销量（一次取齐，替代按行循环查询）
    // ------------------------------------------------------------------

    /** @return array<int, array{d7:int, d30:int, d60?:int, d90?:int}> 按商品聚合近 N 天出库量 */
    private function dailySalesMap(array $productIds, int $window = 30): array
    {
        if (empty($productIds)) {
            return [];
        }

        $lookback = max($window, 1);
        $rows = DB::table('stocks_history')
            ->whereIn('product_id', $productIds)
            ->where('change_qty', '<', 0)
            ->where('created_at', '>=', now()->subDays($lookback))
            ->groupBy('product_id')
            ->selectRaw(
                'product_id,
                 SUM(CASE WHEN created_at >= ? THEN ABS(change_qty) ELSE 0 END) as d7,
                 SUM(ABS(change_qty)) as dN',
                [now()->subDays(min(7, $lookback))]
            )
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = [
                'd7' => (int) $r->d7,
                'd30' => (int) $r->dN, // 默认 30 天窗口别名（safety-stock 消费）
                'd'.$window => (int) $r->dN,
            ];
        }

        return $map;
    }

    private function turnoverDays(): float
    {
        $totalStock = (float) DB::table('stocks')->sum(DB::raw('ABS(quantity)'));
        $dailyOut = (float) DB::table('stocks_history')
            ->where('change_qty', '<', 0)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum(DB::raw('ABS(change_qty)'));
        if ($dailyOut <= 0) {
            return 0;
        }

        return round($totalStock / ($dailyOut / 30), 1);
    }
}
