<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Business\Services\ExpenseService;

/**
 * B9 一般费用单：完整 CRUD + 审核，数据源 expenses 表（沿用 ExpenseService）。
 *
 * 与 business/expense（ExpenseController）同表同服务：一般费用单页是其面向
 * 财务域的入口，字段口径必须一致，故 CRUD 全部走 ExpenseService，不再各自查表。
 * 方法形状对齐 FinanceFundOrderController（inline validation + try/catch 422）。
 */
class GeneralExpenseController extends Controller
{
    use ResponseTrait;

    public function __construct(private ExpenseService $service) {}

    public function index(Request $request)
    {
        $filters = array_filter([
            'expense_type' => $request->input('expense_type'),
            'status' => $request->input('status'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
        ], fn ($v) => $v !== null && $v !== '');

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));

        return $this->paginated($this->service->list($filters, $page, $pageSize));
    }

    public function show($id)
    {
        $expense = $this->service->find((int) $id);
        if (! $expense) {
            return $this->notFound();
        }

        return $this->success($expense);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'expense_type' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_date' => 'nullable|date',
            'handler_id' => 'nullable|integer|min:1',
            'department_id' => 'nullable|integer|min:1',
            'remark' => 'nullable|string',
        ]);

        $expense = $this->service->create($validated);

        return $this->created($expense->load(['handler', 'department']));
    }

    public function update(Request $request, $id)
    {
        $expense = $this->service->find((int) $id);
        if (! $expense) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'expense_type' => 'nullable|string|max:50',
            'amount' => 'nullable|numeric|min:0.01|max:99999999.99',
            'expense_date' => 'nullable|date',
            'handler_id' => 'nullable|integer|min:1',
            'department_id' => 'nullable|integer|min:1',
            'remark' => 'nullable|string',
        ]);

        try {
            return $this->success($this->service->update($expense, $validated)->load(['handler', 'department']));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function approve($id)
    {
        $expense = $this->service->find((int) $id);
        if (! $expense) {
            return $this->notFound();
        }

        try {
            return $this->success($this->service->approve($expense));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy($id)
    {
        $expense = $this->service->find((int) $id);
        if (! $expense) {
            return $this->notFound();
        }

        try {
            $this->service->destroy($expense);

            return $this->noContent();
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function statistics(Request $request)
    {
        $filters = array_filter([
            'expense_type' => $request->input('expense_type'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->success($this->service->statistics($filters));
    }
}
