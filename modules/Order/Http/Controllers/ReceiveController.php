<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receive_type' => 'nullable|integer|in:1,2',
            'customer_id' => 'nullable|exists:customers,id',
            'sales_order_id' => 'nullable|exists:sales_orders,id',
            'sales_order_ids' => 'nullable|array',
            'sales_order_ids.*' => 'exists:sales_orders,id',
            'amount' => 'required|numeric|min:0',
            'receive_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'handler_id' => 'nullable|exists:employees,id',
            'remark' => 'nullable|string',
            'discount' => 'nullable|numeric|min:0',
        ]);

        // 事务内：创建收款单 + 核销订单 + 写现金流水 + 更新应收款状态
        return DB::transaction(function () use ($validated) {
            $receive = $this->service->create($validated);
            $receiveAmount = (float) $validated['amount'];
            $discount = (float) ($validated['discount'] ?? 0);

            // 1. 核销勾选的订单应收：按比例分配收款金额
            $orderIds = $validated['sales_order_ids'] ?? ($validated['sales_order_id'] ? [$validated['sales_order_id']] : []);
            if ($orderIds) {
                // 计算总待收金额，用于按比例分配
                $totalReceivable = 0;
                $ordersToAllocate = [];
                foreach ($orderIds as $oid) {
                    $order = SalesOrder::find($oid);
                    if ($order) {
                        $receivable = (float) $order->total_amount - (float) $order->paid_amount;
                        if ($receivable > 0) {
                            $ordersToAllocate[] = ['order' => $order, 'receivable' => $receivable];
                            $totalReceivable += $receivable;
                        }
                    }
                }

                // 按比例分配收款金额
                $remainingAmount = $receiveAmount + $discount;
                foreach ($ordersToAllocate as $index => $item) {
                    $order = $item['order'];
                    $receivable = $item['receivable'];
                    // 比例分配（最后一笔用剩余金额避免精度问题）
                    if ($index === count($ordersToAllocate) - 1) {
                        $allocateAmount = $remainingAmount;
                    } else {
                        $allocateAmount = $totalReceivable > 0 ? ($receivable / $totalReceivable) * $receiveAmount : 0;
                        $remainingAmount -= $allocateAmount;
                    }

                    $order->paid_amount = (float) $order->paid_amount + $allocateAmount;
                    // 已收 >= 应收 → 标记已收款
                    if ($order->paid_amount >= $order->total_amount - 0.01) {
                        $order->status = '已收款';
                        $order->payment_status = '已收款';
                    } elseif ($order->paid_amount > 0) {
                        $order->payment_status = '部分收款';
                    }
                    $order->save();
                }

                // 2. 更新应收款记录（receives 表中自动生成的待确认记录）
                foreach ($orderIds as $oid) {
                    $order = SalesOrder::find($oid);
                    if ($order) {
                        DB::table('receives')
                            ->where('sales_order_id', $oid)
                            ->where('status', 0)
                            ->update([
                                'status' => 1,
                                'amount' => (float) $order->total_amount - (float) $order->paid_amount,
                                'payment_method' => $validated['payment_method'] ?? '现金',
                                'updated_at' => now(),
                            ]);
                    }
                }
            }

            // 3. 写经营历程（cash_flows 表，不再复用 stocks_history）
            if ($validated['customer_id']) {
                $flowNo = 'CF'.date('YmdHis').strtoupper(Str::random(4));
                DB::table('cash_flows')->insert([
                    'flow_no' => $flowNo,
                    'flow_type' => 'receive',
                    'customer_id' => $validated['customer_id'],
                    'related_id' => $receive->id ?? 0,
                    'related_type' => 'Receive',
                    'flow_date' => $validated['receive_date'] ?? now()->toDateString(),
                    'amount' => $receiveAmount,
                    'payment_method' => $validated['payment_method'] ?? '现金',
                    'remark' => '收款：'.($validated['remark'] ?? '').($orderIds ? ' [订单:'.implode(',', $orderIds).']' : ''),
                    'created_by' => auth('admin')?->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $this->created($receive);
        });
    }

    public function show($id)
    {
        $receive = $this->service->find($id);
        if (! $receive) {
            return $this->notFound();
        }

        return $this->success($receive);
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
        // 每个客户的订单总额（排除已取消）vs 已审核收款
        $query = \DB::table('customers as c')
            ->leftJoin('sales_orders as so', function ($j) {
                $j->on('c.id', '=', 'so.customer_id')->whereNotIn('so.status', ['cancelled']);
            })
            ->leftJoin('receives as r', function ($j) {
                $j->on('c.id', '=', 'r.customer_id')->where('r.status', 1);
            })
            ->select(
                'c.id as customer_id', 'c.name as customer_name',
                \DB::raw('COALESCE(SUM(DISTINCT so.total_amount), 0) as order_total'),
                \DB::raw('COALESCE(SUM(r.amount), 0) as received_total')
            )
            ->where('c.is_active', 1)
            ->groupBy('c.id', 'c.name');

        if ($request->filled('customer_id')) {
            $query->where('c.id', $request->customer_id);
        }
        $list = $query->havingRaw('order_total > 0 OR received_total > 0')->orderByDesc('order_total')->get()
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
