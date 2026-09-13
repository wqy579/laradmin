<?php

namespace App\Http\Controllers\Admin\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\PurchaseOrder;
use App\Models\Business\Supplier;
use App\Models\Business\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'warehouse', 'items.product']);
        if ($request->filled('keyword')) {
            $query->where('order_no', 'like', '%' . $request->keyword . '%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        $query->orderBy('id', 'desc');
        $orders = $query->paginate($request->integer('per_page', 20));
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', true)->get();
        return response()->json(['data' => $orders, 'suppliers' => $suppliers, 'warehouses' => $warehouses]);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'warehouse', 'items.product']);
        return response()->json(['data' => $purchaseOrder]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);
        $orderNo = 'PO' . date('YmdHis') . strtoupper(Str::random(4));
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $order = PurchaseOrder::create(array_merge($validated, [
                'order_no' => $orderNo,
                'status' => 'draft',
                'total_amount' => 0,
                'total_qty' => 0,
                'created_by' => auth()->id(),
            ]));
            foreach ($request->items as $itemData) {
                $item = $order->items()->create(array_merge($itemData, [
                    'amount' => $itemData['quantity'] * $itemData['price'],
                ]));
                $totalAmount += $item->amount;
                $totalQty += $item->quantity;
            }
            $order->update(['total_amount' => $totalAmount, 'total_qty' => $totalQty]);
            DB::commit();
            return response()->json(['data' => $order->fresh(['items.product']), 'message' => '创建成功']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => '创建失败: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以编辑'], 422);
        }
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $purchaseOrder->update($validated);
            $purchaseOrder->items()->delete();
            foreach ($request->items as $itemData) {
                $item = $purchaseOrder->items()->create(array_merge($itemData, [
                    'amount' => $itemData['quantity'] * $itemData['price'],
                ]));
                $totalAmount += $item->amount;
                $totalQty += $item->quantity;
            }
            $purchaseOrder->update(['total_amount' => $totalAmount, 'total_qty' => $totalQty]);
            DB::commit();
            return response()->json(['data' => $purchaseOrder->fresh(['items.product']), 'message' => '更新成功']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => '更新失败: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以删除'], 422);
        }
        $purchaseOrder->delete();
        return response()->json(['message' => '删除成功']);
    }

    public function approve(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        return response()->json(['message' => '审批成功']);
    }

    public function receive(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'approved') {
            return response()->json(['message' => '只有已审批的订单可以入库'], 422);
        }
        $purchaseOrder->update(['status' => 'received']);
        return response()->json(['message' => '入库成功']);
    }

    public function statistics()
    {
        $stats = [
            'total_orders' => PurchaseOrder::count(),
            'total_amount' => PurchaseOrder::sum('total_amount'),
            'pending' => PurchaseOrder::where('status', 'draft')->count(),
            'approved' => PurchaseOrder::where('status', 'approved')->count(),
        ];
        return response()->json(['data' => $stats]);
    }
}
