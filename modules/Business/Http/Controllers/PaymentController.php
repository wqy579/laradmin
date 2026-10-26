<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Order\Models\Pay;

class PaymentController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = Pay::with('supplier', 'handler');

        if ($request->filled('pay_type')) {
            $query->where('pay_type', $request->pay_type);
        }
        if (isset($request->status)) {
            $query->where('status', $request->status);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('start_date')) {
            $query->where('pay_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('pay_date', '<=', $request->end_date);
        }
        if ($request->filled('keyword')) {
            $query->where('pay_no', 'like', '%'.$request->keyword.'%');
        }

        $list = $query->orderByDesc('id')
            ->paginate((int) $request->input('page_size', 20), ['*'], 'page', (int) $request->input('page', 1));

        return $this->paginated($list);
    }

    public function statistics(Request $request)
    {
        $query = Pay::where('status', 1);
        if ($request->filled('start_date')) {
            $query->where('pay_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('pay_date', '<=', $request->end_date);
        }

        return $this->success([
            'total_amount' => round((float) $query->sum('amount'), 2),
            'count' => $query->count(),
        ]);
    }
}
