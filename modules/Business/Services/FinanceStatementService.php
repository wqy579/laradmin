<?php

namespace Modules\Business\Services;

use Carbon\CarbonInterface;
use Modules\Ledger\Models\VoucherEntry;

/**
 * 财务对账单（B3）—— 客户应收 / 供应商应付 对账单查询视图
 *
 * 只读透出 A 机 ledger 的往来科目（应收账款 1122 / 应付账款 2202）流水，
 * 按「往来单位 + 期间（月）」归集期初/发生/收付/期末余额，并提供逐笔往来明细。
 * 对账状态无独立存储，按收付完成度实时推导（settled/partial/unsettled）。
 * 本服务不改写任何 ledger 数据。
 */
class FinanceStatementService
{
    /** 客户应收科目编码 = 应收账款 */
    private const SUBJECT_AR = '1122';

    /** 供应商应付科目编码 = 应付账款 */
    private const SUBJECT_AP = '2202';

    /**
     * 对账单数据集：往来单位字典 + 期间汇总 + 往来明细
     *
     * @param  string  $kind  customer=客户应收 / supplier=供应商应付
     * @param  int|null  $unitId  指定往来单位（null=全部）
     */
    public function dataset(string $kind = 'customer', ?int $unitId = null): array
    {
        $subjectCode = $kind === 'supplier' ? self::SUBJECT_AP : self::SUBJECT_AR;
        $auxType = $kind === 'supplier' ? 'supplier' : 'customer';

        // 该科目下所有往来分录（已审核、排除红冲），关联凭证头
        $rows = VoucherEntry::query()
            ->join('ledger_vouchers', 'ledger_voucher_entries.voucher_id', '=', 'ledger_vouchers.id')
            ->where('ledger_voucher_entries.subject_code', $subjectCode)
            ->where('ledger_vouchers.status', 'audited')
            ->whereNull('ledger_vouchers.red_of_id')
            ->where('ledger_voucher_entries.aux_type', $auxType)
            ->orderBy('ledger_vouchers.voucher_date')
            ->orderBy('ledger_vouchers.id')
            ->orderBy('ledger_voucher_entries.line_no')
            ->get([
                'ledger_voucher_entries.id', 'ledger_voucher_entries.aux_id', 'ledger_voucher_entries.aux_name',
                'ledger_voucher_entries.summary', 'ledger_voucher_entries.debit_amount', 'ledger_voucher_entries.credit_amount',
                'ledger_vouchers.voucher_no', 'ledger_vouchers.voucher_date', 'ledger_vouchers.period',
            ]);

        // 往来单位字典
        $units = [];
        foreach ($rows as $r) {
            if ($r->aux_id) {
                $units[$r->aux_id] = [
                    'id' => (int) $r->aux_id,
                    'kind' => $kind,
                    'name' => $r->aux_name ?: ('往来单位#'.$r->aux_id),
                ];
            }
        }
        $units = array_values($units);
        $unitMap = collect($units)->keyBy('id');

        if ($unitId) {
            $rows = $rows->filter(fn ($r) => (int) $r->aux_id === (int) $unitId)->values();
        }

        // 期间汇总：按月归集各往来单位的期初/发生/收付/期末
        $periods = $this->aggregatePeriods($rows, $kind, $unitMap);

        // 往来明细：按凭证归并（一张凭证对某单位的一条应收/应付流水）
        $details = $this->aggregateDetails($rows, $kind, $unitMap);

        return [
            'units' => $units,
            'periods' => $periods,
            'details' => $details,
        ];
    }

