<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Stock\Models\Transfer;
use Modules\Stock\Models\Warehouse;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $query = Transfer::with(['fromWarehouse', 'toWarehouse', 'items.product']);
        if ($request->filled('keyword')) {
            $query->where('order_no', 'like', '%'.$request->keyword.'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $query->orderBy('id', 'desc');
        $transfers = $query->paginate($request->integer('per_page', 20));
        $warehouses = Warehouse::where('is_active', true)->where('type', 'normal')->get();

        return response()->json(['data' => $transfers, 'warehouses' => $warehouses]);
    }

    public function show(Transfer $transfer)
    {
        $transfer->load(['fromWarehouse', 'toWarehouse', 'items.product']);

        return response()->json(['data' => $transfer]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'transfer_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);
        $orderNo = 'TF'.date('YmdHis').strtoupper(Str::random(4));
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $transfer = Transfer::create(array_merge($validated, [
                'order_no' => $orderNo,
                'status' => 'draft',
                'total_amount' => 0,
                'total_qty' => 0,
                'created_by' => auth('admin')->id(),
            ]));
            foreach ($request->items as $itemData) {
                $item = $transfer->items()->create(array_merge($itemData, [
                    'amount' => $itemData['quantity'] * $itemData['price'],
                ]));
                $totalAmount += $item->amount;
                $totalQty += $item->quantity;
            }
            $transfer->update(['total_amount' => $totalAmount, 'total_qty' => $totalQty]);
            DB::commit();

            return response()->json(['data' => $transfer->fresh(['items.product']), 'message' => '创建成功']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => '创建失败: '.$e->getMessage()], 500);
        }
    }

    public function update(Request $request, Transfer $transfer)
    {
        if ($transfer->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以编辑'], 422);
        }
        $validated = $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'transfer_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $transfer->update($validated);
            $transfer->items()->delete();
            foreach ($request->items as $itemData) {
                $item = $transfer->items()->create(array_merge($itemData, [
                    'amount' => $itemData['quantity'] * $itemData['price'],
                ]));
                $totalAmount += $item->amount;
                $totalQty += $item->quantity;
            }
            $transfer->update(['total_amount' => $totalAmount, 'total_qty' => $totalQty]);
            DB::commit();

            return response()->json(['data' => $transfer->fresh(['items.product']), 'message' => '更新成功']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => '更新失败: '.$e->getMessage()], 500);
        }
    }

    public function destroy(Transfer $transfer)
    {
        if ($transfer->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以删除'], 422);
        }
        $transfer->delete();

        return response()->json(['message' => '删除成功']);
    }

    public function approve(Transfer $transfer)
    {
        if ($transfer->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态的调拨单可以审批'], 422);
        }
        $transfer->update(['status' => 'approved']);

        return response()->json(['message' => '审批成功']);
    }

    public function execute(Transfer $transfer)
    {
        // 不变量 I2：任何情况下不允许把源仓库库存扣成负数
        if ($transfer->status !== 'approved') {
            return response()->json(['message' => '只有已审批的调拨单可以执行'], 422);
        }
        DB::beginTransaction();
        try {
            foreach ($transfer->items as $item) {
                // 先校验源库存充足，不足则整体回滚并保持单据原状态
                $source = DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->lockForUpdate()
                    ->first();
                $available = (int) ($source->quantity ?? 0);
                if ($available < $item->quantity) {
                    DB::rollBack();

                    return response()->json([
                        'message' => "库存不足：源仓库该商品现有 {$available} 件，无法调拨 {$item->quantity} 件",
                    ], 422);
                }
                // 源仓：出库腿（记账，before→after）
                $sourceBefore = (int) $source->quantity;
                DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->decrement('quantity', $item->quantity);
                $this->recordTransferHistory(
                    $item->product_id,
                    $transfer->from_warehouse_id,
                    'transfer_out',
                    -$item->quantity,
                    $sourceBefore,
                    $sourceBefore - $item->quantity,
                    $transfer->id,
                    '调拨出库 '.$transfer->order_no,
                );

                // 目标仓：入库腿（记账，before→after；无行则建行）
                $destBefore = (int) DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->to_warehouse_id)
                    ->value('quantity');
                $stock = DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->to_warehouse_id)
                    ->first();
                if ($stock) {
                    DB::table('stocks')
                        ->where('id', $stock->id)
                        ->increment('quantity', $item->quantity);
                } else {
                    DB::table('stocks')->insert([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'quantity' => $item->quantity,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $this->recordTransferHistory(
                    $item->product_id,
                    $transfer->to_warehouse_id,
                    'transfer_in',
                    $item->quantity,
                    $destBefore,
                    $destBefore + $item->quantity,
                    $transfer->id,
                    '调拨入库 '.$transfer->order_no,
                );

                // 重算商品总库存（对齐 StockService 其它写路径的口径）
                $this->syncProductStockQty($item->product_id);
            }
            $transfer->update(['status' => 'completed']);
            DB::commit();

            return response()->json(['message' => '调拨成功']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => '调拨失败: '.$e->getMessage()], 500);
        }
    }

    public function statistics()
    {
        $stats = [
            'total_transfers' => Transfer::count(),
            'total_amount' => Transfer::sum('total_amount'),
            'pending' => Transfer::where('status', 'draft')->count(),
            'approved' => Transfer::where('status', 'approved')->count(),
            'completed' => Transfer::where('status', 'completed')->count(),
        ];

        return response()->json(['data' => $stats]);
    }

    /** 写一条调拨库存变动流水（stocks_history），与 StockService::recordHistory 同构。 */
    private function recordTransferHistory(int $productId, int $warehouseId, string $changeType, int $changeQty, int $beforeQty, int $afterQty, int $relatedId, string $remark): void
    {
        DB::table('stocks_history')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'change_type' => $changeType,
            'change_qty' => $changeQty,
            'before_qty' => $beforeQty,
            'after_qty' => $afterQty,
            'related_id' => $relatedId,
            'related_type' => 'Transfer',
            'remark' => $remark,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 重算 products.stock_qty = SUM(quantity - frozen_qty)，对齐 StockService 口径。 */
    private function syncProductStockQty(int $productId): void
    {
        $row = DB::table('stocks')->where('product_id', $productId)
            ->selectRaw('COALESCE(SUM(quantity - frozen_qty), 0) as available')->first();
        DB::table('products')->where('id', $productId)->update([
            'stock_qty' => (int) ($row->available ?? 0),
            'updated_at' => now(),
        ]);
    }
}
