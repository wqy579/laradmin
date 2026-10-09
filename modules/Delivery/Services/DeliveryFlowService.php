<?php

namespace Modules\Delivery\Services;

use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryCheck;
use Modules\Delivery\Models\DeliveryCheckItem;
use Modules\Delivery\Models\DeliveryLoad;
use Modules\Delivery\Models\DeliveryPick;
use Modules\Delivery\Models\DeliveryPicking;
use Modules\Delivery\Models\DeliveryPickItem;
use Modules\Delivery\Models\DeliveryTask;
use Modules\Stock\Models\StockAdjust;
use Modules\Stock\Models\StockAdjustItem;

/**
 * 配送管理跨单据流转编排。
 *
 * 封装自动生成逻辑：配货单→拣货单、拣货单→验货单、装车单→配送任务、
 * 验货差异→报损/报溢草稿（复用 StockAdjust 审核）。
 * 纯 PHP 类，方法在调用方事务内执行（不另开事务）。
 */
class DeliveryFlowService
{
    /** 由配货单生成拣货单（在 PickingController::confirm 事务内调用） */
    public function createPickFromPicking(DeliveryPicking $picking): DeliveryPick
    {
        $pickNo = $this->generateNo('PJ', 'delivery_pick', 'pick_no');

        $pick = DeliveryPick::create([
            'pick_no' => $pickNo,
            'picking_id' => $picking->id,
            'picking_no' => $picking->picking_no,
            'customer_id' => $picking->customer_id,
            'customer_name' => $picking->customer_name,
            'warehouse_id' => $picking->warehouse_id,
            'pick_date' => now()->toDateString(),
            'total_skus' => $picking->total_skus,
            'total_qty' => $picking->total_qty,
            'status' => DeliveryPick::STATUS_PENDING,
        ]);

        foreach ($picking->items as $i => $item) {
            DeliveryPickItem::create([
                'pick_id' => $pick->id,
                'picking_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'spec' => $item->spec,
                'unit' => $item->unit,
                'pick_qty' => (int) $item->quantity,
                'actual_qty' => (int) $item->quantity,
                'short_qty' => 0,
                'sort' => $i,
            ]);
        }

        return $pick;
    }

    /** 由拣货单生成验货单（在 PickController::confirm 事务内调用） */
    public function createCheckFromPick(DeliveryPick $pick): DeliveryCheck
    {
        $checkNo = $this->generateNo('YH', 'delivery_check', 'check_no');

        $check = DeliveryCheck::create([
            'check_no' => $checkNo,
            'pick_id' => $pick->id,
            'pick_no' => $pick->pick_no,
            'customer_id' => $pick->customer_id,
            'customer_name' => $pick->customer_name,
            'check_date' => now()->toDateString(),
            'total_skus' => $pick->total_skus,
            'expected_qty' => $pick->total_qty,
            'actual_qty' => $pick->items->sum('actual_qty'),
            'diff_qty' => 0,
            'status' => DeliveryCheck::STATUS_PENDING,
        ]);

        $diffTotal = 0;
        $actualTotal = 0;
        foreach ($pick->items as $i => $item) {
            $actualQty = (int) $item->actual_qty;
            $pickQty = (int) $item->pick_qty;
            $diff = $pickQty - $actualQty;
            DeliveryCheckItem::create([
                'check_id' => $check->id,
                'pick_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'spec' => $item->spec,
                'unit' => $item->unit,
                'pick_qty' => $pickQty,
                'actual_qty' => $actualQty,
                'diff_qty' => $diff,
                'sort' => $i,
            ]);
            $diffTotal += $diff;
            $actualTotal += $actualQty;
        }

        $check->update([
            'actual_qty' => $actualTotal,
            'diff_qty' => $diffTotal,
        ]);

        return $check;
    }

