<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashFlowController extends Controller
{
    use ResponseTrait;

    /** 现金流水：收款(正) + 付款(负) + 费用(负) */
    public function index(Request $request)
    {
        $quickDate = $request->input('quick_date');
        $dateFn = function ($q) use ($quickDate) {
            if ($quickDate === 'today') {
                $q->whereDate('date', now()->toDateString());
            } elseif ($quickDate === 'week') {
                $q->whereDate('date', '>=', now()->subDays(6)->toDateString());
            } elseif ($quickDate === 'month') {
                $q->whereMonth('date', now()->month)->whereYear('date', now()->year);
            }
        };

        // 收款（全部状态，草稿也计入经营历程）
        $receives = DB::table('receives')->select('receive_date as date', DB::raw("'收款' as type"), 'remark as description', 'amount');
        $dateFn($receives);

        // 付款（负，全部状态）
        $pays = DB::table('pays')->select('pay_date as date', DB::raw("'付款' as type"), 'remark as description', DB::raw('-amount as amount'));
        $dateFn($pays);

        // 费用（负，全部状态）
        $expenses = DB::table('expenses')->select('expense_date as date', DB::raw("'费用' as type"), 'remark as description', DB::raw('-amount as amount'));
        $dateFn($expenses);

        $list = $receives->unionAll($pays)->unionAll($expenses)->orderByDesc('date')->get();

        return $this->success(['list' => $list, 'total' => $list->count()]);
    }
}
