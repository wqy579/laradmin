<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayableController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = DB::table('suppliers as s')
            ->leftJoin('pays as p', function ($j) {
                $j->on('s.id', '=', 'p.supplier_id')->where('p.status', 1);
            })
            ->select(
                's.id as supplier_id',
                's.name as supplier_name',
                DB::raw('COALESCE(SUM(p.amount), 0) as paid_total')
            )
            ->where('s.is_active', 1)
            ->groupBy('s.id', 's.name');

        if ($request->filled('supplier_id')) {
            $query->where('s.id', $request->supplier_id);
        }
        if ($request->filled('keyword')) {
            $query->where('s.name', 'like', '%'.$request->keyword.'%');
        }

        $list = $query->havingRaw('paid_total > 0')->orderByDesc('paid_total')->get();

        return $this->success(['list' => $list, 'total' => $list->count()]);
    }
}
