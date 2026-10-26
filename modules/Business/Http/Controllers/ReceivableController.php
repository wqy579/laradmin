<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceivableController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = DB::table('customers as c')
            ->leftJoin('sales_orders as so', function ($j) {
                $j->on('c.id', '=', 'so.customer_id')
                    ->whereNotIn('so.status', ['cancelled', '已红冲']);
            })
            ->select(
                'c.id as customer_id',
                'c.name as customer_name',
                DB::raw('COALESCE(SUM(so.total_amount), 0) as order_total'),
                DB::raw('COALESCE(SUM(so.paid_amount), 0) as received_total')
            )
            ->where('c.is_active', 1)
            ->groupBy('c.id', 'c.name');

        if ($request->filled('customer_id')) {
            $query->where('c.id', $request->customer_id);
        }
        if ($request->filled('keyword')) {
            $query->where('c.name', 'like', '%'.$request->keyword.'%');
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
}