    private function aggregatePeriods($rows, string $kind, $unitMap): array
    {
        // 收集涉及的会计期间（升序）
        $periodKeys = $rows->map(fn ($r) => $r->period)->filter()->unique()->sort()->values()->all();

        // 期间排序键
        $periodSort = function ($p) {
            [$y, $m] = array_pad(explode('-', $p), 2, '01');

            return ((int) $y) * 100 + (int) $m;
        };
        usort($periodKeys, fn ($a, $b) => $periodSort($a) <=> $periodSort($b));

        // 每单位分期间累计 net（借-贷）
        $accum = []; // unitId -> period -> net
        foreach ($rows as $r) {
            if (! $r->period) {
                continue;
            }
            $uid = (int) $r->aux_id;
            $net = (float) $r->debit_amount - (float) $r->credit_amount;
            if ($kind === 'supplier') {
                $net = -$net; // 应付：贷方为发生
            }
            $accum[$uid][$r->period] = ($accum[$uid][$r->period] ?? 0) + $net;
        }

        $periods = [];
        foreach ($unitMap as $uid => $u) {
            if (! isset($accum[$uid])) {
                continue;
            }
            $opening = 0;
            foreach ($periodKeys as $pk) {
                $net = $accum[$uid][$pk] ?? 0;
                $unitPeriods = $rows->filter(fn ($r) => (int) $r->aux_id === $uid && $r->period === $pk);
                $occurrence = $unitPeriods->sum(fn ($r) => $kind === 'supplier' ? (float) $r->credit_amount : (float) $r->debit_amount);
                $settle = $unitPeriods->sum(fn ($r) => $kind === 'supplier' ? (float) $r->debit_amount : (float) $r->credit_amount);
                $closing = round($opening + $occurrence - $settle, 2);

                [$y, $m] = explode('-', $pk);
                $periods[] = [
                    'unit_id' => $uid,
                    'unitName' => $u['name'],
                    'year' => (int) $y,
                    'month' => (int) $m,
                    'opening_balance' => round($opening, 2),
                    'receivable' => $kind === 'supplier' ? 0 : round($occurrence, 2),
                    'payable' => $kind === 'supplier' ? round($occurrence, 2) : 0,
                    'received' => $kind === 'supplier' ? 0 : round($settle, 2),
                    'paid' => $kind === 'supplier' ? round($settle, 2) : 0,
                    'closing_balance' => $closing,
                    'recon_status' => $this->reconStatus($closing, $settle),
                    'recon_date' => $this->reconDate($unitPeriods),
                ];
                $opening = $closing;
            }
        }

        return $periods;
    }

    private function reconStatus(float $closing, float $settle): string
    {
        if (abs($closing) < 0.005) {
            return 'settled';
        }

        return $settle > 0 ? 'partial' : 'unsettled';
    }

    private function dateString($date): string
    {
        if (! $date) {
            return '';
        }

        return $date instanceof CarbonInterface ? $date->format('Y-m-d') : (string) $date;
    }

    private function reconDate($unitPeriods): ?string
    {
        $date = $unitPeriods->map(fn ($r) => $this->dateString($r->voucher_date))->filter()->max();

        return $date ?: null;
    }

    private function aggregateDetails($rows, string $kind, $unitMap): array
    {
        $details = [];
        // 按 单位 + 凭证 归并为一条往来明细
        $groups = $rows->groupBy(fn ($r) => $r->aux_id.'|'.$r->voucher_id);
        foreach ($groups as $key => $group) {
            $first = $group->first();
            $uid = (int) $first->aux_id;
            $debit = $group->sum(fn ($r) => (float) $r->debit_amount);
            $credit = $group->sum(fn ($r) => (float) $r->credit_amount);

            [$y, $m] = $first->period ? explode('-', $first->period) : ['', ''];
            $details[] = [
                'unit_id' => $uid,
                'unitName' => $unitMap[$uid]['name'] ?? '',
                'year' => (int) $y,
                'month' => (int) $m,
                'date' => $this->dateString($first->voucher_date),
                'doc_no' => $first->voucher_no,
                'doc_type' => '记账凭证',
                'summary' => (string) $first->summary,
                'amount' => $kind === 'supplier' ? round($credit, 2) : round($debit, 2),
                'settle' => $kind === 'supplier' ? round($debit, 2) : round($credit, 2),
                'matched' => true,
            ];
        }
        // 按日期排序
        usort($details, fn ($a, $b) => ($a['date'] ?? '') <=> ($b['date'] ?? ''));

        return $details;
    }
}
