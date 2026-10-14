<?php

namespace Modules\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\OpeningBalance;
use Modules\Ledger\Models\Period;
use Modules\Ledger\Models\Subject;

/**
 * 期初余额业务逻辑：末级科目录入、非末级自动汇总、试算平衡。
 *
 * 已月结的期间禁止修改期初。
 */
class OpeningBalanceService
{
    /** 某期间的期初余额清单（末级科目可录入，非末级只读汇总） */
    public function list(string $period): array
    {
        $subjects = Subject::orderBy('code')->get();
        $byId = $subjects->keyBy('id');
        $byParent = $subjects->groupBy('parent_id');

        $balances = OpeningBalance::where('period', $period)->get()->keyBy('subject_id');

        $rows = [];
        foreach ($subjects as $s) {
            $b = $balances->get($s->id);
            $rows[] = [
                'subject_id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'category' => $s->category,
                'category_label' => Subject::categoryLabel($s->category),
                'direction' => $s->balance_direction,
                'is_leaf' => (bool) $s->is_leaf,
                'level' => $s->level,
                'opening_debit' => $b ? (float) $b->opening_debit : 0,
                'opening_credit' => $b ? (float) $b->opening_credit : 0,
                'year_debit' => $b ? (float) $b->year_debit : 0,
                'year_credit' => $b ? (float) $b->year_credit : 0,
            ];
        }

        // 当前期初借/贷 = 年初 + 本期累计（只读自动算）
        foreach ($rows as &$r) {
            $r['current_debit'] = round($r['opening_debit'] + $r['year_debit'], 2);
            $r['current_credit'] = round($r['opening_credit'] + $r['year_credit'], 2);
        }
        unset($r);

        return [
            'period' => $period,
            'period_closed' => $this->isPeriodClosed($period),
            'rows' => $rows,
            'total' => $this->totals($rows),
        ];
    }

    /** 批量保存（只存末级科目，非末级自动汇总） */
    public function save(string $period, array $items): void
    {
        DB::transaction(function () use ($period, $items) {
            if ($this->isPeriodClosed($period)) {
                throw new \DomainException('期间 '.$period.' 已月结，禁止修改期初');
            }
            foreach ($items as $item) {
                $subjectId = (int) ($item['subject_id'] ?? 0);
                $subject = Subject::find($subjectId);
                if (! $subject) {
                    continue;
                }
                // 只有末级科目可录入
                if (! $subject->is_leaf) {
                    continue;
                }
                $openingDebit = (float) ($item['opening_debit'] ?? 0);
                $openingCredit = (float) ($item['opening_credit'] ?? 0);
                $yearDebit = (float) ($item['year_debit'] ?? 0);
                $yearCredit = (float) ($item['year_credit'] ?? 0);

                OpeningBalance::updateOrCreate(
                    ['subject_id' => $subjectId, 'period' => $period],
                    [
                        'opening_debit' => round($openingDebit, 2),
                        'opening_credit' => round($openingCredit, 2),
                        'year_debit' => round($yearDebit, 2),
                        'year_credit' => round($yearCredit, 2),
                    ]
                );
            }
        });
    }

    /** 试算平衡检查：所有科目借贷合计相等 */
    public function balanceCheck(string $period): array
    {
        $data = $this->list($period);

        return $data['total'];
    }

    private function isPeriodClosed(string $period): bool
    {
        $p = Period::where('year', (int) substr($period, 0, 4))
            ->where('month', (int) substr($period, 5, 2))
            ->first();

        return $p && $p->status === Period::STATUS_CLOSED;
    }

    private function totals(array $rows): array
    {
        $debit = 0;
        $credit = 0;
        foreach ($rows as $r) {
            $debit += $r['current_debit'];
            $credit += $r['current_credit'];
        }
        $debit = round($debit, 2);
        $credit = round($credit, 2);
        $balanced = abs($debit - $credit) < 0.005;
        $diff = round($debit - $credit, 2);

        return [
            'total_debit' => $debit,
            'total_credit' => $credit,
            'balanced' => $balanced,
            'diff' => $diff,
            'message' => $balanced ? '试算平衡' : '借贷不平衡，差额 '.number_format(abs($diff), 2),
        ];
    }
}
