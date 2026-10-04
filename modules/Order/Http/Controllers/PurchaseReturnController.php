<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\PurchaseReturn;
use Modules\Stock\Services\StockService;

class PurchaseReturnController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService)
    {
    }

    public function index(Request $request)
    {
        $query = PurchaseReturn::with(['warehouse', 'creator', 'approver']);
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('return_no', 'like', "%{$request->keyword}%")
                    ->orWhere('stock_in_no', 'like', "%{$request->keyword}%");
            });
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->where('return_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('return_date', '<=', $request->end_date);
        }
        $list = $query->orderByDesc('id')->paginate(
            $request->integer('page_size', 20), ['*'], 'page', $request->integer('page', 1)
        );

        return $this->paginated($list);
    }

    public function show($id)
    {
        $return = PurchaseReturn::with(['warehouse', 'creator', 'approver', 'items.product'])->find($id);
        if ($return === null) {
            return $this->notFound();
        }

        return $this->success($return);
    }

    /** 根据原入库单获取可退货商品 */
    public function stockInProducts(Request $request)
    {
        $request->validate(['stock_in_id' => 'required|exists:stock_ins,id']);
        $stockIn = DB::table('stock_ins')->find($request->stock_in_id);
        if ($stockIn === null) {
            return $this->notFound();
        }
        $items = DB::table('stock_in_items')->where('stock_in_id', $request->stock_in_id)->get();
        $returnedMap = $this->getReturnedQtyByStockIn((int) $request->stock_in_id);

        $list = $items->map(function ($it) use ($returnedMap) {
            $returned = $returnedMap[$it->product_id] ?? 0;
            $available = max(0, (int) $it->quantity - $returned);
            $p = DB::table('products')->where('id', $it->product_id)->first();

            return [
                'product_id' => $it->product_id,
                'product_code' => $p?->code ?? '',
                'product_name' => $p?->name ?? '',
                'spec' => $p?->spec ?? '',
                'unit' => $p?->price_unit_small ?? '',
                'original_qty' => (int) $it->quantity,
                'returned_qty' => $returned,
                'available_qty' => $available,
                'price' => (float) $it->price,
            ];
        })->values();

        return $this->success([
            'stock_in' => ['id' => $stockIn->id, 'order_no' => $stockIn->order_no, 'supplier_id' => $stockIn->supplier_id],
            'items' => $list,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'stock_in_id' => 'nullable|exists:stock_ins,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'return_date' => 'required|date',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:20',
            'remark' => 'nullable|string',
            'submit_for_approval' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.return_price' => 'required|numeric|min:0',
        ]);

        $adminId = auth('admin')?->id();
        $stockInNo = null;
        $supplierName = null;
        if (!empty($validated['stock_in_id'])) {
            $stockIn = DB::table('stock_ins')->find($validated['stock_in_id']);
            $stockInNo = $stockIn?->order_no;
            $validated['supplier_id'] = $validated['supplier_id'] ?? $stockIn?->supplier_id;
        }
        if (!empty($validated['supplier_id'])) {
            $supplierName = DB::table('suppliers')->where('id', $validated['supplier_id'])->value('name');
        }

        $returnedMap = !empty($validated['stock_in_id'])
            ? $this->getReturnedQtyByStockIn((int) $validated['stock_in_id'])
            : [];
        $inItems = !empty($validated['stock_in_id'])
            ? DB::table('stock_in_items')->where('stock_in_id', $validated['stock_in_id'])->get()->keyBy('product_id')
            : collect();

        $items = [];
        $totalQty = 0;
        $totalAmount = 0.0;
        foreach ($validated['items'] as $item) {
            if (!empty($validated['stock_in_id'])) {
                $orig = $inItems->get($item['product_id']);
                if ($orig === null) {
                    return $this->error("商品{$item['product_id']}不在原入库单中", 422);
                }
                $returned = $returnedMap[$item['product_id']] ?? 0;
                $available = (int) $orig->quantity - $returned;
                if ($item['return_qty'] > $available) {
                    return $this->error("退货数量超过可退数量（可退{$available}）", 422);
                }
            }
            $amount = round($item['return_qty'] * $item['return_price'], 2);
            $p = DB::table('products')->where('id', $item['product_id'])->first();
            $totalQty += $item['return_qty'];
            $totalAmount += $amount;
            $items[] = [
                'product_id' => $item['product_id'],
                'product_code' => $p?->code ?? '',
                'product_name' => $p?->name ?? '',
                'spec' => $p?->spec ?? '',
                'unit' => $p?->price_unit_small ?? '',
                'original_qty' => (int) ($orig->quantity ?? 0),
                'returned_qty' => $returnedMap[$item['product_id']] ?? 0,
                'return_qty' => $item['return_qty'],
                'return_price' => $item['return_price'],
                'return_amount' => $amount,
            ];
        }

        return DB::transaction(function () use ($validated, $stockInNo, $supplierName, $items, $totalQty, $totalAmount, $adminId) {
            $status = !empty($validated['submit_for_approval']) ? 'pending' : 'draft';
            $return = PurchaseReturn::create([
                'return_no' => $this->generateNo(),
                'stock_in_id' => $validated['stock_in_id'] ?? null,
                'stock_in_no' => $stockInNo,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'supplier_name' => $supplierName,
                'warehouse_id' => $validated['warehouse_id'],
                'return_date' => $validated['return_date'],
                'status' => $status,
                'total_skus' => count($items),
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'contact' => $validated['contact'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
            ]);
            foreach ($items as $item) {
                $item['return_id'] = $return->id;
                DB::table('purchase_return_items')->insert(array_merge($item, ['created_at' => now(), 'updated_at' => now()]));
            }

            return $this->created($return->fresh(['items.product']), $status === 'pending' ? '已提交审核' : '已保存草稿');
        });
    }

    public function update(Request $request, $id)
    {
        $return = PurchaseReturn::find($id);
        if ($return === null) {
            return $this->notFound();
        }
        if ($return->status !== 'draft') {
            return $this->error('只有草稿状态可以编辑', 422);
        }
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'return_date' => 'required|date',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:20',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.return_price' => 'required|numeric|min:0',
        ]);

        $totalQty = 0;
        $totalAmount = 0.0;
        $itemsData = [];
        foreach ($validated['items'] as $item) {
            $amount = round($item['return_qty'] * $item['return_price'], 2);
            $p = DB::table('products')->where('id', $item['product_id'])->first();
            $totalQty += $item['return_qty'];
            $totalAmount += $amount;
            $itemsData[] = [
                'product_id' => $item['product_id'],
                'product_code' => $p?->code ?? '',
                'product_name' => $p?->name ?? '',
                'spec' => $p?->spec ?? '',
                'unit' => $p?->price_unit_small ?? '',
                'original_qty' => 0, 'returned_qty' => 0,
                'return_qty' => $item['return_qty'],
                'return_price' => $item['return_price'],
                'return_amount' => $amount,
            ];
        }

        return DB::transaction(function () use ($return, $validated, $itemsData, $totalQty, $totalAmount) {
            $return->update([
                'warehouse_id' => $validated['warehouse_id'],
                'return_date' => $validated['return_date'],
                'contact' => $validated['contact'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'remark' => $validated['remark'] ?? null,
                'total_skus' => count($itemsData),
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
            ]);
            DB::table('purchase_return_items')->where('return_id', $return->id)->delete();
            foreach ($itemsData as $item) {
                $item['return_id'] = $return->id;
                DB::table('purchase_return_items')->insert(array_merge($item, ['created_at' => now(), 'updated_at' => now()]));
            }

            return $this->success($return->fresh(['items.product']), '更新成功');
        });
    }

    public function submit($id)
    {
        $return = PurchaseReturn::find($id);
        if ($return === null) {
            return $this->notFound();
        }
        if ($return->status !== 'draft') {
            return $this->error('只有草稿状态可以提交', 422);
        }
        $return->status = 'pending';
        $return->save();

        return $this->success($return, '已提交审核');
    }

    public function approve(Request $request, $id)
    {
        $return = PurchaseReturn::with(['items'])->find($id);
        if ($return === null) {
            return $this->notFound();
        }
        if ($return->status === 'approved') {
            return $this->success($return, '已审核');
        }
        if ($return->status !== 'pending') {
            return $this->error('只有待审核状态可以审核', 422);
        }
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);
        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->name ?? ($admin?->nickname ?? '管理员');
        $totalAmount = (float) $return->total_amount;

        return DB::transaction(function () use ($return, $validated, $adminId, $adminName, $totalAmount) {
            // 1. 退货出库：库存减少
            foreach ($return->items as $item) {
                if ($item->return_qty > 0) {
                    $this->stockService->stockOut(
                        (int) $item->product_id,
                        (int) $return->warehouse_id,
                        (int) $item->return_qty,
                        $return->id,
                        'PurchaseReturn'
                    );
                }
            }
            // 2. 冲减供应商应付
            if ($return->supplier_id) {
                DB::table('suppliers')->where('id', $return->supplier_id)->decrement('balance', $totalAmount);
            }
            // 3. 更新状态
            $return->status = 'approved';
            $return->approved_by = $adminId;
            $return->approved_at = now();
            $return->approval_comment = $validated['approval_comment'] ?? null;
            $return->payable_offset = $totalAmount;
            $return->save();
            // 4. 经营历程
            DB::table('order_operation_logs')->insert([
                'order_id' => $return->id, 'order_no' => $return->return_no,
                'order_type' => 'purchase_return', 'user_id' => $adminId, 'user_name' => $adminName,
                'operator_id' => $adminId, 'operator_name' => $adminName,
                'action' => 'approve', 'action_label' => '采购退货审核通过',
                'detail' => '采购退货审核通过，退货金额¥'.number_format($totalAmount, 2),
                'remark' => $validated['approval_comment'] ?? null,
                'from_status' => 'pending', 'to_status' => 'approved',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->success($return->fresh(['items.product']), '审核通过，已退货出库并冲减应付');
        });
    }

    public function reject(Request $request, $id)
    {
        $return = PurchaseReturn::find($id);
        if ($return === null) {
            return $this->notFound();
        }
        if ($return->status !== 'pending') {
            return $this->error('只有待审核状态可以驳回', 422);
        }
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);
        $return->status = 'draft';
        $return->approval_comment = $validated['approval_comment'] ?? null;
        $return->save();

        return $this->success($return, '已驳回');
    }

    public function cancel($id)
    {
        $return = PurchaseReturn::find($id);
        if ($return === null) {
            return $this->notFound();
        }
        if ($return->status === 'approved') {
            return $this->error('已审核的退货单不能取消', 422);
        }
        $return->status = 'cancelled';
        $return->save();

        return $this->success($return, '已取消');
    }

    public function destroy($id)
    {
        $return = PurchaseReturn::find($id);
        if ($return === null) {
            return $this->notFound();
        }
        if ($return->status !== 'draft') {
            return $this->error('只有草稿状态可以删除', 422);
        }
        DB::table('purchase_return_items')->where('return_id', $return->id)->delete();
        $return->delete();

        return $this->noContent();
    }

    private function getReturnedQtyByStockIn(int $stockInId): array
    {
        $rows = DB::table('purchase_return_items as pri')
            ->join('purchase_returns as pr', 'pr.id', '=', 'pri.return_id')
            ->where('pr.stock_in_id', $stockInId)
            ->where('pr.status', 'approved')
            ->get(['pri.product_id', 'pri.return_qty']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->product_id] = ($map[$r->product_id] ?? 0) + $r->return_qty;
        }

        return $map;
    }

    private function generateNo(): string
    {
        $prefix = 'PR'.date('Ymd');
        $last = PurchaseReturn::where('return_no', 'like', $prefix.'%')->orderByDesc('return_no')->value('return_no');
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
