<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
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
            'amount' => 'required|numeric|min:0.01',
            'receive_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'handler_id' => 'nullable|exists:employees,id',
            'remark' => 'nullable|string',
        ]);

        $receive = $this->service->create($validated);

        return $this->created($receive);
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
}
