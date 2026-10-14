<?php

namespace Modules\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Period;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Models\VoucherEntry;

/**
 * 财务月结业务逻辑：期间列表、结账检查、结转损益、月结/反结账。
 *
 * 结账检查（点「结账」后弹出检查窗，逐项打勾）：
 *  ① 本期凭证是否全部审核（存在未审核凭证则红字列出张数并阻止）；
 *  ② 试算是否平衡；
 *  ③ 业务单据是否都已生成凭证（只检查并提示，不阻止，显示黄色警告）；
 *  ④ 损益类科目是否需要结转（提供「自动生成结转损益凭证」按钮）；
 *  ⑤ 应收应付期间与现金流水是否平衡（提示性）。
 *
 * 反结账仅允许从最后一个已结账期间逆向操作，需二次确认并记录操作日志。
 */
class PeriodService
{
    public function __construct(
        private VoucherService $voucherService,
        private SubjectService $subjectService,
    ) {}

    /** 期间列表（按年度折叠） */
    public function list(): array
    {
        $periods = Period::orderByDesc('year')->orderBy('month')->get();
        $byYear = $periods->groupBy('year');

        $years = [];
        foreach ($byYear as $year => $items) {
            $years[] = [
                'year' => (int) $year,
                'periods' => $items->map(fn ($p) => $this->formatPeriod($p))->all(),
            ];
        }

        return ['years' => $years];
    }

    /** 结账检查 */
    public function check(string $period): array
    {
        $checks = [];

        // ① 本期凭证是否全部审核
        $unauditedCount = Voucher::where('period', $period)
            ->whereIn('status', [Voucher::STATUS_DRAFT, Voucher::STATUS_SUBMITTED])
            ->count();
        $checks[] = [
            'key' => 'all_audited',
            'title' => '本期凭证全部审核',
            'passed' => $unauditedCount === 0,
            'blocking' => true,
            'detail' => $unauditedCount > 0 ? '存在未审核凭证 '.$unauditedCount.' 张' : '全部已审核',
        ];

        // ② 试算是否平衡
        $balanced = $this->isTrialBalanced($period);
        $checks[] = [
            'key' => 'trial_balance',
            'title' => '试算平衡',
            'passed' => $balanced,
            'blocking' => true,
            'detail' => $balanced ? '借贷平衡' : '借贷不平衡',
        ];

        // ③ 业务单据是否都已生成凭证（本期只检查并提示，不阻止）
        $checks[] = [
            'key' => 'business_voucher',
            'title' => '业务单据已生成凭证',
            'passed' => true,
            'blocking' => false,
            'detail' => '自动凭证尚未全打通，请人工确认业务单据是否已生成凭证',
        ];

        // ④ 损益类科目是否需要结转
        $needCarryForward = $this->needCarryForward($period);
        $checks[] = [
            'key' => 'carry_forward',
            'title' => '损益类科目已结转',
            'passed' => ! $needCarryForward,
            'blocking' => false,
            'detail' => $needCarryForward ? '存在未结转的损益类科目余额，建议先生成结转损益凭证' : '损益已结转',
        ];

        // ⑤ 应收应付期间与现金流水是否平衡（提示性）
        $checks[] = [
            'key' => 'ar_ap_cash',
            'title' => '应收应付与现金流水平衡',
            'passed' => true,
            'blocking' => false,
            'detail' => '提示性检查，请人工核对',
        ];

        $blockingPassed = true;
        foreach ($checks as $c) {
            if ($c['blocking'] && ! $c['passed']) {
                $blockingPassed = false;
                break;
            }
        }

        return [
            'period' => $period,
            'checks' => $checks,
            'can_close' => $blockingPassed,
        ];
    }

