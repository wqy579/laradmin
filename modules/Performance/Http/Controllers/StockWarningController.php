<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 库存预警中心（绩效分析 → 库存预警中心）。
 *
 * 四个预警类型共用一个控制器，按 type 分发：
 *   safety-stock：当前库存 < products.safety_stock
 *   expiry：依赖 products.shelf_life_days / expiry_date
 *   sellable-days：当前库存 ÷ 近 30 天日均销量
 *   stock-exception：库存为负 / 与核对差异大
 *
 * 日均销量取 stocks_history 近 30 天出库流水（change_type 含 out/sale/transfer_out 等）。
 */
class StockWarningController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->input('type', 'safety-stock');
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        return match ($type) {
            'safety-stock' => $this->safetyStock($request, $warehouseId),
            'expiry' => $this->expiry($request, $warehouseId),
            'sellable-days' => $this->sellableDays($request, $warehouseId),
            'stock-exception' => $this->stockException($request, $warehouseId),
            default => $this->safetyStock($request, $warehouseId),
        };
    }

    /** 概览：各预警数量 + 库存周转天数 */
    public function overview(Request $request): JsonResponse
    {
        $safetyCount = $this->safetyCount();
        $expiryCount = $this->expiryCount();
        $lowSellCount = $this->lowSellCount();
        $exceptionCount = $this->exceptionCount();

        return $this->success([
            'safety_stock' => $safetyCount,
            'expiry' => $expiryCount,
            'low_sellable' => $lowSellCount,
            'exception' => $exceptionCount,
            'turnover_days' => $this->turnoverDays(),
        ]);
    }

    private function safetyStock(Request $request, ?int $warehouseId): JsonResponse
    {
        $list = DB::table('products as p')
            ->leftJoin('stocks as s', function ($j) use ($warehouseId) {
                $j->on('s.product_id', '=', 'p.id');
                if ($warehouseId) {
                    $j->where('s.warehouse_id', $warehouseId);
                }
            })
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->where('p.is_active', 1)
            ->whereColumn(DB::raw('COALESCE(s.quantity, 0)'), '<', 'p.safety_stock')
            ->select([
                'p.id', 'p.code', 'p.name', 'p.spec', DB::raw('COALESCE(p.price_unit, "") as unit'),
                'b.name as brand_name', 'mc.name as category_name',
                'w.name as warehouse_name', 'w.id as warehouse_id',
                DB::raw('COALESCE(s.quantity, 0) as current_stock'),
                'p.safety_stock',
            ])
            ->get()->map(function ($r) {
                $daily = $this->dailySales($r->id);
                $safety = (int) $r->safety_stock;
                $cur = (int) $r->current_stock;
                $sug = max(0, $safety - $cur);

                return [
                    'product_id' => $r->id,
                    'product_code' => $r->code,
                    'product_name' => $r->name,
                    'brand_name' => $r->brand_name,
                    'category_name' => $r->category_name,
                    'spec' => $r->spec,
                    'unit' => $r->unit,
                    'warehouse_name' => $r->warehouse_name ?: '-',
                    'current_stock' => $cur,
                    'safety_stock' => $safety,
                    'suggest_replenish' => $sug,
                    'daily_7' => $daily['d7'],
                    'daily_30' => $daily['d30'],
                    'sellable_days' => $daily['d30'] > 0 ? round($cur / $daily['d30'], 1) : 999999,
                    'supplier_name' => '',
                ];
            })->values();

        return $this->success(['list' => $list, 'total' => $list->count()]);
    }

    private function expiry(Request $request, ?int $warehouseId): JsonResponse
    {
        $list = DB::table('products as p')
            ->where('p.is_active', 1)
            ->whereNotNull('p.expiry_date')
            ->whereNotNull('p.shelf_life_days')
            ->select(
                'p.id', 'p.code', 'p.name', 'p.spec',
                'p.shelf_life_days', 'p.expiry_date',
                DB::raw('DATEDIFF(p.expiry_date, CURDATE()) as remaining_days')
            )
            ->having('remaining_days', '<=', 60)
            ->orderBy('remaining_days')
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->id,
                'product_code' => $r->code,
                'product_name' => $r->name,
                'spec' => $r->spec,
                'batch' => '-',
                'shelf_life_days' => $r->shelf_life_days,
                'expiry_date' => (string) $r->expiry_date,
                'remaining_days' => $r->remaining_days,
                'status' => $r->remaining_days <= 0 ? 'expired' : ($r->remaining_days <= 30 ? 'near_expiry' : 'normal'),
            ]);

        return $this->success([
            'list' => $list,
            'total' => $list->count(),
            'hint' => '需在商品档案维护保质期、在入库时录入生产日期方可完整预警',
        ]);
    }

    private function sellableDays(Request $request, ?int $warehouseId): JsonResponse
    {
        $list = DB::table('products as p')
            ->leftJoin('stocks as s', function ($j) use ($warehouseId) {
                $j->on('s.product_id', '=', 'p.id');
                if ($warehouseId) {
                    $j->where('s.warehouse_id', $warehouseId);
                }
            })
            ->where('p.is_active', 1)
            ->select(
                'p.id', 'p.code', 'p.name', 'p.spec',
                DB::raw('COALESCE(s.quantity, 0) as current_stock')
            )
            ->get()->map(function ($r) {
                $daily = $this->dailySales($r->id);
                $days = $daily['d30'] > 0 ? round($r->current_stock / $daily['d30'], 1) : 999999;

                return [
                    'product_id' => $r->id,
                    'product_code' => $r->code,
                    'product_name' => $r->name,
                    'spec' => $r->spec,
                    'current_stock' => (int) $r->current_stock,
                    'daily_30' => $daily['d30'],
                    'sellable_days' => $days,
                    'status' => $days <= 7 ? 'short' : ($days <= 15 ? 'low' : ($days > 90 ? 'slow' : 'ok')),
                    'action' => $days <= 7 ? 'replenish' : ($days > 90 ? 'promote' : 'observe'),
                ];
            })->filter(fn ($r) => $r['sellable_days'] !== 999999 || $r['current_stock'] > 0)->values();

        return $this->success(['list' => $list, 'total' => $list->count()]);
    }

    private function stockException(Request $request, ?int $warehouseId): JsonResponse
    {
        $list = DB::table('stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.quantity', '<', 0)
            ->when($warehouseId, fn ($q) => $q->where('s.warehouse_id', $warehouseId))
            ->select(
                'p.id', 'p.code', 'p.name', 'p.spec',
                'w.name as warehouse_name',
                's.quantity as current_stock'
            )
            ->get()->map(fn ($r) => [
                'product_id' => $r->id,
                'product_code' => $r->code,
                'product_name' => $r->name,
                'spec' => $r->spec,
                'warehouse_name' => $r->warehouse_name,
                'current_stock' => (int) $r->current_stock,
                'issue' => 'negative_stock',
            ]);

        return $this->success(['list' => $list, 'total' => $list->count()]);
    }

    /** 近 7/30 天销量（取 stocks_history 出库流水） */
    private function dailySales(int $productId): array
    {
        $d7 = DB::table('stocks_history')
            ->where('product_id', $productId)
            ->where('change_qty', '<', 0)
            ->where('created_at', '>=', now()->subDays(7))
            ->sum(DB::raw('ABS(change_qty)'));
        $d30 = DB::table('stocks_history')
            ->where('product_id', $productId)
            ->where('change_qty', '<', 0)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum(DB::raw('ABS(change_qty)'));

        return [
            'd7' => (int) $d7,
            'd30' => (int) $d30,
        ];
    }

    private function safetyCount(): int
    {
        return DB::table('products as p')
            ->leftJoin('stocks as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', 1)
            ->whereColumn(DB::raw('COALESCE(s.quantity, 0)'), '<', 'p.safety_stock')
            ->count();
    }

    private function expiryCount(): int
    {
        return DB::table('products')
            ->where('is_active', 1)
            ->whereNotNull('expiry_date')
            ->whereRaw('DATEDIFF(expiry_date, CURDATE()) <= 60')
            ->count();
    }

    private function lowSellCount(): int
    {
        return DB::table('products as p')
            ->leftJoin('stocks as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', 1)
            ->whereRaw('COALESCE(s.quantity, 0) <= 7')
            ->count();
    }

    private function exceptionCount(): int
    {
        return DB::table('stocks')->where('quantity', '<', 0)->count();
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
