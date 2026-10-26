<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Business\Models\Expense;

class GeneralExpenseController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = Expense::with(['handler', 'department']);

        if ($request->filled('expense_type')) {
            $query->where('expense_type', $request->expense_type);
        }
        if (isset($request->status)) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->where('expense_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('expense_date', '<=', $request->end_date);
        }
        if ($request->filled('keyword')) {
            $query->where('expense_no', 'like', '%'.$request->keyword.'%');
        }

        $list = $query->orderByDesc('id')
            ->paginate((int) $request->input('page_size', 20), ['*'], 'page', (int) $request->input('page', 1));

        return $this->paginated($list);
    }

    public function statistics(Request $request)
    {
        $query = Expense::where('status', 1);
        if ($request->filled('start_date')) {
            $query->where('expense_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('expense_date', '<=', $request->end_date);
        }

        return $this->success([
            'total_amount' => round((float) $query->sum('amount'), 2),
            'count' => $query->count(),
        ]);
    }
}