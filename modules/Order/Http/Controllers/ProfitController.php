<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfitController extends Controller
{
    use ResponseTrait;

    /**
     * 区间解析：start_date+end_date 同时存在优先；其次 month=last/quarter；否则当前自然月。
     *
     * 返回 [$start, $endExclusive]。上界必须是**开区间**：date cast 列在 sqlite 里存成
     * 'YYYY-MM-DD 00:00:00'，`<= 'YYYY-MM-DD'` 会静默丢掉 end_date 当天那一笔。
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(Request $request): array
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        if ($startDate && $endDate) {
            $request->validate([
                'start_date' => 'date',
                'end_date' => 'date|after_or_equal:start_date',
            ]);

            $start = CarbonImmutable::parse($startDate)->startOfDay();

            return [$start, CarbonImmutable::parse($endDate)->startOfDay()->addDay()];
        }

        $month = $request->input('month');
        if ($month === 'last') {
            // subMonthNoOverflow：31 号跑 subMonth 会溢出回本月，month=last 就取不到上个自然月了
            return [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->startOfMonth()->addMonth()];
        }
        if ($month === 'quarter') {
            return [now()->startOfQuarter(), now()->startOfQuarter()->addMonthsNoOverflow(3)];
        }

        return [now()->startOfMonth(), now()->startOfMonth()->addMonth()];
    }

    /** @param string $endExclusive 开区间上界，见 resolveRange */
    private function summarize(string $table, string $dateColumn, string $start, string $endExclusive): array
    {
        $base = DB::table($table)
            ->where('status', 1)
            ->where($dateColumn, '>=', $start)
            ->where($dateColumn, '<', $endExclusive);

        return [
            'amount' => (float) (clone $base)->sum('amount'),
            'count' => $base->count(),
        ];
    }

    /**
     * 利润数据（index 与 export 共用）。
     *
     * @return array{list: array, stats: array, balance_assets: array, balance_liabilities: array, start_date: string, end_date: string}
     */
    private function profitData(Request $request): array
    {
        [$start, $endExclusive] = $this->resolveRange($request);
        $startStr = $start->toDateString();
        $endExclusiveStr = $endExclusive->toDateString();

        $receive = $this->summarize('receives', 'receive_date', $startStr, $endExclusiveStr);
        $pay = $this->summarize('pays', 'pay_date', $startStr, $endExclusiveStr);
        $expense = $this->summarize('expenses', 'expense_date', $startStr, $endExclusiveStr);

        $receiveTotal = $receive['amount'];
        $payTotal = $pay['amount'];
        $expenseTotal = $expense['amount'];

        $list = [
            ['category' => '收款收入', 'amount' => $receiveTotal, 'count' => $receive['count']],
            ['category' => '付款支出', 'amount' => $payTotal, 'count' => $pay['count']],
            ['category' => '费用支出', 'amount' => $expenseTotal, 'count' => $expense['count']],
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

        return [
            'list' => $list,
            'stats' => $stats,
            'balance_assets' => $assets,
            'balance_liabilities' => $equityAndLiabilities,
            'start_date' => $startStr,
            'end_date' => $endExclusive->subDay()->toDateString(),
        ];
    }

    /** 利润查询：收入(收款) - 支出(付款+费用)，按类别分组 + 资产负债表 */
    public function index(Request $request)
    {
        return $this->success($this->profitData($request));
    }

    /** 导出利润表 (CSV格式)，区间与 index 同参数同口径 */
    public function export(Request $request)
    {
        $data = $this->profitData($request);

        $csv = '﻿'; // BOM for Excel UTF-8
        $csv .= "利润查询\n";
        $csv .= "查询期间：{$data['start_date']} 至 {$data['end_date']}\n\n";
        $csv .= "项目,金额,笔数\n";

        foreach ($data['list'] as $row) {
            $csv .= sprintf("%s,%.2f,%d\n", $row['category'], $row['amount'], $row['count']);
        }

        $csv .= sprintf(
            "合计,%.2f,%d\n",
            $data['stats']['total_income'] - $data['stats']['total_expense'],
            array_sum(array_column($data['list'], 'count'))
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }
}
