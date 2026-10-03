<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfitController extends Controller
{
    use ResponseTrait;

    /** 月度利润：收入(收款) - 支出(付款+费用)，按类别分组 + 资产负债表 */
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

        // 资产负债表：从库存 + 应收 + 应付 + 现金实时计算
        $stockValue = (float) DB::table('stocks')
            ->join('products', 'stocks.product_id', '=', 'products.id')
            ->where('products.is_active', 1)
            ->sum(DB::raw('stocks.quantity * COALESCE(products.cost_price, 0)'));

        // 应收账款 = 已发货未收款订单总额（status=配送中或待收款，且 paid_amount < total_amount）
        $receivableTotal = (float) DB::table('sales_orders')
            ->whereIn('status', ['配送中', '待收款'])
            ->whereRaw('paid_amount < total_amount')
            ->sum(DB::raw('total_amount - paid_amount'));

        // 应付账款 = 未完成付款的采购单总额（简化：所有pays未完成的）
        $payableTotal = (float) DB::table('pays')
            ->where('status', 0)
            ->sum('amount');

        // 现金 = 历史所有收款 - 历史所有付款 - 历史所有费用
        $allReceive = (float) DB::table('receives')->where('status', 1)->sum('amount');
        $allPay = (float) DB::table('pays')->where('status', 1)->sum('amount');
        $allExpense = (float) DB::table('expenses')->where('status', 1)->sum('amount');
        $cash = $allReceive - $allPay - $allExpense;

        $assets = [
            ['name' => '现金', 'amount' => $cash],
            ['name' => '库存商品', 'amount' => $stockValue],
            ['name' => '应收账款', 'amount' => $receivableTotal],
            ['name' => '资产总计', 'amount' => $cash + $stockValue + $receivableTotal],
        ];

        $equityAndLiabilities = [
            ['name' => '应付账款', 'amount' => $payableTotal],
            ['name' => '所有者权益', 'amount' => $cash + $stockValue + $receivableTotal - $payableTotal],
            ['name' => '负债+权益总计', 'amount' => $cash + $stockValue + $receivableTotal],
        ];

        $stats = [
            'total_income' => $receiveTotal,
            'total_expense' => $payTotal + $expenseTotal,
            'stock_value' => $stockValue,
            'receivable_total' => $receivableTotal,
            'payable_total' => $payableTotal,
            'cash' => $cash,
        ];

        return $this->success([
            'list' => $list,
            'stats' => $stats,
            'balance_assets' => $assets,
            'balance_liabilities' => $equityAndLiabilities,
        ]);
    }
}
