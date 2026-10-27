<?php

namespace Modules\Business\Services;

use Modules\Ledger\Models\VoucherEntry;

/**
 * 财务日记账（B1）—— 只读查询视图
 *
 * 现金/银行日记账：从 A 机凭证体系（ledger）读取现金(1001)/银行(1002)科目流水，
 * 聚合成日记账行（收入/支出/余额）。本服务不改写任何 ledger 数据，仅作查询透出。
 */
class FinanceJournalService
{
    /** 现金/银行科目编码前缀（库存现金=1001，银行存款=1002） */
    private const CASH_BANK_PREFIXES = ['1001', '1002'];

    public function list(array $filters = [], int $page = 1, int $pageSize = 30): array
    {
        $query = VoucherEntry::query()
            ->join('ledger_vouchers', 'ledger_voucher_entries.voucher_id', '=', 'ledger_vouchers.id')
            ->where(function ($q) {
                foreach (self::CASH_BANK_PREFIXES as $prefix) {
                    $q->orWhere('ledger_voucher_entries.subject_code', 'like', $prefix.'%');
                }
            })
            ->where('ledger_vouchers.status', '!=', 'reversed');

        // 含红冲：默认排除红冲单（red_of_id 非空为红冲分录）
        if (empty($filters['include_red'])) {
            $query->whereNull('ledger_vouchers.red_of_id');
        }

        // 科目：按编码前缀匹配（父科目匹配子科目）
        if (! empty($filters['subject_id'])) {
            $query->where('ledger_voucher_entries.subject_code', 'like', $filters['subject_id'].'%');
        }

        // 凭证字号模糊
        if (! empty($filters['voucher_no'])) {
            $query->where('ledger_vouchers.voucher_no', 'like', '%'.$filters['voucher_no'].'%');
        }

        // 摘要关键字
        if (! empty($filters['summary'])) {
            $query->where('ledger_voucher_entries.summary', 'like', '%'.$filters['summary'].'%');
        }

        // 经手人（制单人）
        if (! empty($filters['salesman'])) {
            $query->where('ledger_vouchers.maker_name', $filters['salesman']);
        }

        // 日期：按记账日期（book）或操作日期（op=创建时间）筛选
        $dateCol = ($filters['date_type'] ?? 'book') === 'op' ? 'ledger_vouchers.created_at' : 'ledger_vouchers.voucher_date';
        if (! empty($filters['start_date'])) {
            $query->where($dateCol, '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where($dateCol, '<=', $filters['end_date'].' 23:59:59');
        }

        // 记账日期正序，保证余额逐行可重排
        $query->orderBy('ledger_vouchers.voucher_date')
            ->orderBy('ledger_vouchers.id')
            ->orderBy('ledger_voucher_entries.line_no');

        // 全量收集（日记账需逐行算余额），再手动分页
        $rows = $query->get(['ledger_voucher_entries.*', 'ledger_vouchers.voucher_no',
            'ledger_vouchers.voucher_date', 'ledger_vouchers.period',
            'ledger_vouchers.maker_name', 'ledger_vouchers.red_of_id']);

        $mapped = $rows->map(function ($row) {
            return $this->mapRow($row);
        })->values();

        // 逐行滚出运行余额（全量过滤集上计算，保证跨页余额连续）
        $balance = 0;
        $mapped = $mapped->map(function ($row) use (&$balance) {
            $balance += $row['income'] - $row['outcome'];
            $row['balance'] = round($balance, 2);

            return $row;
        })->values();

        $total = $mapped->count();

        $incomeTotal = round($mapped->sum('income'), 2);
        $outcomeTotal = round($mapped->sum('outcome'), 2);

        return [
            'list' => $mapped->slice(($page - 1) * $pageSize, $pageSize)->values()->all(),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => (int) ceil($total / $pageSize),
            'income_total' => $incomeTotal,
            'outcome_total' => $outcomeTotal,
        ];
    }

    private function mapRow(VoucherEntry $row): array
    {
        return [
            'id' => $row->id,
            'date' => optional($row->voucher_date)->format('Y-m-d'),
            'op_date' => optional($row->created_at)->format('Y-m-d'),
            'doc_type' => '',
            'doc_no' => '',
            'voucher_no' => $row->voucher_no,
            'subject_id' => $row->subject_code,
            'subject' => $row->subject_name,
            'summary' => (string) $row->summary,
            'income' => (float) $row->debit_amount,
            'outcome' => (float) $row->credit_amount,
            'salesman' => (string) $row->maker_name,
            'is_red' => ! empty($row->red_of_id),
        ];
    }
}
