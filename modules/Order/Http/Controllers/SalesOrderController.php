<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Stock\Models\Warehouse;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesOrder::with(['customer', 'warehouse', 'items.product']);
        if ($request->filled('keyword')) {
            $query->where('order_no', 'like', '%'.$request->keyword.'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        $query->orderBy('id', 'desc');
        $orders = $query->paginate($request->integer('per_page', 20));
        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', true)->get();

        return response()->json(['data' => $orders, 'customers' => $customers, 'warehouses' => $warehouses]);
    }

    public function show(SalesOrder $salesOrder)
    {
        $salesOrder->load(['customer', 'warehouse', 'items.product']);

        return response()->json(['data' => $salesOrder]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);
        $orderNo = 'SO'.date('YmdHis').strtoupper(Str::random(4));
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $order = SalesOrder::create(array_merge($validated, [
                'order_no' => $orderNo,
                'status' => 'draft',
                'total_amount' => 0,
                'total_qty' => 0,
                'created_by' => auth('admin')->id(),
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

            return response()->json(['message' => '创建失败: '.$e->getMessage()], 500);
        }
    }

    public function update(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以编辑'], 422);
        }
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
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
            $salesOrder->update($validated);
            $salesOrder->items()->delete();
            foreach ($request->items as $itemData) {
                $item = $salesOrder->items()->create(array_merge($itemData, [
                    'amount' => $itemData['quantity'] * $itemData['price'],
                ]));
                $totalAmount += $item->amount;
                $totalQty += $item->quantity;
            }
            $salesOrder->update(['total_amount' => $totalAmount, 'total_qty' => $totalQty]);
            DB::commit();

            return response()->json(['data' => $salesOrder->fresh(['items.product']), 'message' => '更新成功']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => '更新失败: '.$e->getMessage()], 500);
        }
    }

    public function destroy(SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以删除'], 422);
        }
        $salesOrder->delete();

        return response()->json(['message' => '删除成功']);
    }

    public function approve(SalesOrder $salesOrder)
    {
        // 与采购单同一套前置校验：只有草稿能审批，避免状态被反复翻转、审计字段被覆盖
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态的订单可以审批'], 422);
        }
        $salesOrder->update(['status' => 'approved', 'approved_by' => auth('admin')->id(), 'approved_at' => now()]);

        return response()->json(['message' => '审批成功']);
    }

    public function statistics()
    {
        $stats = [
            'total_orders' => SalesOrder::count(),
            'total_amount' => SalesOrder::sum('total_amount'),
            'pending' => SalesOrder::where('status', 'draft')->count(),
            'approved' => SalesOrder::where('status', 'approved')->count(),
        ];

        return response()->json(['data' => $stats]);
    }
}
