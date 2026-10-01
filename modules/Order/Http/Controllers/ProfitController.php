<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfitController extends Controller
{
    use ResponseTrait;

    /** 月度利润：收入(收款) - 支出(付款+费用)，按类别分组 */
    public function index(Request $request)
    {
        $month = $request->input('month');
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        if ($month === 'last') {
            $start = now()->subMonth()->startOfMonth();
            $end = now()->subMonth()->endOfMonth();
        } elseif ($month === 'quarter') {
            $start = now()->startOfQuarter();
            $end = now()->endOfQuarter();
        }

        $receiveTotal = (float) DB::table('receives')->where('status', 1)->whereBetween('receive_date', [$start, $end])->sum('amount');
        $payTotal = (float) DB::table('pays')->where('status', 1)->whereBetween('pay_date', [$start, $end])->sum('amount');
        $expenseTotal = (float) DB::table('expenses')->where('status', 1)->whereBetween('expense_date', [$start, $end])->sum('amount');

        $list = [
            ['category' => '收款收入', 'amount' => $receiveTotal, 'count' => DB::table('receives')->where('status', 1)->whereBetween('receive_date', [$start, $end])->count()],
            ['category' => '付款支出', 'amount' => $payTotal, 'count' => DB::table('pays')->where('status', 1)->whereBetween('pay_date', [$start, $end])->count()],
            ['category' => '费用支出', 'amount' => $expenseTotal, 'count' => DB::table('expenses')->where('status', 1)->whereBetween('expense_date', [$start, $end])->count()],
        ];

        $stats = [
            'total_income' => $receiveTotal,
            'total_expense' => $payTotal + $expenseTotal,
        ];

        return $this->success(['list' => $list, 'stats' => $stats]);
    }
}
