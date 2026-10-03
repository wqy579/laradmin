<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Services\ReceiveService;

class ReceiveController extends Controller
{
    use ResponseTrait;

    protected ReceiveService $service;

    public function __construct(ReceiveService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['customer_id', 'status', 'start_date', 'end_date']);

        return $this->paginated($this->service->list($filters, (int) $request->input('page', 1), (int) $request->input('page_size', 20)));
    }

    /** 客户未收款订单明细 */
    public function unpaidOrders(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
        ]);

        $orders = SalesOrder::where('customer_id', $request->customer_id)
            ->whereNotIn('status', ['cancelled', '已红冲'])
            ->whereRaw('(total_amount - paid_amount) > 0.01')
            ->select('id', 'order_no', 'order_date', 'total_amount', 'paid_amount', 'status')
            ->orderByDesc('order_date')
            ->get()
            ->map(function ($o) {
                $total = (float) $o->total_amount;
                $paid = (float) $o->paid_amount;
                $o->order_date = $o->order_date?->format('Y-m-d');
                $o->total_amount = round($total, 2);
                $o->paid_amount = round($paid, 2);
                $o->unpaid_amount = round($total - $paid, 2);

                return $o;
            });

        return $this->success(['list' => $orders, 'total' => $orders->count()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receive_type' => 'nullable|integer|in:1,2',
            'customer_id' => 'nullable|exists:customers,id',
            'sales_order_id' => 'nullable|exists:sales_orders,id',
            'sales_order_ids' => 'nullable|array',
            'sales_order_ids.*' => 'exists:sales_orders,id',
            'sales_order_items' => 'nullable|array',
            'sales_order_items.*.order_id' => 'required_with:sales_order_items|exists:sales_orders,id',
            'sales_order_items.*.pay_amount' => 'required_with:sales_order_items|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'receive_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'handler_id' => 'nullable|exists:employees,id',
            'remark' => 'nullable|string',
            'discount' => 'nullable|numeric|min:0',
        ]);

        $adminId = auth('admin')?->id();

        return DB::transaction(function () use ($validated, $adminId) {
            // 预取订单信息（在事务内，避免后续 N+1）
            $orderMap = [];
            $items = $validated['sales_order_items'] ?? [];
            if (! empty($items)) {
                $orderIdsForMap = collect($items)->pluck('order_id')->unique()->toArray();
                foreach (SalesOrder::whereIn('id', $orderIdsForMap)->get() as $o) {
                    $orderMap[$o->id] = $o;
                }
            }

            // 构建核销明细（含收款前已收金额快照）
            $salesOrderItems = [];
            $orderIds = [];
            $itemMap = [];
            foreach ($items as $item) {
                $oid = (int) $item['order_id'];
                $order = $orderMap[$oid] ?? null;
                $orderIds[] = $oid;
                $itemMap[$oid] = (float) ($item['pay_amount'] ?? 0);
                $salesOrderItems[] = [
                    'order_id' => $oid,
                    'pay_amount' => (float) ($item['pay_amount'] ?? 0),
                    'paid_before' => $order ? round((float) $order->paid_amount, 2) : 0,
                ];
            }

            $receive = $this->service->create(array_merge($validated, [
                'sales_order_items' => $salesOrderItems,
                'sales_order_id' => count($orderIds) === 1 ? $orderIds[0] : null,
            ]));
            $receiveAmount = (float) $validated['amount'];
            $discount = (float) ($validated['discount'] ?? 0);
            $paymentMethod = $validated['payment_method'] ?? '现金';

            // 核销订单应收
            if (! empty($orderIds)) {
                $totalReceivable = 0;
                $ordersToAllocate = [];
                foreach ($orderIds as $oid) {
                    $order = $orderMap[$oid] ?? null;
                    if (! $order) {
                        continue;
                    }
                    $receivable = (float) $order->total_amount - (float) $order->paid_amount;
                    if ($receivable > 0.01) {
                        $ordersToAllocate[] = ['order' => $order, 'receivable' => $receivable, 'order_id' => $oid];
                        $totalReceivable += $receivable;
                    }
                }

                if (! empty($ordersToAllocate)) {
                    // 有明确 per-order 金额则直接使用，否则按比例分配
                    if (! empty($itemMap)) {
                        $remainingAmount = $receiveAmount;
                        foreach ($ordersToAllocate as $index => $alloc) {
                            $order = $alloc['order'];
                            $oid = $alloc['order_id'];
                            $payAmount = $itemMap[$oid] ?? 0;
                            // 最后一笔用剩余，避免精度丢失
                            if ($index === count($ordersToAllocate) - 1) {
                                $payAmount = round($remainingAmount, 2);
                            } else {
                                $payAmount = round($payAmount, 2);
                                $remainingAmount = round($remainingAmount - $payAmount, 2);
                            }
                            $payAmount = max(0, $payAmount);
                            $order->paid_amount = round((float) $order->paid_amount + $payAmount, 2);
                            $this->updatePaymentStatus($order);
                            $order->save();
                        }
                    } else {
                        // 按比例分配
                        $remainingAmount = $receiveAmount + $discount;
                        foreach ($ordersToAllocate as $index => $alloc) {
                            $order = $alloc['order'];
                            $receivable = $alloc['receivable'];
                            if ($index === count($ordersToAllocate) - 1) {
                                $allocateAmount = $remainingAmount;
                            } else {
                                $allocateAmount = $totalReceivable > 0
                                    ? ($receivable / $totalReceivable) * $receiveAmount
                                    : 0;
                                $remainingAmount -= $allocateAmount;
                            }
                            $order->paid_amount = round((float) $order->paid_amount + $allocateAmount, 2);
                            $this->updatePaymentStatus($order);
                            $order->save();
                        }
                    }

                    // 更新应收款记录状态
                    foreach ($orderIds as $oid) {
                        $order = $orderMap[$oid] ?? null;
                        if ($order) {
                            DB::table('receives')
                                ->where('sales_order_id', $oid)
                                ->where('status', 0)
                                ->update([
                                    'status' => 1,
                                    'amount' => max(0, round((float) $order->total_amount - (float) $order->paid_amount, 2)),
                                    'payment_method' => $paymentMethod,
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }
            }

            // 保存核销明细到receive_items表
            if (! empty($salesOrderItems)) {
                $itemsToInsert = [];
                foreach ($salesOrderItems as $item) {
                    $order = $orderMap[(int) $item['order_id']] ?? null;
                    $itemsToInsert[] = [
                        'receive_id' => $receive->id,
                        'sales_order_id' => $item['order_id'],
                        'order_no' => $order ? $order->order_no : null,
                        'pay_amount' => $item['pay_amount'],
                        'paid_before' => $item['paid_before'],
                        'receivable_before' => $order ? round((float) $order->total_amount - (float) $item['paid_before'], 2) : 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('receive_items')->insert($itemsToInsert);
            }

            // 更新客户应收余额（减少欠款）
            if (! empty($validated['customer_id'])) {
                DB::table('customers')
                    ->where('id', $validated['customer_id'])
                    ->decrement('balance', $receiveAmount);
            }

            // 写经营历程
            if (! empty($validated['customer_id'])) {
                $flowNo = 'CF'.date('YmdHis').strtoupper(Str::random(4));
                $customer = Customer::find($validated['customer_id']);
                $customerName = $customer ? $customer->name : '';
                $orderSummary = '';
                if (! empty($items)) {
                    $orderNos = [];
                    foreach ($items as $it) {
                        $o = $orderMap[(int) $it['order_id']] ?? null;
                        $orderNos[] = $o ? $o->order_no : (string) $it['order_id'];
                    }
                    $orderSummary = '[订单:'.implode(',', $orderNos).']';
                }
                DB::table('cash_flows')->insert([
                    'flow_no' => $flowNo,
                    'flow_type' => 'receive',
                    'customer_id' => $validated['customer_id'],
                    'related_id' => $receive->id,
                    'related_type' => 'Receive',
                    'flow_date' => $validated['receive_date'] ?? now()->toDateString(),
                    'amount' => $receiveAmount,
                    'payment_method' => $paymentMethod,
                    'remark' => '收款：'.($customerName ? $customerName.' - ' : '').($validated['remark'] ?? '').' '.$orderSummary,
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $this->created($receive);
        });
    }

    /** 更新订单付款状态 */
    private function updatePaymentStatus(SalesOrder $order): void
    {
        $total = (float) $order->total_amount;
        $paid = (float) $order->paid_amount;
        if ($paid >= $total - 0.01) {
            $order->payment_status = '已收款';
        } elseif ($paid > 0.01) {
            $order->payment_status = '部分收款';
        } else {
            $order->payment_status = '未收款';
        }
        // 仅当订单尚未红冲时变更主状态
        if ($order->status !== '已红冲') {
            if ($order->payment_status === '已收款' && $order->status !== '已收款') {
                $order->status = '已收款';
            }
        }
    }

    public function show($id)
    {
        $receive = $this->service->find($id);
        if (! $receive) {
            return $this->notFound();
        }

        // 附核销明细（来自 sales_order_items 和 cash_flows）
        $flowDetail = DB::table('cash_flows')
            ->where('related_id', $id)
            ->where('related_type', 'Receive')
            ->select('flow_no', 'amount', 'payment_method', 'flow_date', 'remark', 'created_by', 'created_at')
            ->first();

        // 格式化核销明细，附加订单号（paid_before 已在 store 时快照保存）
        $orderItems = $receive->sales_order_items ?? [];
        if (! empty($orderItems)) {
            $orderIds = array_column($orderItems, 'order_id');
            $orderList = SalesOrder::whereIn('id', $orderIds)
                ->select('id', 'order_no', 'total_amount')
                ->get()
                ->keyBy('id');
            foreach ($orderItems as &$item) {
                $order = $orderList->get($item['order_id']);
                $item['order_no'] = $order ? $order->order_no : '';
                $item['total_amount'] = $order ? (float) $order->total_amount : 0;
                // paid_before 是 store 时保存的快照，直接用；避免用当前 paid_amount 再算
                if (! isset($item['paid_before']) && $order) {
                    $item['paid_before'] = round((float) $order->paid_amount - (float) $item['pay_amount'], 2);
                }
            }
        }

        $data = $receive->toArray();
        $data['cash_flow'] = $flowDetail;
        $data['order_items'] = $orderItems;

        return $this->success($data);
    }

    public function update(Request $request, $id)
    {
        $receive = $this->service->find($id);
        if (! $receive) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'receive_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'remark' => 'nullable|string',
        ]);

        $receive = $this->service->update($receive, $validated);

        return $this->success($receive);
    }

    public function approve($id)
    {
        $receive = $this->service->find($id);
        if (! $receive) {
            return $this->notFound();
        }

        $receive = $this->service->approve($receive);

        return $this->success($receive);
    }

    /** 红冲收款单 */
    public function redFlush(Request $request, $id)
    {
        $receive = $this->service->find($id);
        if (! $receive) {
            return $this->notFound();
        }
        if ($receive->status !== 1) {
            return $this->error('只有已审核的收款单才能红冲', 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $adminId = auth('admin')?->id();
        $reason = $validated['reason'] ?? '手动红冲';

        return DB::transaction(function () use ($receive, $adminId, $reason) {
            // 1. 从 sales_order_items 快照恢复各订单的 paid_amount
            $orderItems = $receive->sales_order_items ?? [];
            if (! empty($orderItems)) {
                $orderIds = array_column($orderItems, 'order_id');
                $orderList = SalesOrder::whereIn('id', $orderIds)
                    ->get()
                    ->keyBy('id');
                foreach ($orderItems as $item) {
                    $oid = (int) $item['order_id'];
                    $order = $orderList->get($oid);
                    if ($order) {
                        // paid_before 是收款前的快照，直接恢复
                        $order->paid_amount = round($item['paid_before'] ?? 0, 2);
                        $this->updatePaymentStatus($order);
                        $order->save();
                    }
                }
            } elseif ($receive->sales_order_id) {
                // 兼容旧数据：没有 sales_order_items 时回退到单订单处理
                $order = SalesOrder::find($receive->sales_order_id);
                if ($order) {
                    $order->paid_amount = round(max(0, (float) $order->paid_amount - (float) $receive->amount), 2);
                    $this->updatePaymentStatus($order);
                    $order->save();
                }
            }

            // 2. 反向写 cash_flow 冲销记录
            $flowRecords = DB::table('cash_flows')
                ->where('related_id', $receive->id)
                ->where('related_type', 'Receive')
                ->get();

            foreach ($flowRecords as $flow) {
                DB::table('cash_flows')->insert([
                    'flow_no' => 'CF'.date('YmdHis').strtoupper(Str::random(4)),
                    'flow_type' => 'receive',
                    'customer_id' => $receive->customer_id,
                    'related_id' => $receive->id,
                    'related_type' => 'Receive',
                    'flow_date' => now()->toDateString(),
                    'amount' => -(float) $flow->amount,
                    'payment_method' => $flow->payment_method,
                    'remark' => '红冲：'.$reason.' 原单'.$receive->receive_no,
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 3. 标记收款单和关联应收款记录为已红冲
            $receive->status = 2;
            $receive->remark = '红冲：'.$reason;
            $receive->save();

            DB::table('receives')
                ->where('id', $receive->id)
                ->update([
                    'status' => 2,
                    'amount' => 0,
                    'remark' => '收款单红冲：'.$reason,
                    'updated_at' => now(),
                ]);

            return $this->success($receive, '红冲成功');
        });
    }

    public function destroy($id)
    {
        $receive = $this->service->find($id);
        if (! $receive) {
            return $this->notFound();
        }

        $this->service->destroy($receive);

        return $this->noContent();
    }

    public function statistics(Request $request)
    {
        $filters = $request->only(['customer_id', 'start_date', 'end_date']);
        $stats = $this->service->statistics($filters);

        return $this->success($stats);
    }

    /** 应收账款：按客户汇总订单总额 - 已收款 */
    public function receivable(Request $request)
    {
        $query = DB::table('customers as c')
            ->leftJoin('sales_orders as so', function ($j) {
                $j->on('c.id', '=', 'so.customer_id')
                    ->whereNotIn('so.status', ['cancelled', '已红冲']);
            })
            ->leftJoin('receives as r', function ($j) {
                $j->on('c.id', '=', 'r.customer_id')
                    ->where('r.status', 1);
            })
            ->select(
                'c.id as customer_id',
                'c.name as customer_name',
                DB::raw('COALESCE(SUM(DISTINCT so.total_amount), 0) as order_total'),
                DB::raw('COALESCE(SUM(r.amount), 0) as received_total')
            )
            ->where('c.is_active', 1)
            ->groupBy('c.id', 'c.name');

        if ($request->filled('customer_id')) {
            $query->where('c.id', $request->customer_id);
        }

        $list = $query
            ->havingRaw('order_total > 0 OR received_total > 0')
            ->orderByDesc('order_total')
            ->get()
            ->map(function ($r) {
                $r->receivable = round($r->order_total - $r->received_total, 2);

                return $r;
            });

        return $this->success(['list' => $list, 'total' => $list->count()]);
    }

    /** 往来对账：返回所有客户的应收/已收/待收余额 */
    public function statement(Request $request)
    {
        return $this->receivable($request);
    }
}
