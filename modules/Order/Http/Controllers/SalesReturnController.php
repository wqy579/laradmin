<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Models\SalesOrderItem;
use Modules\Order\Models\SalesReturn;
use Modules\Stock\Services\StockService;

class SalesReturnController extends Controller
{
    use ResponseTrait;

    protected StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    public function index(Request $request)
    {
        $query = SalesReturn::with(['customer', 'warehouse', 'salesOrder', 'creator', 'approver']);

        if ($request->filled('keyword')) {
            $kw = $request->keyword;
            $query->where(function ($q) use ($kw) {
                $q->where('return_no', 'like', "%{$kw}%")
                    ->orWhere('order_no', 'like', "%{$kw}%");
            });
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
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
            $request->integer('page_size', 20),
            ['*'],
            'page',
            $request->integer('page', 1)
        );

        return $this->paginated($list);
    }

    public function show($id)
    {
        $return = SalesReturn::with(['customer', 'warehouse', 'salesOrder', 'creator', 'approver', 'items.product'])
            ->find($id);
        if (! $return) {
            return $this->notFound();
        }

        return $this->success($return);
    }

    /** 获取指定订单的可退货商品列表 */
    public function orderProducts(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:sales_orders,id',
        ]);

        $order = SalesOrder::with(['items.product'])->find($request->order_id);
        if (! $order) {
            return $this->notFound();
        }

        // 查询该订单已有的退货记录，计算每个商品已退数量
        $returnedMap = $this->getReturnedQtyByOrder((int) $order->id);

        $items = $order->items->map(function ($item) use ($returnedMap) {
            $returned = $returnedMap[$item->product_id] ?? 0;
            $returnable = max(0, (int) $item->quantity - $returned);

            return [
                'product_id' => $item->product_id,
                'product_code' => $item->product?->code ?? '',
                'product_name' => $item->product?->name ?? '',
                'spec' => $item->product?->spec ?? '',
                'unit' => $item->product?->unit?->name ?? '',
                'order_qty' => (int) $item->quantity,
                'returned_qty' => $returned,
                'returnable_qty' => $returnable,
                'price' => (float) $item->price,
            ];
        })->values();

        $customer = $order->customer;

        return $this->success([
            'order' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'customer_id' => $order->customer_id,
                'customer_name' => $customer?->name ?? '',
                'warehouse_id' => $order->warehouse_id,
            ],
            'items' => $items,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:sales_orders,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'return_date' => 'required|date',
            'return_type' => 'nullable|string|in:quality,cancel,damage,other',
            'remark' => 'nullable|string',
            'submit_for_approval' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.return_price' => 'required|numeric|min:0',
        ]);

        $adminId = auth('admin')?->id();
        $order = SalesOrder::with(['customer'])->find($validated['order_id']);
        if (! $order) {
            return $this->error('原订单不存在', 404);
        }

        // 校验可退数量
        $returnedMap = $this->getReturnedQtyByOrder((int) $order->id);
        $orderItems = SalesOrderItem::where('sales_order_id', $order->id)->get()->keyBy('product_id');

        $items = [];
        $totalQty = 0;
        $totalAmount = 0.0;
        $totalSkus = 0;

        foreach ($validated['items'] as $item) {
            $orderItem = $orderItems->get($item['product_id']);
            if (! $orderItem) {
                return $this->error("商品{$item['product_id']}不在原订单中", 422);
            }
            $returned = $returnedMap[$item['product_id']] ?? 0;
            $returnable = (int) $orderItem->quantity - $returned;
            if ($item['return_qty'] > $returnable) {
                return $this->error("退货数量超过可退数量（可退{$returnable}）", 422);
            }

            $amount = round($item['return_qty'] * $item['return_price'], 2);
            $totalQty += $item['return_qty'];
            $totalAmount += $amount;
            $totalSkus++;

            $items[] = [
                'product_id' => $item['product_id'],
                'product_code' => $orderItem->product?->code ?? '',
                'product_name' => $orderItem->product?->name ?? '',
                'spec' => $orderItem->product?->spec ?? '',
                'unit' => $orderItem->product?->unit?->name ?? '',
                'order_qty' => (int) $orderItem->quantity,
                'returned_qty' => $returned,
                'return_qty' => $item['return_qty'],
                'return_price' => $item['return_price'],
                'return_amount' => $amount,
            ];
        }

        return DB::transaction(function () use ($validated, $order, $items, $totalQty, $totalAmount, $totalSkus, $adminId) {
            $returnNo = $this->generateNo();
            $status = ! empty($validated['submit_for_approval']) ? 'pending' : 'draft';

            $return = SalesReturn::create([
                'return_no' => $returnNo,
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer?->name ?? '',
                'warehouse_id' => $validated['warehouse_id'],
                'return_date' => $validated['return_date'],
                'return_type' => $validated['return_type'] ?? 'quality',
                'status' => $status,
                'total_skus' => $totalSkus,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
            ]);

            foreach ($items as $item) {
                $item['return_id'] = $return->id;
                DB::table('sales_return_items')->insert(array_merge($item, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            return $this->created($return->fresh(['items.product']), $status === 'pending' ? '已提交审核' : '已保存草稿');
        });
    }

    public function update(Request $request, $id)
    {
        $return = SalesReturn::find($id);
        if (! $return) {
            return $this->notFound();
        }
        if ($return->status !== 'draft') {
            return $this->error('只有草稿状态可以编辑', 422);
        }

        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'return_date' => 'required|date',
            'return_type' => 'nullable|string|in:quality,cancel,damage,other',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.return_price' => 'required|numeric|min:0',
        ]);

        $returnedMap = $this->getReturnedQtyByOrder((int) $return->order_id);
        $orderItems = SalesOrderItem::where('sales_order_id', $return->order_id)->get()->keyBy('product_id');

        $totalQty = 0;
        $totalAmount = 0.0;
        $totalSkus = 0;
        $itemsData = [];

        foreach ($validated['items'] as $item) {
            $orderItem = $orderItems->get($item['product_id']);
            if (! $orderItem) {
                return $this->error("商品{$item['product_id']}不在原订单中", 422);
            }
            $returned = $returnedMap[$item['product_id']] ?? 0;
            $returnable = (int) $orderItem->quantity - $returned;
            if ($item['return_qty'] > $returnable) {
                return $this->error('退货数量超过可退数量', 422);
            }
            $amount = round($item['return_qty'] * $item['return_price'], 2);
            $totalQty += $item['return_qty'];
            $totalAmount += $amount;
            $totalSkus++;
            $itemsData[] = array_merge($item, [
                'return_amount' => $amount,
                'order_qty' => (int) $orderItem->quantity,
                'returned_qty' => $returned,
            ]);
        }

        return DB::transaction(function () use ($return, $validated, $itemsData, $totalQty, $totalAmount, $totalSkus) {
            $return->update([
                'warehouse_id' => $validated['warehouse_id'],
                'return_date' => $validated['return_date'],
                'return_type' => $validated['return_type'] ?? 'quality',
                'remark' => $validated['remark'] ?? null,
                'total_skus' => $totalSkus,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
            ]);

            DB::table('sales_return_items')->where('return_id', $return->id)->delete();
            foreach ($itemsData as $item) {
                DB::table('sales_return_items')->insert(array_merge($item, [
                    'return_id' => $return->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            return $this->success($return->fresh(['items.product']), '更新成功');
        });
    }

    public function submit($id)
    {
        $return = SalesReturn::find($id);
        if (! $return) {
            return $this->notFound();
        }
        if ($return->status !== 'draft') {
            return $this->error('只有草稿状态可以提交审核', 422);
        }
        $return->status = 'pending';
        $return->save();

        return $this->success($return, '已提交审核');
    }

    public function approve(Request $request, $id)
    {
        $return = SalesReturn::with(['items'])->find($id);
        if (! $return) {
            return $this->notFound();
        }
        if ($return->status === 'approved') {
            return $this->success($return, '已审核，无需重复操作');
        }
        if ($return->status !== 'pending') {
            return $this->error('只有待审核状态可以审核', 422);
        }

        $validated = $request->validate([
            'approval_comment' => 'nullable|string|max:500',
        ]);

        $adminId = auth('admin')?->id();
        $totalAmount = (float) $return->total_amount;

        return DB::transaction(function () use ($return, $validated, $adminId, $totalAmount) {
            // 1. 退货入库：调用 StockService::stockIn()
            foreach ($return->items as $item) {
                if ($item->return_qty > 0) {
                    $this->stockService->stockIn(
                        $item->product_id,
                        $return->warehouse_id,
                        $item->return_qty,
                        null,
                        $return->id,
                        'SalesReturn'
                    );
                }
            }

            // 2. 更新原订单 paid_amount（冲减已收）
            $order = SalesOrder::find($return->order_id);
            $refundAmount = 0;
            $receivableOffset = 0;

            if ($order) {
                $currentPaid = (float) $order->paid_amount;
                if ($totalAmount <= $currentPaid) {
                    // 退货金额 ≤ 已收 → 退差额给客户
                    $refundAmount = $totalAmount;
                    $order->paid_amount = round($currentPaid - $refundAmount, 2);
                } else {
                    // 退货金额 > 已收 → 退已收部分，余下冲减应收
                    $refundAmount = $currentPaid;
                    $receivableOffset = $totalAmount - $currentPaid;
                    $order->paid_amount = 0;
                }
                // 更新订单状态
                if ($order->paid_amount <= 0.01 && $order->status !== '已红冲') {
                    $order->payment_status = '未收款';
                }
                $order->save();
            }

            // 3. 更新客户 balance（退货增加欠款或减少预付）
            if ($return->customer_id) {
                // 退货 → 客户欠款增加（balance正向移动）
                DB::table('customers')
                    ->where('id', $return->customer_id)
                    ->increment('balance', $totalAmount);
            }

            // 4. 写现金流水（如有退款）
            if ($refundAmount > 0 && $return->customer_id) {
                $flowNo = 'CF'.date('YmdHis').strtoupper(Str::random(4));
                $customer = Customer::find($return->customer_id);
                DB::table('cash_flows')->insert([
                    'flow_no' => $flowNo,
                    'flow_type' => 'pay',
                    'customer_id' => $return->customer_id,
                    'related_id' => $return->id,
                    'related_type' => 'SalesReturn',
                    'flow_date' => $return->return_date?->toDateString() ?? now()->toDateString(),
                    'amount' => -$refundAmount,
                    'payment_method' => '退货退款',
                    'remark' => '销售退货退款：'.($customer?->name ?? '').' '.$return->return_no,
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 5. 更新退货单状态
            $return->status = 'approved';
            $return->approved_by = $adminId;
            $return->approved_at = now();
            $return->approval_comment = $validated['approval_comment'] ?? null;
            $return->refund_amount = round($refundAmount, 2);
            $return->receivable_offset = round($receivableOffset, 2);
            $return->save();

            return $this->success($return->fresh(['items.product']), '审核通过，已退货入库');
        });
    }

    public function reject(Request $request, $id)
    {
        $return = SalesReturn::find($id);
        if (! $return) {
            return $this->notFound();
        }
        if ($return->status !== 'pending') {
            return $this->error('只有待审核状态可以驳回', 422);
        }

        $validated = $request->validate([
            'approval_comment' => 'nullable|string|max:500',
        ]);

        $return->status = 'draft';
        $return->approval_comment = $validated['approval_comment'] ?? null;
        $return->save();

        return $this->success($return, '已驳回，可继续编辑');
    }

    public function cancel($id)
    {
        $return = SalesReturn::find($id);
        if (! $return) {
            return $this->notFound();
        }
        if (in_array($return->status, ['approved'], true)) {
            return $this->error('已审核的退货单不能取消', 422);
        }
        $return->status = 'cancelled';
        $return->save();

        return $this->success($return, '已取消');
    }

    public function destroy($id)
    {
        $return = SalesReturn::find($id);
        if (! $return) {
            return $this->notFound();
        }
        if ($return->status !== 'draft') {
            return $this->error('只有草稿状态可以删除', 422);
        }
        DB::table('sales_return_items')->where('return_id', $return->id)->delete();
        $return->delete();

        return $this->noContent();
    }

    public function export(Request $request)
    {
        $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $query = SalesReturn::with(['customer', 'warehouse']);
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('start_date')) {
            $query->where('return_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('return_date', '<=', $request->end_date);
        }
        $list = $query->orderByDesc('id')->get();

        $csv = "\u{FEFF}";
        $csv .= "销售退货单列表\n\n";
        $csv .= "退货单号,原订单号,客户,仓库,退货日期,商品种类,退货数量,退货金额,状态,制单人,审核人\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%d,%d,%s,%s,%s,%s\n",
                $r->return_no,
                $r->order_no ?? '',
                $r->customer_name ?? '',
                $r->warehouse?->name ?? '',
                $r->return_date?->format('Y-m-d') ?? '',
                $r->total_skus,
                $r->total_qty,
                $r->total_amount,
                $this->statusLabel($r->status),
                $r->creator?->real_name ?? '-',
                $r->approver?->real_name ?? '-'
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="sales_returns.csv"',
        ]);
    }

    /** 获取指定订单各商品已退数量 */
    private function getReturnedQtyByOrder(int $orderId): array
    {
        $returns = SalesReturn::where('order_id', $orderId)
            ->where('status', 'approved')
            ->with('items')
            ->get();

        $map = [];
        foreach ($returns as $return) {
            foreach ($return->items as $item) {
                $pid = $item->product_id;
                $map[$pid] = ($map[$pid] ?? 0) + $item->return_qty;
            }
        }

        return $map;
    }

    private function generateNo(): string
    {
        $date = date('Ymd');
        $prefix = 'TH'.$date;
        $last = SalesReturn::where('return_no', 'like', $prefix.'%')
            ->orderByDesc('return_no')
            ->value('return_no');

        if ($last) {
            $seq = intval(substr($last, -6)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => '草稿',
            'pending' => '待审核',
            'approved' => '已审核',
            'cancelled' => '已取消',
            default => $status,
        };
    }
}
