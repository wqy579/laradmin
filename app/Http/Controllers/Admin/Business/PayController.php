<?php

namespace App\Http\Controllers\Admin\Business;

use App\Http\Controllers\Controller;
use App\Services\Business\PayService;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;

class PayController extends Controller
{
    use ResponseTrait;

    protected PayService $service;

    public function __construct(PayService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['supplier_id', 'status', 'start_date', 'end_date']);
        return $this->paginated($this->service->list($filters, (int) $request->input('page', 1), (int) $request->input('page_size', 20)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'pay_type' => 'nullable|integer|in:1,2',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'amount' => 'required|numeric|min:0.01',
            'pay_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'handler_id' => 'nullable|exists:employees,id',
            'remark' => 'nullable|string',
        ]);

        $pay = $this->service->create($validated);
        return $this->created($pay);
    }

    public function show($id)
    {
        $pay = $this->service->find($id);
        if (!$pay) {
            return $this->notFound();
        }
        return $this->success($pay);
    }

    public function update(Request $request, $id)
    {
        $pay = $this->service->find($id);
        if (!$pay) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'pay_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'remark' => 'nullable|string',
        ]);

        $pay = $this->service->update($pay, $validated);
        return $this->success($pay);
    }

    public function approve($id)
    {
        $pay = $this->service->find($id);
        if (!$pay) {
            return $this->notFound();
        }

        $pay = $this->service->approve($pay);
        return $this->success($pay);
    }

    public function destroy($id)
    {
        $pay = $this->service->find($id);
        if (!$pay) {
            return $this->notFound();
        }

        $this->service->destroy($pay);
        return $this->noContent();
    }

    public function statistics(Request $request)
    {
        $filters = $request->only(['supplier_id', 'start_date', 'end_date']);
        $stats = $this->service->statistics($filters);
        return $this->success($stats);
    }
}
