<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Assembly;
use Modules\Order\Models\Split;
use Modules\Stock\Services\StockService;

/**
 * 组装/拆分审核联动 + 成本结转引擎。
 */
class AssemblyService
{
    public function __construct(private StockService $stockService) {}

    /**
     * 组装审核联动：子件出库 + 父件加权入库 + 成本结转 + 经营历程
     */
    public function approveAssembly(Assembly $a, int $adminId, string $adminName, ?string $comment): array
    {
        return DB::transaction(function () use ($a, $adminId, $adminName, $comment) {
            $wid = (int) $a->warehouse_id;
            $newQty = (int) $a->quantity;
            $totalCost = 0.0;

            // 1. 子件出库，累加总成本
            foreach ($a->items as $item) {
                $usage = (int) $item->total_usage;
                if ($usage <= 0) continue;

                // 校验可用库存
                $childStock = DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $wid)
                    ->lockForUpdate()
                    ->first();
                $available = ($childStock ? (int)$childStock->quantity : 0) -
                             ($childStock ? (int)$childStock->frozen_qty : 0);
                if ($available < $usage) {
                    throw new \RuntimeException(sprintf(
                        '子件 "%s" 可用库存不足：可用 %d，需要 %d',
                        $item->product_name ?? "商品 #{$item->product_id}",
                        $available,
                        $usage
                    ));
                }

                $unitCost = $this->resolveUnitCost($item, $childStock);
                $lineCost = round($usage * $unitCost, 2);
                $totalCost += $lineCost;

                // 使用 stockService 出库
                $this->stockService->stockOut(
                    (int)$item->product_id, $wid, $usage, $a->id, 'Assembly'
                );

                // 更新明细行成本
                DB::table('assembly_order_items')
                    ->where('id', $item->id)
                    ->update([
                        'unit_cost' => $unitCost,
                        'total_cost' => $lineCost,
                        'updated_at' => now(),
                    ]);
            }

            // 2. 父件加权平均成本
            $parentStock = DB::table('stocks')
                ->where('product_id', $a->parent_product_id)
                ->where('warehouse_id', $wid)
                ->lockForUpdate()
                ->first();
            $oldQty = $parentStock ? (int)$parentStock->quantity : 0;
            $oldCost = $parentStock ? (float)$parentStock->cost_price : 0;
            $newCost = $newQty > 0 ? $totalCost / $newQty : 0;
            $weightedCost = ($oldQty + $newQty) > 0
                ? round(($oldQty * $oldCost + $newQty * $newCost) / ($oldQty + $newQty), 2)
                : round($newCost, 2);

            // 3. 父件入库
            $this->stockService->stockIn(
                (int)$a->parent_product_id, $wid, $newQty, $weightedCost, $a->id, 'Assembly'
            );

            // 4. 同步 products.cost_price
            DB::table('products')
                ->where('id', $a->parent_product_id)
                ->update([
                    'cost_price' => $weightedCost,
                    'updated_at' => now(),
                ]);

            // 5. 成本变动历史
            DB::table('product_cost_history')->insert([
                'product_id' => $a->parent_product_id,
                'warehouse_id' => $wid,
                'old_cost' => $oldCost,
                'new_cost' => $weightedCost,
                'old_qty' => $oldQty,
                'new_qty' => $oldQty + $newQty,
                'change_type' => 'assembly',
                'related_id' => $a->id,
                'related_type' => 'Assembly',
                'operator_id' => $adminId,
                'operator_name' => $adminName,
                'remark' => '组装入库结转：' . $a->assembly_no,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 6. 经营历程
            DB::table('order_operation_logs')->insert([
                'order_id' => $a->id,
                'order_no' => $a->assembly_no,
                'order_type' => 'assembly',
                'user_id' => $adminId,
                'user_name' => $adminName,
                'operator_id' => $adminId,
                'operator_name' => $adminName,
                'action' => 'approve',
                'action_label' => '组装审核通过',
                'detail' => '商品组装审核通过：' . $a->parent_product_name . '×' . $newQty .
                    '，子件' . $a->items->count() . '种，总成本¥' . number_format($totalCost, 2) .
                    '，父件单位成本¥' . number_format($weightedCost, 2),
                'remark' => $comment,
                'from_status' => 'pending',
                'to_status' => 'approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 7. 更新组装单（直接使用 DB 查询，避免 Eloquent 问题）
            DB::table('assembly_orders')
                ->where('id', $a->id)
                ->update([
                    'status' => 'approved',
                    'approved_by' => $adminId,
                    'approved_at' => now(),
                    'approval_comment' => $comment,
                    'total_cost' => round($totalCost, 2),
                    'unit_cost' => $weightedCost,
                    'updated_at' => now(),
                ]);

            // 返回组装单数据
            $result = DB::table('assembly_orders')
                ->where('id', $a->id)
                ->first();

            $items = DB::table('assembly_order_items')
                ->where('assembly_order_id', $a->id)
                ->get();

            return [
                'assembly' => $result,
                'items' => $items,
            ];
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

            // 1. 父件出库
            $parentStock = DB::table('stocks')
                ->where('product_id', $s->parent_product_id)
                ->where('warehouse_id', $wid)
                ->lockForUpdate()
                ->first();
            $parentCost = $parentStock ? (float)$parentStock->cost_price : 0;
            $this->stockService->stockOut(
                (int)$s->parent_product_id, $wid, $splitQty, $s->id, 'Disassembly'
            );
            $parentTotalCost = round($parentCost * $splitQty, 2);

            // 2. 子件按售价比例分摊
            $items = $s->items;
            $priceMap = [];
            $priceSum = 0.0;
            $qtySum = 0;
            foreach ($items as $item) {
                $price = (float)($item->product?->price_small ?? 0);
                $priceMap[$item->id] = $price;
                $priceSum += $price;
                $qtySum += (int)$item->split_total;
            }

            foreach ($items as $item) {
                $splitTotal = (int)$item->split_total;
                if ($splitTotal <= 0) continue;

                // 计算分摊成本
                if ($priceSum > 0) {
                    $share = $parentTotalCost * ($priceMap[$item->id] / $priceSum);
                } else {
                    $share = $qtySum > 0 ? $parentTotalCost * ($splitTotal / $qtySum) : 0;
                }
                $newCost = $splitTotal > 0 ? $share / $splitTotal : 0;

                // 加权平均
                $childStock = DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $wid)
                    ->lockForUpdate()
                    ->first();
                $oldQty = $childStock ? (int)$childStock->quantity : 0;
                $oldCost = $childStock ? (float)$childStock->cost_price : 0;
                $weightedCost = ($oldQty + $splitTotal) > 0
                    ? round(($oldQty * $oldCost + $splitTotal * $newCost) / ($oldQty + $splitTotal), 2)
                    : round($newCost, 2);

                // 子件入库
                $this->stockService->stockIn(
                    (int)$item->product_id, $wid, $splitTotal, $weightedCost, $s->id, 'Disassembly'
                );

                // 更新 products.cost_price
                DB::table('products')
                    ->where('id', $item->product_id)
                    ->update([
                        'cost_price' => $weightedCost,
                        'updated_at' => now(),
                    ]);

                // 记录成本历史
                DB::table('product_cost_history')->insert([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $wid,
                    'old_cost' => $oldCost,
                    'new_cost' => $weightedCost,
                    'old_qty' => $oldQty,
                    'new_qty' => $oldQty + $splitTotal,
                    'change_type' => 'split',
                    'related_id' => $s->id,
                    'related_type' => 'Disassembly',
                    'operator_id' => $adminId,
                    'operator_name' => $adminName,
                    'remark' => '拆分入库结转：' . $s->split_no,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 更新明细行
                DB::table('split_order_items')
                    ->where('id', $item->id)
                    ->update([
                        'unit_cost' => $weightedCost,
                        'total_cost' => round($splitTotal * $weightedCost, 2),
                        'updated_at' => now(),
                    ]);
            }

            // 3. 经营历程
            DB::table('order_operation_logs')->insert([
                'order_id' => $s->id,
                'order_no' => $s->split_no,
                'order_type' => 'disassembly',
                'user_id' => $adminId,
                'user_name' => $adminName,
                'operator_id' => $adminId,
                'operator_name' => $adminName,
                'action' => 'approve',
                'action_label' => '拆分审核通过',
                'detail' => '商品拆分审核通过：' . $s->parent_product_name . '×' . $splitQty .
                    '，拆出子件' . $items->count() . '种，分摊总成本¥' . number_format($parentTotalCost, 2),
                'remark' => $comment,
                'from_status' => 'pending',
                'to_status' => 'approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. 更新拆分单
            DB::table('split_orders')
                ->where('id', $s->id)
                ->update([
                    'status' => 'approved',
                    'approved_by' => $adminId,
                    'approved_at' => now(),
                    'approval_comment' => $comment,
                    'total_cost' => $parentTotalCost,
                    'updated_at' => now(),
                ]);

            // 返回拆分单数据
            $result = DB::table('split_orders')
                ->where('id', $s->id)
                ->first();

            $items = DB::table('split_order_items')
                ->where('split_order_id', $s->id)
                ->get();

            return (object)[
                'id' => $result->id,
                'split_no' => $result->split_no,
                'parent_product_id' => $result->parent_product_id,
                'parent_product_name' => $result->parent_product_name,
                'warehouse_id' => $result->warehouse_id,
                'quantity' => $result->quantity,
                'total_cost' => $result->total_cost,
                'status' => $result->status,
                'approved_by' => $result->approved_by,
                'approved_at' => $result->approved_at,
                'approval_comment' => $result->approval_comment,
                'created_by' => $result->created_by,
                'items' => $items,
            ];
        });
    }

    /**
     * 取子件单位成本：用户传入 unit_cost>0 时优先使用，
     * 仅当 unit_cost=0 时从 stocks.cost_price → products.cost_price 兜底。
     */
    private function resolveUnitCost($item, ?object $stock): float
    {
        $provided = (float)$item->unit_cost;
        if ($provided > 0) {
            return $provided;
        }
        if ($stock && (float)$stock->cost_price > 0) {
            return (float)$stock->cost_price;
        }
        $productCost = (float)DB::table('products')
            ->where('id', $item->product_id)
            ->value('cost_price');
        if ($productCost > 0) {
            return $productCost;
        }

        throw new \RuntimeException(sprintf(
            '子件 "%s" 成本未设置（unit_cost=0 且无历史成本），请编辑明细后重新提交',
            $item->product_name ?? "商品 #{$item->product_id}"
        ));
    }
}
