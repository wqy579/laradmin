<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Order\Models\PurchaseOrder;
use Modules\Order\Models\Supplier;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Warehouse;
use Modules\Stock\Services\StockService;

class PurchaseOrderController extends Controller
{
    public function __construct(private StockService $stocks) {}

    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'warehouse', 'items.product']);
        if ($request->filled('keyword')) {
            $query->where('order_no', 'like', '%'.$request->keyword.'%');
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
            'items.*.qty_large' => 'nullable|integer|min:0',
            'items.*.qty_medium' => 'nullable|integer|min:0',
            'items.*.qty_small' => 'nullable|integer|min:0',
            'items.*.price_large' => 'nullable|numeric|min:0',
            'items.*.price_medium' => 'nullable|numeric|min:0',
            'items.*.price_small' => 'nullable|numeric|min:0',
        ]);
        $orderNo = 'PO'.date('YmdHis').strtoupper(Str::random(4));
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $order = PurchaseOrder::create(array_merge($validated, [
                'order_no' => $orderNo,
                'status' => 'draft',
                'total_amount' => 0,
                'total_qty' => 0,
                'created_by' => auth('admin')->id(),
            ]));
            foreach ($request->items as $itemData) {
                [$quantity, $amount, $price] = $this->computeItemQtyAmount($itemData);
                $item = $order->items()->create(array_merge($itemData, [
                    'quantity' => $quantity,
                    'price' => $price,
                    'amount' => $amount,
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
            'items.*.qty_large' => 'nullable|integer|min:0',
            'items.*.qty_medium' => 'nullable|integer|min:0',
            'items.*.qty_small' => 'nullable|integer|min:0',
            'items.*.price_large' => 'nullable|numeric|min:0',
            'items.*.price_medium' => 'nullable|numeric|min:0',
            'items.*.price_small' => 'nullable|numeric|min:0',
        ]);
        $totalAmount = 0;
        $totalQty = 0;
        DB::beginTransaction();
        try {
            $purchaseOrder->update($validated);
            $purchaseOrder->items()->delete();
            foreach ($request->items as $itemData) {
                [$quantity, $amount, $price] = $this->computeItemQtyAmount($itemData);
                $item = $purchaseOrder->items()->create(array_merge($itemData, [
                    'quantity' => $quantity,
                    'price' => $price,
                    'amount' => $amount,
                ]));
                $totalAmount += $item->amount;
                $totalQty += $item->quantity;
            }
            $purchaseOrder->update(['total_amount' => $totalAmount, 'total_qty' => $totalQty]);
            DB::commit();

            return response()->json(['data' => $purchaseOrder->fresh(['items.product']), 'message' => '更新成功']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => '更新失败: '.$e->getMessage()], 500);
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
        // 状态机：draft --approve--> approved。无前置校验时 received 的单据还能被反复
        // 审批并把状态打回 approved，单据状态被来回翻转且审计字段被覆盖。
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态的订单可以审批'], 422);
        }
        $purchaseOrder->update(['status' => 'approved', 'approved_by' => auth('admin')->id(), 'approved_at' => now()]);

        return response()->json(['message' => '审批成功']);
    }

    public function receive(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'approved') {
            return response()->json(['message' => '只有已审批的订单可以入库'], 422);
        }
        DB::beginTransaction();
        try {
            // 采购入库：把每张明细的数量加进收货仓库。此前这里只翻状态，采购单永远不会
            // 反映到库存，出入库全靠另开一张 stock-in 手工补记。
            // cost_price 不动——采购单价不等于成本价，静默覆盖会把成本口径改脏。
            foreach ($purchaseOrder->items as $item) {
                $this->stocks->stockIn(
                    (int) $item->product_id,
                    (int) $purchaseOrder->warehouse_id,
                    (int) $item->quantity,
                );
            }
            $purchaseOrder->update(['status' => 'received']);
            DB::commit();

            return response()->json(['message' => '入库成功']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => '入库失败: '.$e->getMessage()], 500);
        }
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        // 只有还没入库的单据能作废；已入库的库存变动已发生，不能一键抹掉。
        if (! in_array($purchaseOrder->status, ['draft', 'approved'], true)) {
            return response()->json(['message' => '只有草稿或已审批的订单可以取消'], 422);
        }
        $purchaseOrder->update(['status' => 'cancelled']);

        return response()->json(['message' => '取消成功']);
    }

    /** 折算单行：quantity=大×c+中×mc+小；amount=三档数量×三档单价之和；price 兜底取 price_small */
    private function computeItemQtyAmount(array $itemData): array
    {
        $qtyLarge = (int) ($itemData['qty_large'] ?? 0);
        $qtyMedium = (int) ($itemData['qty_medium'] ?? 0);
        $qtySmall = (int) ($itemData['qty_small'] ?? 0);
        $priceLarge = (float) ($itemData['price_large'] ?? 0);
        $priceMedium = (float) ($itemData['price_medium'] ?? 0);
        $priceSmall = (float) ($itemData['price_small'] ?? 0);

        $product = Product::find($itemData['product_id']);
        $c = (int) ($product?->unit_conversion ?? 0);
        $mc = (int) ($product?->unit_conversion_medium ?? 0);
        $quantity = $qtyLarge * $c + $qtyMedium * $mc + $qtySmall;
        $amount = round($qtyLarge * $priceLarge + $qtyMedium * $priceMedium + $qtySmall * $priceSmall, 2);

        return [$quantity, $amount, $priceSmall > 0 ? $priceSmall : (float) ($itemData['price'] ?? 0)];
    }

    public function statistics()
    {
        $stats = [
            'total_orders' => PurchaseOrder::count(),
            'total_amount' => PurchaseOrder::sum('total_amount'),
            'pending' => PurchaseOrder::where('status', 'draft')->count(),
            'approved' => PurchaseOrder::where('status', 'approved')->count(),
            'received' => PurchaseOrder::where('status', 'received')->count(),
            'cancelled' => PurchaseOrder::where('status', 'cancelled')->count(),
        ];

        return response()->json(['data' => $stats]);
    }
}
