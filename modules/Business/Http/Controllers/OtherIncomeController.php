<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Order\Models\Receive;

class OtherIncomeController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        // 其它收入单：其他收款（receive_type = 2）
        $query = Receive::with('customer', 'handler')->where('receive_type', 2);

        if (isset($request->status)) {
            $query->where('status', $request->status);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('start_date')) {
            $query->where('receive_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('receive_date', '<=', $request->end_date);
        }
        if ($request->filled('keyword')) {
            $query->where('receive_no', 'like', '%'.$request->keyword.'%');
        }

        $list = $query->orderByDesc('id')
            ->paginate((int) $request->input('page_size', 20), ['*'], 'page', (int) $request->input('page', 1));

        return $this->paginated($list);
    }

    public function statistics(Request $request)
    {
        $query = Receive::where('status', 1)->where('receive_type', 2);
        if ($request->filled('start_date')) {
            $query->where('receive_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('receive_date', '<=', $request->end_date);
        }

        return $this->success([
            'total_amount' => round((float) $query->sum('amount'), 2),
            'count' => $query->count(),
        ]);
    }
}