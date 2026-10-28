<?php

namespace Modules\Cash\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashFlow;

/**
 * 现金账户收支查询（D-29）
 *
 * 余额口径：逐行累计。基线 = 账户期初余额 + 窗口起点（start_date）之前
 * 全部流水的净额，故带日期筛选时首行余额仍与真实账户余额连续。
 * 排序固定 日期→单据号→id，保证累计顺序跨分页、跨导出稳定。
 */
class CashFlowService
{
    /**
     * 分页查询账户流水，每行附 income/expense 拆分与逐行累计 balance。
     *
     * @return array{account: CashAccount, opening_balance: float, base_balance: float, list: array, total: int, page: int, page_size: int, last_page: int, income_total: float, expense_total: float}
     */
    public function query(int $accountId, string $startDate = '', string $endDate = '', string $direction = '', int $page = 1, int $pageSize = 20): array
    {
        $account = CashAccount::findOrFail($accountId);

        $baseBalance = (float) $account->opening_balance;
        if ($startDate !== '') {
            $baseBalance += (float) $this->netBefore($accountId, $startDate);
        }

        $q = CashFlow::query()->where('account_id', $accountId);
        if ($startDate !== '') {
            $q->where('flow_date', '>=', $startDate);
        }
        if ($endDate !== '') {
            $q->where('flow_date', '<=', $endDate);
        }
        if (in_array($direction, [CashFlow::DIRECTION_INCOME, CashFlow::DIRECTION_EXPENSE], true)) {
            $q->where('direction', $direction);
        }

        // 全窗口合计（合计行不受分页影响）
        $totals = (clone $q)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) as income_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) as expense_total")
            ->first();

        /** @var LengthAwarePaginator $pager */
        $pager = $q->orderBy('flow_date')->orderBy('flow_no')->orderBy('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        // 累计必须从基线按完整顺序递推：当前页之前的同筛选行也要计入，
        // 所以先取「排序在当前页之前」的行数与其中净额来补齐页首基线。
        $offset = ($pager->currentPage() - 1) * $pager->perPage();
        $balance = $baseBalance + $this->netOfFirst($accountId, $startDate, $endDate, $direction, $offset);

        $list = [];
        foreach ($pager->items() as $row) {
            $income = $row->direction === CashFlow::DIRECTION_INCOME ? (float) $row->amount : 0.0;
            $expense = $row->direction === CashFlow::DIRECTION_EXPENSE ? (float) $row->amount : 0.0;
            $balance += $income - $expense;

            $list[] = [
                'id' => $row->id,
                'date' => $row->flow_date->toDateString(),
                'no' => $row->flow_no,
                'summary' => $row->summary,
                'direction' => $row->direction,
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'balance' => round($balance, 2),
            ];
        }

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'opening_balance' => (float) $account->opening_balance,
            ],
            'opening_balance' => (float) $account->opening_balance,
            'base_balance' => round($baseBalance, 2),
            'list' => $list,
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
            'income_total' => round((float) $totals->income_total, 2),
            'expense_total' => round((float) $totals->expense_total, 2),
        ];
    }

    /** 窗口起点之前的净额（收入 − 支出），作为累计余额基线 */
    private function netBefore(int $accountId, string $startDate): float
    {
        return (float) CashFlow::query()
            ->where('account_id', $accountId)
            ->where('flow_date', '<', $startDate)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END), 0) as net")
            ->value('net');
    }

    /** 按固定排序取前 $offset 行的净额，用于补齐非首页的累计基线 */
    private function netOfFirst(int $accountId, string $startDate, string $endDate, string $direction, int $offset): float
    {
        if ($offset <= 0) {
            return 0.0;
        }

        // LIMIT 必须作用在取行上（派生表），直接对聚合加 LIMIT 只限制结果行数，
        // 会把整窗口净额当成页首基线。
        $head = $this->filtered($accountId, $startDate, $endDate, $direction)
            ->orderBy('flow_date')->orderBy('flow_no')->orderBy('id')
            ->limit($offset)
            ->select('direction', 'amount');

        return (float) CashFlow::query()
            ->fromSub($head, 'head')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END), 0) as net")
            ->value('net');
    }

    private function filtered(int $accountId, string $startDate, string $endDate, string $direction)
    {
        $q = CashFlow::query()->where('account_id', $accountId);
        if ($startDate !== '') {
            $q->where('flow_date', '>=', $startDate);
        }
        if ($endDate !== '') {
            $q->where('flow_date', '<=', $endDate);
        }
        if (in_array($direction, [CashFlow::DIRECTION_INCOME, CashFlow::DIRECTION_EXPENSE], true)) {
            $q->where('direction', $direction);
        }

        return $q;
    }
}
