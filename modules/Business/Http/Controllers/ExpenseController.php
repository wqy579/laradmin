<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Business\Services\ExpenseService;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;

class ExpenseController extends Controller
{
    use ResponseTrait;

    protected ExpenseService $service;

    public function __construct(ExpenseService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['expense_type', 'status', 'start_date', 'end_date']);
        return $this->paginated($this->service->list($filters, (int) $request->input('page', 1), (int) $request->input('page_size', 20)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'expense_type' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'nullable|date',
            'handler_id' => 'nullable|exists:employees,id',
            'department_id' => 'nullable|exists:auth_department,id',
            'remark' => 'nullable|string',
        ]);

        $expense = $this->service->create($validated);
        return $this->created($expense);
    }

    public function show($id)
    {
        $expense = $this->service->find($id);
        if (!$expense) {
            return $this->notFound();
        }
        return $this->success($expense);
    }

    public function update(Request $request, $id)
    {
        $expense = $this->service->find($id);
        if (!$expense) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'expense_type' => 'nullable|string|max:50',
            'amount' => 'nullable|numeric|min:0.01',
            'expense_date' => 'nullable|date',
            'remark' => 'nullable|string',
        ]);

        $expense = $this->service->update($expense, $validated);
        return $this->success($expense);
    }

    public function approve($id)
    {
        $expense = $this->service->find($id);
        if (!$expense) {
            return $this->notFound();
        }

        $expense = $this->service->approve($expense);
        return $this->success($expense);
    }

    public function destroy($id)
    {
        $expense = $this->service->find($id);
        if (!$expense) {
            return $this->notFound();
        }

        $this->service->destroy($expense);
        return $this->noContent();
    }

    public function statistics(Request $request)
    {
        $filters = $request->only(['expense_type', 'start_date', 'end_date']);
        $stats = $this->service->statistics($filters);
        return $this->success($stats);
    }
}