    /** 一键结转损益：把收入、成本、费用类科目本期余额结转到 4103 本年利润 */
    public function carryForward(string $period, int $makerId, string $makerName): Voucher
    {
        return DB::transaction(function () use ($period, $makerId, $makerName) {
            $profitSubject = Subject::where('code', '4103')->first();
            if (! $profitSubject) {
                throw new \DomainException('未找到 4103 本年利润科目，请先预置科目');
            }

            // 汇总本期各损益类科目余额（已审核凭证）
            $balances = $this->subjectPeriodBalances($period, Subject::profitLossCategories());

            $entries = [];
            $totalProfit = 0.0; // 正数表示盈利（收入大于成本费用）
            foreach ($balances as $b) {
                $subject = Subject::find($b->subject_id);
                if (! $subject) {
                    continue;
                }
                // 借方科目（成本/费用）余额 = 借方发生 - 贷方发生
                // 贷方科目（收入）余额 = 贷方发生 - 借方发生
                if ($subject->balance_direction === Subject::DIRECTION_DEBIT) {
                    $amount = round((float) $b->total_debit - (float) $b->total_credit, 2);
                    if (abs($amount) < 0.005) {
                        continue;
                    }
                    // 成本费用结转：贷方记该科目（减少）
                    $entries[] = [
                        'subject_id' => $subject->id,
                        'subject_code' => $subject->code,
                        'subject_name' => $subject->name,
                        'summary' => '结转'.$subject->name.'至本年利润',
                        'debit_amount' => 0,
                        'credit_amount' => round(abs($amount), 2),
                    ];
                    $totalProfit -= abs($amount);
                } else {
                    $amount = round((float) $b->total_credit - (float) $b->total_debit, 2);
                    if (abs($amount) < 0.005) {
                        continue;
                    }
                    // 收入结转：借方记该科目（减少）
                    $entries[] = [
                        'subject_id' => $subject->id,
                        'subject_code' => $subject->code,
                        'subject_name' => $subject->name,
                        'summary' => '结转'.$subject->name.'至本年利润',
                        'debit_amount' => round(abs($amount), 2),
                        'credit_amount' => 0,
                    ];
                    $totalProfit += abs($amount);
                }
            }

            if (empty($entries)) {
                throw new \DomainException('本期损益类科目无余额，无需结转');
            }

            // 本年利润科目：盈利记贷方，亏损记借方
            $profitEntry = [
                'subject_id' => $profitSubject->id,
                'subject_code' => $profitSubject->code,
                'subject_name' => $profitSubject->name,
                'summary' => '结转本期损益至本年利润',
                'debit_amount' => $totalProfit < 0 ? round(abs($totalProfit), 2) : 0,
                'credit_amount' => $totalProfit >= 0 ? round(abs($totalProfit), 2) : 0,
            ];

            // 平衡：本年利润金额补齐
            $debitSum = array_sum(array_column($entries, 'debit_amount')) + $profitEntry['debit_amount'];
            $creditSum = array_sum(array_column($entries, 'credit_amount')) + $profitEntry['credit_amount'];
            $entries[] = $profitEntry;

            $voucher = $this->voucherService->create([
                'voucher_date' => now()->format('Y-m-d'),
                'entries' => $entries,
                'remark' => '结转损益自动生成凭证',
                'status' => Voucher::STATUS_DRAFT,
            ], $makerId, $makerName);

            return $voucher;
        });
    }

    /** 月结：全部必检项通过后结账，期间锁定 */
    public function close(string $period, int $closedById, string $closedByName): Period
    {
        return DB::transaction(function () use ($period, $closedById, $closedByName) {
            $check = $this->check($period);
            if (! $check['can_close']) {
                throw new \DomainException('结账检查未通过，存在未完成的必检项');
            }

            $p = $this->getPeriod($period);
            // 统计本期已审核凭证
            $vouchers = Voucher::where('period', $period)->where('status', Voucher::STATUS_AUDITED)->get();
            $totalDebit = $vouchers->sum('total_debit');
            $totalCredit = $vouchers->sum('total_credit');

            $p->status = Period::STATUS_CLOSED;
            $p->closed_by = $closedById;
            $p->closed_by_name = $closedByName;
            $p->closed_at = now();
            $p->voucher_count = $vouchers->count();
            $p->total_debit = round((float) $totalDebit, 2);
            $p->total_credit = round((float) $totalCredit, 2);
            $p->check_report = $check;
            $p->save();

            return $p->fresh();
        });
    }

