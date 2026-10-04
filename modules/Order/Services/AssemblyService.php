<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Assembly;
use Modules\Order\Models\Split;
use Modules\Stock\Services\StockService;

/**
 * 组装/拆分审核联动 + 成本结转引擎。
 *
 * 系统无现成移动加权平均服务（StockService::stockIn 是 last-write-wins 覆盖 cost_price），
 * 本服务在调用 stockIn 前，先 lockForUpdate 读旧库存数量+旧成本，按
 * (旧量×旧成本 + 新量×新成本) / 总量 算出加权成本，再传给 stockIn 覆盖。
 *
 * 台账：复用 StockService::stockIn/stockOut，stocks_history 记
 * change_type=stock_in/stock_out + related_type=Assembly/Disassembly，不扩展表结构。
 * 成本历史：写入 product_cost_history（系统此前无成本历史表）。
 * 经营历程：写入 order_operation_logs，order_type=assembly/disassembly。
 * 不写 cash_flows（组装拆分是库存形态转换，不涉现金，对齐采购退货范式）。
 */
class AssemblyService
{
    public function __construct(private StockService $stockService) {}

    /**
     * 组装审核联动：子件出库 + 父件加权入库 + 成本结转 + 经营历程
     */
    public function approveAssembly(Assembly $a, int $adminId, string $adminName, ?string $comment): Assembly
    {
        return DB::transaction(function () use ($a, $adminId, $adminName, $comment) {
            $wid = (int) $a->warehouse_id;
            $newQty = (int) $a->quantity;

            // 1. 子件出库，累加总成本（取子件在该仓库的实时成本价，fallback 明细 unit_cost）
            $totalCost = 0.0;
            foreach ($a->items as $item) {
                $usage = (int) $item->total_usage;
                if ($usage <= 0) {
                    continue;
                }
                $childStock = DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $wid)
                    ->lockForUpdate()
                    ->first();
                $childCost = $this->resolveCost($childStock, (float) $item->unit_cost, (int) $item->product_id);

                $this->stockService->stockOut(
                    (int) $item->product_id, $wid, $usage, $a->id, 'Assembly'
                );

                $lineCost = round($usage * $childCost, 2);
                $totalCost += $lineCost;
                $item->update(['unit_cost' => $childCost, 'total_cost' => $lineCost]);
            }

            // 2. 父件加权平均成本
            $parentStock = DB::table('stocks')
                ->where('product_id', $a->parent_product_id)
                ->where('warehouse_id', $wid)
                ->lockForUpdate()
                ->first();
            $oldQty = $parentStock ? (int) $parentStock->quantity : 0;
            $oldCost = $parentStock ? (float) $parentStock->cost_price : 0;
            $newCost = $newQty > 0 ? $totalCost / $newQty : 0;
            $weightedCost = ($oldQty + $newQty) > 0
                ? round(($oldQty * $oldCost + $newQty * $newCost) / ($oldQty + $newQty), 2)
                : round($newCost, 2);

            // 3. 父件入库（stockIn 用 weightedCost 覆盖 cost_price、写 stocks_history、syncProductStockQty）
            $this->stockService->stockIn(
                (int) $a->parent_product_id, $wid, $newQty, $weightedCost, $a->id, 'Assembly'
            );

            // 4. 同步 products.cost_price（stockIn 只更 stock_qty 不更 products.cost_price）
            DB::table('products')->where('id', $a->parent_product_id)->update([
                'cost_price' => $weightedCost, 'updated_at' => now(),
            ]);

            // 5. 成本变动历史
            DB::table('product_cost_history')->insert([
                'product_id' => $a->parent_product_id, 'warehouse_id' => $wid,
                'old_cost' => $oldCost, 'new_cost' => $weightedCost,
                'old_qty' => $oldQty, 'new_qty' => $oldQty + $newQty,
                'change_type' => 'assembly', 'related_id' => $a->id, 'related_type' => 'Assembly',
                'operator_id' => $adminId, 'operator_name' => $adminName,
                'remark' => '组装入库结转：'.$a->assembly_no,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // 6. 经营历程
            DB::table('order_operation_logs')->insert([
                'order_id' => $a->id, 'order_no' => $a->assembly_no,
                'order_type' => 'assembly', 'user_id' => $adminId, 'user_name' => $adminName,
                'operator_id' => $adminId, 'operator_name' => $adminName,
                'action' => 'approve', 'action_label' => '组装审核通过',
                'detail' => '商品组装审核通过：'.$a->parent_product_name.'×'.$newQty.
                    '，子件'.$a->items->count().'种，总成本¥'.number_format($totalCost, 2).
                    '，父件单位成本¥'.number_format($weightedCost, 2),
                'remark' => $comment, 'from_status' => 'pending', 'to_status' => 'approved',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // 7. 更新组装单
            $a->status = 'approved';
            $a->approved_by = $adminId;
            $a->approved_at = now();
            $a->approval_comment = $comment;
            $a->total_cost = round($totalCost, 2);
            $a->unit_cost = $weightedCost;
            $a->save();

            return $a->fresh(['items.product']);
        });
    }

    /**
     * 拆分审核联动：父件出库 + 子件按售价比例分摊入库 + 成本结转 + 经营历程
     */
    public function approveSplit(Split $s, int $adminId, string $adminName, ?string $comment): Split
    {
        return DB::transaction(function () use ($s, $adminId, $adminName, $comment) {
            $wid = (int) $s->warehouse_id;
            $splitQty = (int) $s->quantity;

            // 1. 父件出库，读父件当前成本算出库总成本
            $parentStock = DB::table('stocks')
                ->where('product_id', $s->parent_product_id)
                ->where('warehouse_id', $wid)
                ->lockForUpdate()
                ->first();
            $parentCost = $parentStock ? (float) $parentStock->cost_price : 0;
            $this->stockService->stockOut(
                (int) $s->parent_product_id, $wid, $splitQty, $s->id, 'Disassembly'
            );
            $parentTotalCost = round($parentCost * $splitQty, 2);

            // 2. 子件按售价比例分摊（price_small 为 0 时按 split_total 等比分摊）
            $items = $s->items;
            $priceMap = [];
            $priceSum = 0.0;
            $qtySum = 0;
            foreach ($items as $item) {
                $price = (float) ($item->product?->price_small ?? 0);
                $priceMap[$item->id] = $price;
                $priceSum += $price;
                $qtySum += (int) $item->split_total;
            }

            foreach ($items as $item) {
                $splitTotal = (int) $item->split_total;
                if ($splitTotal <= 0) {
                    continue;
                }
                if ($priceSum > 0) {
                    $share = $parentTotalCost * ($priceMap[$item->id] / $priceSum);
                } else {
                    $share = $qtySum > 0 ? $parentTotalCost * ($splitTotal / $qtySum) : 0;
                }
                $newCost = $splitTotal > 0 ? $share / $splitTotal : 0;

                $childStock = DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $wid)
                    ->lockForUpdate()
                    ->first();
                $oldQty = $childStock ? (int) $childStock->quantity : 0;
                $oldCost = $childStock ? (float) $childStock->cost_price : 0;
                $weightedCost = ($oldQty + $splitTotal) > 0
                    ? round(($oldQty * $oldCost + $splitTotal * $newCost) / ($oldQty + $splitTotal), 2)
                    : round($newCost, 2);

                $this->stockService->stockIn(
                    (int) $item->product_id, $wid, $splitTotal, $weightedCost, $s->id, 'Disassembly'
                );
                DB::table('products')->where('id', $item->product_id)->update([
                    'cost_price' => $weightedCost, 'updated_at' => now(),
                ]);
                DB::table('product_cost_history')->insert([
                    'product_id' => $item->product_id, 'warehouse_id' => $wid,
                    'old_cost' => $oldCost, 'new_cost' => $weightedCost,
                    'old_qty' => $oldQty, 'new_qty' => $oldQty + $splitTotal,
                    'change_type' => 'split', 'related_id' => $s->id, 'related_type' => 'Disassembly',
                    'operator_id' => $adminId, 'operator_name' => $adminName,
                    'remark' => '拆分入库结转：'.$s->split_no,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $item->update([
                    'unit_cost' => $weightedCost,
                    'total_cost' => round($splitTotal * $weightedCost, 2),
                ]);
            }

            // 3. 经营历程
            DB::table('order_operation_logs')->insert([
                'order_id' => $s->id, 'order_no' => $s->split_no,
                'order_type' => 'disassembly', 'user_id' => $adminId, 'user_name' => $adminName,
                'operator_id' => $adminId, 'operator_name' => $adminName,
                'action' => 'approve', 'action_label' => '拆分审核通过',
                'detail' => '商品拆分审核通过：'.$s->parent_product_name.'×'.$splitQty.
                    '，拆出子件'.$items->count().'种，分摊总成本¥'.number_format($parentTotalCost, 2),
                'remark' => $comment, 'from_status' => 'pending', 'to_status' => 'approved',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // 4. 更新拆分单
            $s->status = 'approved';
            $s->approved_by = $adminId;
            $s->approved_at = now();
            $s->approval_comment = $comment;
            $s->total_cost = $parentTotalCost;
            $s->save();

            return $s->fresh(['items.product']);
        });
    }

    /**
     * 取子件成本价：stocks.cost_price > 0 用之，否则 products.cost_price，否则明细 unit_cost。
     */
    private function resolveCost(?object $stock, float $fallback, int $productId): float
    {
        if ($stock && (float) $stock->cost_price > 0) {
            return (float) $stock->cost_price;
        }
        $productCost = (float) DB::table('products')->where('id', $productId)->value('cost_price');
        if ($productCost > 0) {
            return $productCost;
        }

        return $fallback;
    }
}
