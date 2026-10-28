<?php

namespace Modules\Cash\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashCardTxn;
use Modules\Cash\Models\CashFlow;
use Modules\Cash\Models\CashHandover;

/**
 * 出纳工作台聚合服务（D8，只读）
 *
 * 只做 cash_ 表的聚合与穿透口径，不新增业务流程与业务表。
 * 金额口径与 D1/D2 完全一致：库内 decimal(14,2) 元，出参 round(...,2)，
 * 前端直接格式化，不再二次换算。
 */
class CashDashboardService
{
    public const TREND_DEFAULT_DAYS = 7;

    public const TREND_MAX_DAYS = 31;

    /** 当日快照：收支、刷卡收款、账户余额合计、待确认上交款 */
    public function summary(): array
    {
        $today = now()->toDateString();

        $flow = CashFlow::query()
            ->whereDate('flow_date', $today)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) as income_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) as expense_total")
            ->first();

        // 退款行 status=refund 且金额为负，只对 success 求和即自然剔除退款
        $card = CashCardTxn::query()
            ->whereDate('txn_time', $today)
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as success_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END), 0) as success_count")
            ->first();

        $account = $this->enabledAccountTotal();
        $handover = CashHandover::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount")
            ->first();

        $income = round((float) $flow->income_total, 2);
        $expense = round((float) $flow->expense_total, 2);

        return [
            'today_income' => $income,
            'today_expense' => $expense,
            'today_net' => round($income - $expense, 2),
            'today_card_amount' => round((float) $card->success_amount, 2),
            'today_card_count' => (int) $card->success_count,
            'account_total' => $account['total'],
            'account_count' => $account['count'],
            'pending_handover_count' => (int) $handover->pending_count,
            'pending_handover_amount' => round((float) $handover->pending_amount, 2),
        ];
    }

    /** 近 N 日收支趋势：日期连续补零，按日期升序 */
    public function trend(int $days): array
    {
        // ?days=abc 时 Request::integer() 拿到的是 intval('abc')=0 而非默认值，需回落
        if ($days < 1) {
            $days = self::TREND_DEFAULT_DAYS;
        }
        $days = min(self::TREND_MAX_DAYS, $days);
        $start = now()->subDays($days - 1)->startOfDay();

        // 上界必须用「明天 00:00 开区间」：flow_date 是 date 列，sqlite 里存成
        // '2026-10-29 00:00:00'，字符串 '<= 2026-10-29' 会把当日行整个丢掉
        // （MySQL DATE 列没这问题）。下界 '< 日期' 仍恒真成立，不用动。
        // select 与 groupBy 都必须 raw，字符串参数会被加反引号当成列名
        $rows = CashFlow::query()
            ->where('flow_date', '>=', $start->toDateString())
            ->where('flow_date', '<', now()->addDay()->toDateString())
            ->selectRaw('DATE(flow_date) as d')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) as income")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) as expense")
            ->groupBy(DB::raw('DATE(flow_date)'))
            ->get();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[substr((string) $row->d, 0, 10)] = $row;
        }

        $list = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $row = $byDate[$date] ?? null;

            $list[] = [
                'date' => $date,
                'income' => round((float) ($row->income ?? 0), 2),
                'expense' => round((float) ($row->expense ?? 0), 2),
            ];
        }

        return ['days' => $days, 'list' => $list];
    }

    /**
     * 启用账户余额合计 = 期初合计 + 这些账户的全部流水净额。
     *
     * cash_account 没有余额列（D1 也是逐行累计得出），故用一条期初聚合
     * 加一条子查询过滤的净额聚合作差，不逐账户取数。
     *
     * @return array{total: float, count: int}
     */
    private function enabledAccountTotal(): array
    {
        $enabledIds = CashAccount::query()
            ->where('status', CashAccount::STATUS_ENABLED)
            ->select('id');

        $head = CashAccount::query()
            ->where('status', CashAccount::STATUS_ENABLED)
            ->selectRaw('COALESCE(SUM(opening_balance), 0) as opening_total')
            ->selectRaw('COUNT(*) as cnt')
            ->first();

        $net = (float) CashFlow::query()
            ->whereIn('account_id', $enabledIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END), 0) as net")
            ->value('net');

        return [
            'total' => round((float) $head->opening_total + $net, 2),
            'count' => (int) $head->cnt,
        ];
    }
}
