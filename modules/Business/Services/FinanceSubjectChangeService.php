<?php

namespace Modules\Business\Services;

use Carbon\CarbonInterface;
use Modules\Ledger\Models\VoucherEntry;

/**
 * 财务科目变化明细（B3）—— 逐科目流水与滚动余额查询视图
 *
 * 只读透出 A 机 ledger 全部分录，按科目（编码前缀）/日期区间/单据类型/是否排除开账前数据
 * 筛选，逐行滚出各科目余额。单据类型与单据号来自凭证（记账凭证 / 凭证字号）。
 * 开账前数据：日期早于 OPEN_DATE（2026-01-01）即视为开账前建账行。本服务不改写任何 ledger 数据。
 */
class FinanceSubjectChangeService
{
    /** 开账日期：此前的分录视为「开账前数据」 */
    private const OPEN_DATE = '2026-01-01';

    private function dateString($date): string
    {
        if (! $date) {
            return '';
        }

        return $date instanceof CarbonInterface ? $date->format('Y-m-d') : (string) $date;
    }

    /** 单据类型字典（下拉筛选用） */
    public function docTypes(): array
    {
        return ['记账凭证', '开账建账'];
    }

    /**
     * @return array{list: array, total: int, doc_types: array}
     */
    public function list(array $filters = [], int $page = 1, int $pageSize = 30): array
    {
        $query = VoucherEntry::query()
            ->join('ledger_vouchers', 'ledger_voucher_entries.voucher_id', '=', 'ledger_vouchers.id')
            ->where('ledger_vouchers.status', '!=', 'reversed');

        // 科目：按编码前缀匹配（父科目匹配子科目）
        if (! empty($filters['subject_id'])) {
            $query->where('ledger_voucher_entries.subject_code', 'like', $filters['subject_id'].'%');
        }

        // 单据类型：开账建账 即开账前数据行；记账凭证 即正常分录
        if (! empty($filters['doc_type']) && $filters['doc_type'] === '开账建账') {
            $query->where('ledger_vouchers.voucher_date', '<', self::OPEN_DATE);
        } elseif (! empty($filters['doc_type']) && $filters['doc_type'] === '记账凭证') {
            $query->where('ledger_vouchers.voucher_date', '>=', self::OPEN_DATE);
        }

        // 日期区间
        if (! empty($filters['start_date'])) {
            $query->where('ledger_vouchers.voucher_date', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where('ledger_vouchers.voucher_date', '<=', $filters['end_date']);
        }

        // 不含开账前数据
        $excludeBeforeOpen = ! empty($filters['exclude_before_open']);
        if ($excludeBeforeOpen) {
            $query->where('ledger_vouchers.voucher_date', '>=', self::OPEN_DATE);
        }

        $query->orderBy('ledger_vouchers.voucher_date')
            ->orderBy('ledger_vouchers.id')
            ->orderBy('ledger_voucher_entries.line_no');

        $rows = $query->get([
            'ledger_voucher_entries.id', 'ledger_voucher_entries.subject_code', 'ledger_voucher_entries.subject_name',
            'ledger_voucher_entries.summary', 'ledger_voucher_entries.debit_amount', 'ledger_voucher_entries.credit_amount',
            'ledger_vouchers.voucher_no', 'ledger_vouchers.voucher_date', 'ledger_vouchers.red_of_id',
        ]);

        // 逐科目滚出运行余额（全量过滤集上计算，保证跨页余额连续）
        $running = [];
        $mapped = $rows->map(function ($row) use (&$running) {
            $subjectId = $row->subject_code ?: $row->subject_name;
            $running[$subjectId] = ($running[$subjectId] ?? 0) + (float) $row->debit_amount - (float) $row->credit_amount;
            $beforeOpen = $row->voucher_date && (string) $row->voucher_date < self::OPEN_DATE;

            return [
                'id' => $row->id,
                'date' => $this->dateString($row->voucher_date),
                'doc_type' => $beforeOpen ? '开账建账' : '记账凭证',
                'doc_no' => $row->voucher_no,
                'subject_id' => $subjectId,
                'subject' => $row->subject_name,
                'summary' => (string) $row->summary,
                'debit' => (float) $row->debit_amount,
                'credit' => (float) $row->credit_amount,
                'direction' => (float) $row->debit_amount > 0 ? '借' : '贷',
                'balance' => round($running[$subjectId], 2),
                'before_open' => $beforeOpen,
                'voucher_no' => $row->voucher_no,
            ];
        })->values();

        $total = $mapped->count();

        return [
            'list' => $mapped->slice(($page - 1) * $pageSize, $pageSize)->values()->all(),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => (int) ceil($total / $pageSize),
            'doc_types' => $this->docTypes(),
        ];
    }
}
