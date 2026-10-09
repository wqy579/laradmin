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
            'warehouses' => Warehouse::where('is_active', true)->where('type', 'normal')->get(),
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
    public function stockOut(int $productId, int $warehouseId, int $quantity, ?int $relatedId = null, ?string $relatedType = null, string $remark = '出库'): Stock
    {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $relatedId, $relatedType, $remark) {
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
                $relatedId ?? 0, $relatedType ?? 'StockOut', $remark
            );

            $this->syncProductStockQty($productId);

            return $stock->fresh(['product', 'warehouse']);
        });
    }

    /**
     * 通用库存调整：盘点/报损等「以实盘为准」的增减。
     *
     * 与 stockIn/stockOut 的区别：
     *   - change_type 由调用方指定（check_in 盘盈 / check_out 盘亏 / adjust_in 调整增加 / adjust_out 调整减少）；
     *   - delta 为负即减少、为正即增加；会按 实盘数量×成本 重算 total_amount。
     *
     * 库存充足校验（按 change_type 决定，与 stockIn/stockOut 的语义对齐）：
     *   - adjust_out / check_out：账面减后不能为负——否则会把库存打成负数，
     *     绕过 stockOut 已经在守的边界（调整单审核、盘点盘亏审核都能穿透它）。
     *   - adjust_in / check_in：增加方向无限制。
     *   - 存量 0 且要减（$stock 为空）也视为不足，同样抛异常，避免创建负库存行。
     *
     * $relatedType 由调用方指定归属单据（Stocktaking 盘点 / StockAdjust 库存调整），
     * 台账与流水按它区分来源；不传时兜底为 Stocktaking（历史行为）。
     *
     * 仍走 lockForUpdate 行锁 + recordHistory + syncProductStockQty，与其它写操作一致。
     *
     * @throws StockRuleException 当 reduce 方向的调整后库存为负
     */
    public function adjust(int $productId, int $warehouseId, int $delta, float $costPrice, int $relatedId, string $changeType, string $remark, ?string $relatedType = null): Stock
    {
        return DB::transaction(function () use ($productId, $warehouseId, $delta, $costPrice, $relatedId, $changeType, $remark, $relatedType) {
            /** @var Stock|null $stock */
            $stock = Stock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            $before = $stock ? (int) $stock->quantity : 0;
            $after = $before + $delta;

            // 减少方向（盘亏 / 调整减少）不能把库存打穿到负数；这与 stockOut 的边界一致，
            // 避免审核通道绕过出库单已经在守的规则。存量本身为 0 且要减也是不足。
            if ($delta < 0 && $after < 0) {
                throw new StockRuleException('库存不足，当前库存 '.$before.'，减少数量 '.abs($delta).' 后为负数');
            }

            if ($stock) {
                $stock->quantity = $after;
                if ($costPrice > 0) {
                    $stock->cost_price = $costPrice;
                }
                $stock->total_amount = (int) $stock->quantity * (float) $stock->cost_price;
                $stock->updated_at = now();
                $stock->save();
            } else {
                // 到不了这里做负数分支：上方校验已经拦截 $after < 0
                $stock = Stock::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $after,
                    'cost_price' => $costPrice,
                    'total_amount' => $after * $costPrice,
                    'updated_at' => now(),
                ]);
            }

            $this->recordHistory(
                $productId, $warehouseId, $changeType, $delta,
                $before, (int) $stock->quantity,
                $relatedId, $relatedType ?? 'Stocktaking', $remark
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

            // 流水记冻结量变化：before=frozen_qty 冻结前, after=frozen_qty 冻结后, change_qty=冻结量(正)
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

            // 解冻：before/after 记 frozen_qty，change_qty 负数（释放冻结）
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
        // stock_qty 反映可用量（quantity - frozen_qty），与 freeze/unfreeze 口径一致
        $row = DB::table('stocks')->where('product_id', $productId)
            ->selectRaw('COALESCE(SUM(quantity - frozen_qty), 0) as available')->first();
        DB::table('products')->where('id', $productId)->update([
            'stock_qty' => (int) ($row->available ?? 0),
            'updated_at' => now(),
        ]);
    }
}