    /** 反结账：仅允许从最后一个已结账期间逆向操作 */
    public function reopen(string $period): Period
    {
        return DB::transaction(function () use ($period) {
            $lastClosed = Period::where('status', Period::STATUS_CLOSED)
                ->orderByDesc('year')->orderByDesc('month')
                ->first();
            $target = $this->getPeriod($period);
            if (! $target || $target->status !== Period::STATUS_CLOSED) {
                throw new \DomainException('该期间未结账，无需反结账');
            }
            if (! $lastClosed || $lastClosed->id !== $target->id) {
                throw new \DomainException('只能反结账最后一个已结账期间');
            }

            $target->status = Period::STATUS_OPEN;
            $target->closed_by = null;
            $target->closed_by_name = null;
            $target->closed_at = null;
            $target->save();

            return $target->fresh();
        });
    }

    /** 试算平衡：所有科目本期借贷合计相等 */
    private function isTrialBalanced(string $period): bool
    {
        $rows = $this->subjectPeriodBalances($period);
        $debit = 0;
        $credit = 0;
        foreach ($rows as $r) {
            $debit += (float) $r->total_debit;
            $credit += (float) $r->total_credit;
        }

        return abs(round($debit, 2) - round($credit, 2)) < 0.005;
    }

    /** 是否需要结转损益：损益类科目本期有余额 */
    private function needCarryForward(string $period): bool
    {
        $balances = $this->subjectPeriodBalances($period, Subject::profitLossCategories());
        foreach ($balances as $b) {
            $subject = Subject::find($b->subject_id);
            if (! $subject) {
                continue;
            }
            if ($subject->balance_direction === Subject::DIRECTION_DEBIT) {
                if (abs((float) $b->total_debit - (float) $b->total_credit) >= 0.005) {
                    return true;
                }
            } else {
                if (abs((float) $b->total_credit - (float) $b->total_debit) >= 0.005) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 某期间各科目借贷发生额（已审核凭证） */
    private function subjectPeriodBalances(string $period, array $categories = []): array
    {
        $query = VoucherEntry::select(
            'subject_id',
            DB::raw('SUM(debit_amount) as total_debit'),
            DB::raw('SUM(credit_amount) as total_credit')
        )
            ->whereHas('voucher', function ($q) use ($period) {
                $q->where('period', $period)->where('status', Voucher::STATUS_AUDITED);
            })
            ->groupBy('subject_id');

        if (! empty($categories)) {
            $query->whereHas('subject', function ($q) use ($categories) {
                $q->whereIn('category', $categories);
            });
        }

        return $query->get()->all();
    }

    private function getPeriod(string $period): ?Period
    {
        return Period::where('year', (int) substr($period, 0, 4))
            ->where('month', (int) substr($period, 5, 2))
            ->first();
    }

    private function formatPeriod(Period $p): array
    {
        return [
            'id' => $p->id,
            'period' => $p->periodString(),
            'year' => $p->year,
            'month' => $p->month,
            'start_date' => $p->start_date?->format('Y-m-d'),
            'end_date' => $p->end_date?->format('Y-m-d'),
            'status' => $p->status,
            'status_label' => Period::statusLabel($p->status),
            'voucher_count' => $p->voucher_count,
            'total_debit' => (float) $p->total_debit,
            'total_credit' => (float) $p->total_credit,
            'closed_by_name' => $p->closed_by_name,
            'closed_at' => $p->closed_at?->format('Y-m-d H:i:s'),
        ];
    }
}
