<?php

namespace Modules\Business\Services;

use Modules\Business\Exceptions\BusinessRuleException;
use Modules\Business\Models\Product;
use Modules\Business\Models\Stock;
use Modules\Business\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                $q->where('name', 'like', '%' . $request->keyword . '%')
                  ->orWhere('code', 'like', '%' . $request->keyword . '%');
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
    public function stockIn(int $productId, int $warehouseId, int $quantity, ?float $costPrice = null): Stock
    {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $costPrice) {
            /** @var Stock|null $stock */
            $stock = Stock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($stock) {
                $stock->quantity = (int) $stock->quantity + $quantity;
                if ($costPrice !== null) {
                    $stock->cost_price = $costPrice;
                }
                $stock->save();
            } else {
                // 唯一索引兜底：并发下重复插入会抛 QueryException 并回滚，不会写坏数据
                $stock = Stock::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                    'cost_price' => (float) ($costPrice ?? 0),
                ]);
            }

            return $stock->fresh(['product', 'warehouse']);
        });
    }

    /** 出库：库存不足时抛 BusinessRuleException（控制器映射为 422） */
    public function stockOut(int $productId, int $warehouseId, int $quantity): Stock
    {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity) {
            /** @var Stock|null $stock */
            $stock = Stock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (!$stock || (int) $stock->quantity < $quantity) {
                throw new BusinessRuleException('库存不足');
            }

            $stock->quantity = (int) $stock->quantity - $quantity;
            $stock->save();

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
            'low_stock_count' => Stock::whereColumn('quantity', '<', 10)->count(),
        ];

        $topProducts = Stock::with('product')
            ->selectRaw('product_id, SUM(quantity) as total_qty')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        return ['stats' => $stats, 'top_products' => $topProducts];
    }
}
