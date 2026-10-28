<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 资金看板（B8）：现金/银行余额、今日收/支、近 7 日收支折线、待确认上交款。
 *
 * 与资金流水（CashFlowController 的 cash_flow 口径）实时一致：数据源同为
 * cash_flows 表。符号口径按全部写入方核对：receive 行自带符号（正常收款为正，
 * 收款红冲为负自然冲减收入）；pay/expense/red_flush 行一律按支出取 ABS（兼容
 * SalesReturn/资金单族负数直插与正数直插两种写法）。账户归类：现金=现金/微信/
 * 支付宝（出纳手边资金），银行=银行卡/银行（含英文键），挂账/退货退款等信用类不入资金卡。
 */
class FinanceFundDashboardController extends Controller
{
    use ResponseTrait;

    /** 现金类支付方式（出纳库存现金口径：现金 + 移动收单） */
    private const CASH_METHODS = ['现金', '微信', '支付宝', 'cash', 'wechat', 'alipay'];

    /** 银行类支付方式 */
    private const BANK_METHODS = ['银行卡', '银行', 'bank', 'card'];

    /** 收入方向（其余 pay/expense/red_flush 为支出方向） */
    private const INCOME_TYPES = ['receive'];

    /** 近 N 日折线 */
    private const TREND_DAYS = 7;

    /** 账户净额 CASE WHEN：命中该账户支付方式时，收入正、支出负 */
    private function accountNetCase(string $account): string
    {
        $methods = $account === 'bank' ? self::BANK_METHODS : self::CASH_METHODS;
        $in = implode(',', array_map(fn ($m) => "'{$m}'", $methods));

        return "CASE WHEN payment_method IN ({$in}) THEN {$this->signedAmount()} ELSE 0 END";
    }

    /** 收入 CASE WHEN：receive 行取存储原值（红冲负数自然冲减） */
    private function incomeCase(): string
    {
        $income = implode("','", self::INCOME_TYPES);

        return "CASE WHEN flow_type IN ('{$income}') THEN amount ELSE 0 END";
    }

    /** 支出 CASE WHEN：非收入类型（pay/expense/red_flush）一律支出，取 ABS */
    private function expenseCase(): string
    {
        $income = implode("','", self::INCOME_TYPES);

        return "CASE WHEN flow_type IN ('{$income}') THEN 0 ELSE ABS(amount) END";
    }

    /** 净额方向符号：收入取原值，支出一律负 ABS */
    private function signedAmount(): string
    {
        $income = implode("','", self::INCOME_TYPES);

        return "CASE WHEN flow_type IN ('{$income}') THEN amount ELSE -ABS(amount) END";
    }

    /** 资金看板聚合：4 卡 + 近 7 日折线 + 待确认上交款 */
    public function index(Request $request)
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // 余额 = 全量收入-支出的现金/银行净额（与资金流水同源，实时一致）
        $bal = DB::table('cash_flows')
            ->selectRaw('COALESCE(SUM('.$this->accountNetCase('cash').'), 0) as cash')
            ->selectRaw('COALESCE(SUM('.$this->accountNetCase('bank').'), 0) as bank')
            ->first();

        $dayAgg = function (string $date) {
            return DB::table('cash_flows')
                ->whereDate('flow_date', $date)
                ->selectRaw('COALESCE(SUM('.$this->incomeCase().'), 0) as income')
                ->selectRaw('COALESCE(SUM('.$this->expenseCase().'), 0) as expense')
                ->first();
        };
        $t = $dayAgg($today);
        $y = $dayAgg($yesterday);

        $todayIncome = (float) ($t->income ?? 0);
        $todayExpense = (float) ($t->expense ?? 0);

        // 近 7 日折线：含当日，无流水的日期补 0（禁 NaN）
        $start = now()->subDays(self::TREND_DAYS - 1)->toDateString();
        $rows = DB::table('cash_flows')
            ->whereBetween('flow_date', [$start, $today])
            ->select('flow_date')
            ->selectRaw('COALESCE(SUM('.$this->incomeCase().'), 0) as income')
            ->selectRaw('COALESCE(SUM('.$this->expenseCase().'), 0) as expense')
            ->groupBy('flow_date')
            ->get()
            ->keyBy('flow_date');

        $trend = [];
        for ($i = self::TREND_DAYS - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $rows->get($date);
            $trend[] = [
                'date' => $date,
                'income' => round((float) ($row->income ?? 0), 2),
                'expense' => round((float) ($row->expense ?? 0), 2),
            ];
        }

        return $this->success([
            'cash_balance' => round((float) ($bal->cash ?? 0), 2),
            'bank_balance' => round((float) ($bal->bank ?? 0), 2),
            'today_income' => round($todayIncome, 2),
            'today_expense' => round($todayExpense, 2),
            'income_ratio' => $this->growthRate($todayIncome, (float) ($y->income ?? 0)),
            'expense_ratio' => $this->growthRate($todayExpense, (float) ($y->expense ?? 0)),
            'trend' => $trend,
        ]);
    }

    /** 待确认上交款列表（delivery_remit status=remitted，出纳未确认） */
    public function pendingHandover(Request $request)
    {
        $limit = min(50, max(5, (int) $request->input('limit', 20)));

        $list = DB::table('delivery_remit')
            ->where('status', 'remitted')
            ->orderBy('remit_date')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'remit_no', 'remit_date', 'delivery_person_name', 'cash_amount', 'bank_amount', 'total_amount'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'remit_no' => $r->remit_no,
                'remit_date' => $r->remit_date,
                'delivery_person_name' => $r->delivery_person_name,
                'cash_amount' => round((float) $r->cash_amount, 2),
                'bank_amount' => round((float) $r->bank_amount, 2),
                'total_amount' => round((float) $r->total_amount, 2),
            ]);

        $total = DB::table('delivery_remit')->where('status', 'remitted')->count();

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /** 增长率（%）：基期为 0 时，本期 >0 记 100，否则 0（与 Dashboard 大屏同口径） */
    private function growthRate(float $current, float $base): float
    {
        if ($base <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round(($current - $base) / $base * 100, 1);
    }
}
