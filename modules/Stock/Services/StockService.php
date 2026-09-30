<?php

namespace Modules\Stock\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;

/**
 * 库存核心服务：入库 / 出库 / 列表查询 / 统计。
 *
 * 所有对 stocks 表数量的读写都收敛在此，控制器只做参数校验与响应封装。
 * 测试见 tests/Feature/Business/StockServiceTest.php。
 *
 * 实现说明——数量变更使用 PHP 侧「读-改-写」+ lockForUpdate 行锁，
 * 不使用 DB::raw('quantity + N')，原因有两个：
 *   1. Stock::updateOrCreate 的 INSERT 分支会把 raw 表达式当值插入，
 *      全新商品/仓库的首单入库会直接抛 SQL 错误；
 *   2. raw 表达式赋值后模型的内存属性变成 Expression 对象，
 *      直接序列化返回前端会得到 {} 而不是数字。
 */
class StockService
{
    /** 库存列表（支持商品/仓库/关键字筛选）+ 下拉数据 */
    public function query(Request $request): array
    {
        $query = Stock::with(['product', 'warehouse']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('keyword')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->keyword.'%')
                    ->orWhere('code', 'like', '%'.$request->keyword.'%');
            });
        }

        $paginator = $query->orderBy('id', 'desc')
            ->paginate((int) $request->integer('per_page', 20));

        return [
            'data' => $paginator,
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->get(),
        ];
    }

    /** 入库：记录不存在则创建，存在则累加数量 */
    public function stockIn(int $productId, int $warehouseId, int $quantity, ?float $costPrice = null, ?int $relatedId = null, ?string $relatedType = null): Stock
    {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $costPrice, $relatedId, $relatedType) {
            /** @var Stock|null $stock */
            $stock = Stock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            $before = $stock ? (int) $stock->quantity : 0;

            if ($stock) {
                $stock->quantity = (int) $stock->quantity + $quantity;
                if ($costPrice !== null) {
                    $stock->cost_price = $costPrice;
                }
                $stock->updated_at = now();
                $stock->save();
            } else {
                // 唯一索引兜底：并发下重复插入会抛 QueryException 并回滚，不会写坏数据
                $stock = Stock::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                    'cost_price' => (float) ($costPrice ?? 0),
                    'updated_at' => now(),
                ]);
            }

            $this->recordHistory(
                $productId, $warehouseId, 'stock_in', $quantity,
                $before, (int) $stock->quantity,
                $relatedId ?? 0, $relatedType ?? 'StockIn', '入库'
            );

            $this->syncProductStockQty($productId);

            return $stock->fresh(['product', 'warehouse']);
        });
    }

    /** 出库：库存不足时抛 StockRuleException（控制器映射为 422） */
    public function stockOut(int $productId, int $warehouseId, int $quantity, ?int $relatedId = null, ?string $relatedType = null): Stock
    {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $relatedId, $relatedType) {
            /** @var Stock|null $stock */
            $stock = Stock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $stock || (int) $stock->quantity < $quantity) {
                throw new StockRuleException('库存不足');
            }

            $before = (int) $stock->quantity;
            $stock->quantity = $before - $quantity;
            $stock->save();

            $this->recordHistory(
                $productId, $warehouseId, 'stock_out', -$quantity,
                $before, (int) $stock->quantity,
                $relatedId ?? 0, $relatedType ?? 'StockOut', '出库'
            );

            $this->syncProductStockQty($productId);

            return $stock->fresh(['product', 'warehouse']);
        });
    }

    /** 库存概览统计 */
    public function statistics(): array
    {
        $stats = [
            'total_products' => Stock::count(),
            'total_quantity' => (int) Stock::sum('quantity'),
            'total_amount' => (float) Stock::sum('total_amount'),
            'low_stock_count' => Stock::where('quantity', '<', 10)->count(),
        ];

        $topProducts = Stock::with('product')
            ->selectRaw('product_id, SUM(quantity) as total_qty')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        return ['stats' => $stats, 'top_products' => $topProducts];
    }

    /**
     * 冻结库存：累加冻结数量（不动 quantity 总量），可用 = quantity - frozen_qty。
     * 之前 freeze 同时减 quantity 加 frozen，导致同商品多行时可用被双重扣（第二行 quantity 已减、frozen 又加，可用 = (q-N)-(f+N) = q-f-2N）。改为只加 frozen，可用口径正确。
     */
    public function freeze(int $productId, int $warehouseId, int $quantity, int $orderId): Stock
    {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $orderId) {
            $stock = Stock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                throw new StockRuleException('库存不足');
            }

            if ((int) $stock->quantity - (int) $stock->frozen_qty < $quantity) {
                throw new StockRuleException('库存不足');
            }

            $beforeFrozen = (int) $stock->frozen_qty;
            $stock->frozen_qty = $beforeFrozen + $quantity;
            $stock->save();

            $this->recordHistory(
                $productId, $warehouseId, 'sale_freeze', $quantity,
                $beforeFrozen, (int) $stock->frozen_qty, $orderId, 'SalesOrder', '销售订单冻结'
            );

            $this->syncProductStockQty($productId);

            return $stock->fresh(['product', 'warehouse']);
        });
    }

    /**
     * 解冻库存：减少冻结数量（不动 quantity），与 freeze 对称。
     */
    public function unfreeze(int $productId, int $warehouseId, int $quantity, int $orderId): ?Stock
    {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $orderId) {
            $stock = Stock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                return null;
            }

            $beforeFrozen = (int) $stock->frozen_qty;
            $stock->frozen_qty = max(0, $beforeFrozen - $quantity);
            $stock->save();

            $this->recordHistory(
                $productId, $warehouseId, 'sale_unfreeze', -$quantity,
                $beforeFrozen, (int) $stock->frozen_qty, $orderId, 'SalesOrder', '销售订单解冻'
            );

            $this->syncProductStockQty($productId);

            return $stock->fresh(['product', 'warehouse']);
        });
    }

    /** 写一条库存变动流水（stocks_history）。change_qty 正为增加、负为减少。 */
    private function recordHistory(int $productId, int $warehouseId, string $changeType, int $changeQty, int $beforeQty, int $afterQty, int $relatedId, string $relatedType, string $remark): void
    {
        DB::table('stocks_history')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'change_type' => $changeType,
            'change_qty' => $changeQty,
            'before_qty' => $beforeQty,
            'after_qty' => $afterQty,
            'related_id' => $relatedId,
            'related_type' => $relatedType,
            'remark' => $remark,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 重算并同步 products.stock_qty = 该商品在所有仓库 stocks.quantity 之和。
     *
     * 对齐旧系统：旧系统在下单冻结 / 采购入库 / 退货多处就地维护 products.stock_qty
     * （冻结用 GREATEST(stock_qty - N, 0)、入库用 sum 重算），散落在各 Controller
     * 且口径不统一、冻结解冻来回加减会漂移。新系统收敛在此：任何库存写操作后
     * 重算一次，保证 products.stock_qty 始终等于 stocks 表 quantity 汇总，
     * 不再做会漂移的就地加减——单值口径与多仓明细严格一致。
     */
    private function syncProductStockQty(int $productId): void
    {
        DB::table('products')->where('id', $productId)->update([
            'stock_qty' => (int) DB::table('stocks')->where('product_id', $productId)->sum('quantity'),
            'updated_at' => now(),
        ]);
    }
}
