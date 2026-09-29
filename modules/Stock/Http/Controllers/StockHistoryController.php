<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('stocks_history as h')
            ->leftJoin('products as p', 'h.product_id', '=', 'p.id')
            ->leftJoin('warehouses as w', 'h.warehouse_id', '=', 'w.id')
            ->select(
                'h.id', 'h.product_id', 'p.name as product_name', 'p.spec as product_spec',
                'h.warehouse_id', 'w.name as warehouse_name',
                'h.change_type', 'h.change_qty', 'h.before_qty', 'h.after_qty',
                'h.related_id', 'h.related_type', 'h.remark', 'h.created_at'
            );

        if ($request->filled('product_id')) {
            $query->where('h.product_id', $request->product_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('h.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('change_type')) {
            $query->where('h.change_type', $request->change_type);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('h.created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('h.created_at', '<=', $request->end_date);
        }
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('p.name', 'like', '%'.$request->keyword.'%')
                    ->orWhere('p.code', 'like', '%'.$request->keyword.'%');
            });
        }

        $list = $query->orderByDesc('h.id')->paginate($request->integer('per_page', 20));

        return response()->json(['code' => 200, 'message' => 'success', 'data' => $list]);
    }
}
