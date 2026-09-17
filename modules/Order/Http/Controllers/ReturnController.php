<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Order\Models\ReturnOrder as ReturnModel;
use Modules\Order\Models\Supplier;
use Modules\Stock\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = ReturnModel::with(['supplier', 'warehouse', 'items.product']);
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
        $returns = $query->paginate($request->integer('per_page', 20));
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', true)->get();
        return response()->json(['data' => $returns, 'suppliers' => $suppliers, 'warehouses' => $warehouses]);
    }

    public function show(ReturnModel $return)
    {
        $return->load(['supplier', 'warehouse', 'items.product']);
        return response()->json(['data' => $return]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'return_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);
        $orderNo = 'RT' . date('YmdHis') . strtoupper(Str::random(4));
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $return = ReturnModel::create(array_merge($validated, [
                'order_no' => $orderNo,
                'status' => 'draft',
                'total_amount' => 0,
                'total_qty' => 0,
                'created_by' => auth('admin')->id(),
            ]));
            foreach ($request->items as $itemData) {
                $item = $return->items()->create(array_merge($itemData, [
                    'amount' => $itemData['quantity'] * $itemData['price'],
                ]));
                $totalAmount += $item->amount;
                $totalQty += $item->quantity;
            }
            $return->update(['total_amount' => $totalAmount, 'total_qty' => $totalQty]);
            DB::commit();
            return response()->json(['data' => $return->fresh(['items.product']), 'message' => '创建成功']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => '创建失败: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, ReturnModel $return)
    {
        if ($return->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以编辑'], 422);
        }
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'return_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $return->update($validated);
            $return->items()->delete();
            foreach ($request->items as $itemData) {
                $item = $return->items()->create(array_merge($itemData, [
                    'amount' => $itemData['quantity'] * $itemData['price'],
                ]));
                $totalAmount += $item->amount;
                $totalQty += $item->quantity;
            }
            $return->update(['total_amount' => $totalAmount, 'total_qty' => $totalQty]);
            DB::commit();
            return response()->json(['data' => $return->fresh(['items.product']), 'message' => '更新成功']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => '更新失败: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(ReturnModel $return)
    {
        if ($return->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以删除'], 422);
        }
        $return->delete();
        return response()->json(['message' => '删除成功']);
    }

    public function approve(ReturnModel $return)
    {
        if ($return->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态的退货单可以审批'], 422);
        }
        $return->update(['status' => 'approved', 'approved_by' => auth('admin')->id(), 'approved_at' => now()]);
        return response()->json(['message' => '审批成功']);
    }

    public function process(ReturnModel $return)
    {
        if ($return->status !== 'approved') {
            return response()->json(['message' => '只有已审批的退货单可以处理'], 422);
        }
        DB::beginTransaction();
        try {
            foreach ($return->items as $item) {
                // 不变量 I2：任何情况下不允许把库存扣成负数。
                // 与 TransferController::execute 同一套「先校验充足、不足则整体回滚」，
                // 退货此前是无条件 decrement，可以把任意商品退成负库存。
                $stock = DB::table('stocks')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $return->warehouse_id)
                    ->lockForUpdate()
                    ->first();
                $available = (int) ($stock->quantity ?? 0);
                if ($available < $item->quantity) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "库存不足：该仓库现有 {$available} 件，无法退货 {$item->quantity} 件",
                    ], 422);
                }
                DB::table('stocks')
                    ->where('id', $stock->id)
                    ->decrement('quantity', $item->quantity);
            }
            $return->update(['status' => 'completed']);
            DB::commit();
            return response()->json(['message' => '退货处理成功']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => '退货处理失败: ' . $e->getMessage()], 500);
        }
    }

    public function statistics()
    {
        $stats = [
            'total_returns' => ReturnModel::count(),
            'total_amount' => ReturnModel::sum('total_amount'),
            'pending' => ReturnModel::where('status', 'draft')->count(),
            'approved' => ReturnModel::where('status', 'approved')->count(),
            'completed' => ReturnModel::where('status', 'completed')->count(),
        ];
        return response()->json(['data' => $stats]);
    }
}