    /**
     * 由装车单生成配送任务（一验货单一任务）。
     * 按 load_items 按 check_id 分组，每组一个任务。
     */
    public function createTasksFromLoad(DeliveryLoad $load): array
    {
        $tasks = [];
        $items = $load->items->groupBy('check_id');
        foreach ($items as $checkId => $group) {
            $first = $group->first();
            $taskNo = $this->generateNo('RW', 'delivery_task', 'task_no');
            $totalQty = $group->sum('quantity');
            $orderAmount = $group->sum('amount');
            $task = DeliveryTask::create([
                'task_no' => $taskNo,
                'load_id' => $load->id,
                'load_no' => $load->load_no,
                'check_id' => $checkId,
                'check_no' => $first->check_no,
                'sales_order_id' => $first->sales_order_id,
                'order_no' => $first->order_no,
                'customer_id' => $first->customer_id,
                'customer_name' => $first->customer_name,
                'address' => $first->address,
                'phone' => $first->phone,
                'warehouse_id' => $load->warehouse_id ?? null,
                'delivery_person_id' => $load->delivery_person_id,
                'delivery_person_name' => $load->delivery_person_name,
                'employee_id' => $load->employee_id,
                'vehicle_id' => $load->vehicle_id,
                'plate_no' => $load->plate_no,
                'total_skus' => $group->count(),
                'total_qty' => (int) $totalQty,
                'order_amount' => $orderAmount,
                'unpaid_amount' => $orderAmount,
                'status' => DeliveryTask::STATUS_PENDING,
            ]);
            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * 验货差异生成报损单草稿（文档 §5.5.4）。
     *
     * 验货实拣 < 应拣（diff_qty > 0）即破损/丢失，自动建一张 stock_loss 草稿。
     * 仅建草稿，不直接动库存——扣减在 StockAdjustController::approve 审核通过后
     * 由 StockService::adjust 完成，保留审核控制点。
     *
     * diff_qty < 0（实验>应验）在验货环节不会发生：CheckController::confirm 强制
     * actual_qty <= pick_qty，故 diff 恒 >= 0。报溢只会来自库存盘点，不是验货，
     * 因此这里只处理报损方向，不为不可达的报溢写分支。
     *
     * 一张验货单可能含多条差异明细，合并到同一张调整单。无差异返回 null。
     */
    public function createStockAdjustFromCheckDiff(
        DeliveryCheck $check,
        int $warehouseId,
        ?int $adminId,
        ?string $adminName,
    ): ?StockAdjust {
        $diffItems = $check->items->filter(fn ($i) => (int) $i->diff_qty > 0)->values();
        if ($diffItems->isEmpty()) {
            return null;
        }

        // 预取库存与商品档案（一次查全，避免 N+1）
        $productIds = $diffItems->pluck('product_id')->unique()->all();
        $stockMap = DB::table('stocks')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');
        $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

        $totalQty = 0.0;
        $totalAmount = 0.0;
        $itemRows = [];

        foreach ($diffItems as $item) {
            $productId = (int) $item->product_id;
            $product = $productMap->get($productId);
            if (! $product) {
                continue;
            }
            $stock = $stockMap->get($productId);
            $beforeQty = (float) ($stock?->quantity ?? 0);
            $adjustQty = -(int) $item->diff_qty; // 报损方向取反：实验少了→扣减
            $afterQty = $beforeQty + $adjustQty;
            $unitCost = (float) ($stock?->cost_price ?? $product->cost_price ?? 0);
            $totalCost = round(abs($adjustQty) * $unitCost, 2);

            $totalQty += $adjustQty;
            $totalAmount += $totalCost;

            $itemRows[] = [
                'product_id' => $productId,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'spec' => $product->spec,
                'unit' => $product->price_unit_small,
                'before_qty' => $beforeQty,
                'adjust_qty' => $adjustQty,
                'after_qty' => $afterQty,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'remark' => $item->remark,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($itemRows)) {
            return null;
        }

        $adjust = StockAdjust::create([
            'adjust_no' => $this->generateNo('TZ', 'stock_adjusts', 'adjust_no'),
            'warehouse_id' => $warehouseId,
            'adjust_date' => now()->toDateString(),
            'adjust_type' => StockAdjust::TYPE_STOCK_LOSS,
            'status' => StockAdjust::STATUS_DRAFT,
            'total_qty' => round($totalQty, 2),
            'total_amount' => round($totalAmount, 2),
            'reason' => '验货差异自动生成：'.$check->check_no,
            'created_by' => $adminId,
            'creator_name' => $adminName ?? '系统',
        ]);

        foreach ($itemRows as &$row) {
            $row['adjust_id'] = $adjust->id;
        }
        unset($row);
        StockAdjustItem::insert($itemRows);

        return $adjust;
    }

    /** 单号生成：前缀+Ymd+6位流水（参考 StockAdjustController::generateNo） */
    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
