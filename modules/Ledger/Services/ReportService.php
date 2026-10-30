<?php

namespace Modules\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Models\VoucherEntry;

/**
 * 账簿与报表：科目余额表、资产负债表、利润表、日记账/明细账。
 *
 * 科目余额：期初 + 本期发生 - 对应方向 = 期末。
 * 资产负债表：左右两栏，资产总计 = 负债和所有者权益总计，必须平衡。
 * 利润表：本月数 + 本年累计数两列，营业收入 - 成本费用 = 净利润。
 */
class ReportService
{
    /** 科目余额表 */
    public function subjectBalance(string $startPeriod, string $endPeriod, int $maxLevel = 99, bool $leafOnly = false, bool $includeZero = false): array
    {
        $subjects = Subject::orderBy('code')->get();
        $byId = $subjects->keyBy('id');

        // 期初（startPeriod 之前的累计）
        $openingMap = $this->openingBalanceBefore($startPeriod);
        // 本期发生（startPeriod ~ endPeriod）
        $occurrenceMap = $this->occurrenceBetween($startPeriod, $endPeriod);

        $rows = [];
        foreach ($subjects as $s) {
            $opening = $openingMap[$s->id] ?? ['debit' => 0, 'credit' => 0];
            $occur = $occurrenceMap[$s->id] ?? ['debit' => 0, 'credit' => 0];

            $openingDebit = (float) $opening['debit'];
            $openingCredit = (float) $opening['credit'];
            $occurDebit = (float) $occur['debit'];
            $occurCredit = (float) $occur['credit'];

            $endDebit = round($openingDebit + $occurDebit, 2);
            $endCredit = round($openingCredit + $occurCredit, 2);

            if ($leafOnly && ! $s->is_leaf) {
                continue;
            }
            if ($s->level > $maxLevel) {
                continue;
            }
            if (! $includeZero && abs($openingDebit) < 0.005 && abs($openingCredit) < 0.005 && abs($occurDebit) < 0.005 && abs($occurCredit) < 0.005) {
                continue;
            }

            $rows[] = [
                'subject_id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'category' => $s->category,
                'direction' => $s->balance_direction,
                'is_leaf' => (bool) $s->is_leaf,
                'level' => $s->level,
                'opening_debit' => $openingDebit,
                'opening_credit' => $openingCredit,
                'occur_debit' => $occurDebit,
                'occur_credit' => $occurCredit,
                'end_debit' => $endDebit,
                'end_credit' => $endCredit,
            ];
        }

        // 合计
        $totalDebit = round(array_sum(array_column($rows, 'opening_debit')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'opening_credit')), 2);
        $totalOccurDebit = round(array_sum(array_column($rows, 'occur_debit')), 2);
        $totalOccurCredit = round(array_sum(array_column($rows, 'occur_credit')), 2);
        $totalEndDebit = round(array_sum(array_column($rows, 'end_debit')), 2);
        $totalEndCredit = round(array_sum(array_column($rows, 'end_credit')), 2);

        return [
            'start_period' => $startPeriod,
            'end_period' => $endPeriod,
            'rows' => $rows,
            'total' => [
                'opening_debit' => $totalDebit,
                'opening_credit' => $totalCredit,
                'occur_debit' => $totalOccurDebit,
                'occur_credit' => $totalOccurCredit,
                'end_debit' => $totalEndDebit,
                'end_credit' => $totalEndCredit,
                'balanced' => abs($totalEndDebit - $totalEndCredit) < 0.005,
            ],
        ];
    }

    /** 资产负债表（小企业会计准则标准项目） */
    public function balanceSheet(string $period): array
    {
        $periodDate = $this->periodEndDate($period);
        $yearStart = substr($period, 0, 4).'-01';
        $openingMap = $this->openingBalanceBefore($yearStart);
        $occMap = $this->occurrenceBetween($yearStart, $period);

        // 期末余额按科目编码聚合
        $subjectBalances = $this->aggregateSubjectBalances($openingMap, $occMap);

        // 年初余额
        $yearOpeningMap = $this->openingBalanceBefore($yearStart);
        $yearOccMap = []; // 年初就是期初
        $yearBalances = $this->aggregateSubjectBalances($yearOpeningMap, $yearOccMap);

        // 科目余额方向一次性批量取回（避免按 code 逐条查库）
        $subjectDirections = Subject::query()->pluck('balance_direction', 'code');
        $directionOf = function (string $code) use ($subjectDirections): string {
            return $subjectDirections[$code] ?? 'debit';
        };

        $balanceByCode = function (string $code) use ($subjectBalances, $directionOf): float {
            $amt = $subjectBalances[$code] ?? ['debit' => 0, 'credit' => 0];
            $direction = $directionOf($code);

            return $direction === 'debit'
                ? round((float) $amt['debit'] - (float) $amt['credit'], 2)
                : round((float) $amt['credit'] - (float) $amt['debit'], 2);
        };

        $yearByCode = function (string $code) use ($yearBalances, $directionOf): float {
            $amt = $yearBalances[$code] ?? ['debit' => 0, 'credit' => 0];
            $direction = $directionOf($code);

            return $direction === 'debit'
                ? round((float) $amt['debit'] - (float) $amt['credit'], 2)
                : round((float) $amt['credit'] - (float) $amt['debit'], 2);
        };

        // 资产合计 = 负债 + 所有者权益合计
        $monetary = $balanceByCode('1001') + $balanceByCode('1002') + $balanceByCode('1012');
        $accountsReceivable = $balanceByCode('1122');
        $prepaid = $balanceByCode('1123');
        $otherReceivable = $balanceByCode('1221');
        $inventory = $balanceByCode('1401') + $balanceByCode('1403') + $balanceByCode('1405') + $balanceByCode('1411') - $balanceByCode('1471');
        $fixedAssetOriginal = $balanceByCode('1601');
        $accumulatedDepreciation = $balanceByCode('1602');
        $fixedAssetNet = $fixedAssetOriginal - $accumulatedDepreciation;
        $constructionInProgress = $balanceByCode('1604');
        $fixedAssetCleanup = $balanceByCode('1606');
        $totalAssets = round($monetary + $accountsReceivable + $prepaid + $otherReceivable + $inventory + $fixedAssetNet + $constructionInProgress + $fixedAssetCleanup, 2);

        $shortTermLoan = $balanceByCode('2001');
        $accountsPayable = $balanceByCode('2202');
        $advanceReceipts = $balanceByCode('2203');
        $payrollPayable = $balanceByCode('2211');
        $taxPayable = $balanceByCode('2221') + $balanceByCode('222101') + $balanceByCode('222102');
        $otherPayable = $balanceByCode('2241');
        $longTermLoan = $balanceByCode('2501');
        $totalLiabilities = round($shortTermLoan + $accountsPayable + $advanceReceipts + $payrollPayable + $taxPayable + $otherPayable + $longTermLoan, 2);

        $paidInCapital = $balanceByCode('4001');
        $capitalReserve = $balanceByCode('4002');
        $undistributedProfit = $balanceByCode('4103') + $balanceByCode('4104');
        $totalEquity = round($paidInCapital + $capitalReserve + $undistributedProfit, 2);
        $totalLiabEquity = round($totalLiabilities + $totalEquity, 2);

        return [
            'period' => $period,
            'report_date' => $periodDate,
            'balanced' => abs($totalAssets - $totalLiabEquity) < 0.005,
            'diff' => round($totalAssets - $totalLiabEquity, 2),
            'left' => [ // 资产
                ['name' => '货币资金', 'code' => 'monetary', 'end' => $monetary, 'year' => ($yearByCode('1001') + $yearByCode('1002') + $yearByCode('1012'))],
                ['name' => '应收账款', 'code' => 'accounts_receivable', 'end' => $accountsReceivable, 'year' => $yearByCode('1122')],
                ['name' => '预付账款', 'code' => 'prepaid', 'end' => $prepaid, 'year' => $yearByCode('1123')],
                ['name' => '其他应收款', 'code' => 'other_receivable', 'end' => $otherReceivable, 'year' => $yearByCode('1221')],
                ['name' => '存货', 'code' => 'inventory', 'end' => $inventory, 'year' => ($yearByCode('1401') + $yearByCode('1403') + $yearByCode('1405') + $yearByCode('1411') - $yearByCode('1471'))],
                ['name' => '固定资产原值', 'code' => 'fixed_asset_original', 'end' => $fixedAssetOriginal, 'year' => $yearByCode('1601')],
                ['name' => '减：累计折旧', 'code' => 'accumulated_depreciation', 'end' => -$accumulatedDepreciation, 'year' => -$yearByCode('1602')],
                ['name' => '固定资产账面价值', 'code' => 'fixed_asset_net', 'end' => $fixedAssetNet, 'year' => ($yearByCode('1601') - $yearByCode('1602'))],
                ['name' => '在建工程', 'code' => 'construction_in_progress', 'end' => $constructionInProgress, 'year' => $yearByCode('1604')],
                ['name' => '固定资产清理', 'code' => 'fixed_asset_cleanup', 'end' => $fixedAssetCleanup, 'year' => $yearByCode('1606')],
                ['name' => '资产总计', 'code' => 'total_assets', 'end' => $totalAssets, 'year' => round($monetary + $accountsReceivable + $prepaid + $otherReceivable + $inventory + $fixedAssetNet + $constructionInProgress + $fixedAssetCleanup, 2), 'is_total' => true],
            ],
            'right' => [ // 负债与所有者权益
                ['name' => '短期借款', 'code' => 'short_term_loan', 'end' => $shortTermLoan, 'year' => $yearByCode('2001')],
                ['name' => '应付账款', 'code' => 'accounts_payable', 'end' => $accountsPayable, 'year' => $yearByCode('2202')],
                ['name' => '预收账款', 'code' => 'advance_receipts', 'end' => $advanceReceipts, 'year' => $yearByCode('2203')],
                ['name' => '应付职工薪酬', 'code' => 'payroll_payable', 'end' => $payrollPayable, 'year' => $yearByCode('2211')],
                ['name' => '应交税费', 'code' => 'tax_payable', 'end' => $taxPayable, 'year' => ($yearByCode('2221') + $yearByCode('222101') + $yearByCode('222102'))],
                ['name' => '其他应付款', 'code' => 'other_payable', 'end' => $otherPayable, 'year' => $yearByCode('2241')],
                ['name' => '长期借款', 'code' => 'long_term_loan', 'end' => $longTermLoan, 'year' => $yearByCode('2501')],
                ['name' => '负债合计', 'code' => 'total_liabilities', 'end' => $totalLiabilities, 'year' => round($shortTermLoan + $accountsPayable + $advanceReceipts + $payrollPayable + $taxPayable + $otherPayable + $longTermLoan, 2), 'is_total' => true],
                ['name' => '实收资本', 'code' => 'paid_in_capital', 'end' => $paidInCapital, 'year' => $yearByCode('4001')],
                ['name' => '资本公积', 'code' => 'capital_reserve', 'end' => $capitalReserve, 'year' => $yearByCode('4002')],
                ['name' => '未分配利润', 'code' => 'undistributed_profit', 'end' => $undistributedProfit, 'year' => ($yearByCode('4103') + $yearByCode('4104'))],
                ['name' => '所有者权益合计', 'code' => 'total_equity', 'end' => $totalEquity, 'year' => round($paidInCapital + $capitalReserve + $undistributedProfit, 2), 'is_total' => true],
                ['name' => '负债和所有者权益总计', 'code' => 'total_liab_equity', 'end' => $totalLiabEquity, 'year' => round($totalLiabilities + $totalEquity, 2), 'is_total' => true],
            ],
        ];
    }

    /** 利润表（本月数 + 本年累计数） */
    public function incomeStatement(string $period): array
    {
        $yearStart = substr($period, 0, 4).'-01';
        $monthOccMap = $this->occurrenceBetween($period, $period);
        $yearOccMap = $this->occurrenceBetween($yearStart, $period);

        // 科目 id/余额方向一次性批量取回（避免按 code 逐条查库）
        $subjectMeta = Subject::query()->get(['code', 'id', 'balance_direction'])
            ->keyBy('code')->map(fn ($s) => ['id' => $s->id, 'direction' => $s->balance_direction])
            ->all();

        $monthByCode = function (string $code) use ($monthOccMap, $subjectMeta): float {
            return $this->netByCode($code, $monthOccMap, $subjectMeta);
        };
        $yearByCode = function (string $code) use ($yearOccMap, $subjectMeta): float {
            return $this->netByCode($code, $yearOccMap, $subjectMeta);
        };

        $operatingRevenue = $monthByCode('6001') + $monthByCode('6051');
        $operatingCost = $monthByCode('5401') + $monthByCode('5402');
        $taxSurcharge = $monthByCode('5403');
        $sellingExpense = $monthByCode('6601');
        $adminExpense = $monthByCode('6602');
        $financeExpense = $monthByCode('6603');
        $investmentIncome = $monthByCode('6111');
        $operatingProfit = round($operatingRevenue - $operatingCost - $taxSurcharge - $sellingExpense - $adminExpense - $financeExpense + $investmentIncome, 2);
        $nonOperatingIncome = $monthByCode('6301');
        $nonOperatingExpense = $monthByCode('6711');
        $totalProfit = round($operatingProfit + $nonOperatingIncome - $nonOperatingExpense, 2);
        $incomeTax = $monthByCode('6801');
        $netProfit = round($totalProfit - $incomeTax, 2);

        $yearRevenue = $yearByCode('6001') + $yearByCode('6051');
        $yearCost = $yearByCode('5401') + $yearByCode('5402');
        $yearTax = $yearByCode('5403');
        $yearSelling = $yearByCode('6601');
        $yearAdmin = $yearByCode('6602');
        $yearFinance = $yearByCode('6603');
        $yearInvestment = $yearByCode('6111');
        $yearOpProfit = round($yearRevenue - $yearCost - $yearTax - $yearSelling - $yearAdmin - $yearFinance + $yearInvestment, 2);
        $yearNonOpIncome = $yearByCode('6301');
        $yearNonOpExpense = $yearByCode('6711');
        $yearTotalProfit = round($yearOpProfit + $yearNonOpIncome - $yearNonOpExpense, 2);
        $yearIncomeTax = $yearByCode('6801');
        $yearNetProfit = round($yearTotalProfit - $yearIncomeTax, 2);

        $row = function (string $name, string $code, float $month, float $year, bool $bold = false, bool $isTotal = false) {
            return ['name' => $name, 'code' => $code, 'month' => $month, 'year' => $year, 'bold' => $bold, 'is_total' => $isTotal];
        };

        return [
            'period' => $period,
            'rows' => [
                $row('一、营业收入', 'operating_revenue', $operatingRevenue, $yearRevenue, true),
                $row('   减：营业成本', 'operating_cost', $operatingCost, $yearCost),
                $row('       税金及附加', 'tax_surcharge', $taxSurcharge, $yearTax),
                $row('       销售费用', 'selling_expense', $sellingExpense, $yearSelling),
                $row('       管理费用', 'admin_expense', $adminExpense, $yearAdmin),
                $row('       财务费用', 'finance_expense', $financeExpense, $yearFinance),
                $row('       加：投资收益', 'investment_income', $investmentIncome, $yearInvestment),
                $row('二、营业利润', 'operating_profit', $operatingProfit, $yearOpProfit, true, true),
                $row('   加：营业外收入', 'non_operating_income', $nonOperatingIncome, $yearNonOpIncome),
                $row('   减：营业外支出', 'non_operating_expense', $nonOperatingExpense, $yearNonOpExpense),
                $row('三、利润总额', 'total_profit', $totalProfit, $yearTotalProfit, true, true),
                $row('   减：所得税费用', 'income_tax', $incomeTax, $yearIncomeTax),
                $row('四、净利润', 'net_profit', $netProfit, $yearNetProfit, true, true),
            ],
            'net_profit' => $netProfit,
            'year_net_profit' => $yearNetProfit,
        ];
    }

    /** 日记账（库存现金/银行存款默认走日记账样式） */
    public function journal(int $subjectId, string $period): array
    {
        return app(VoucherService::class)->subjectLedger($subjectId, $period);
    }

    // ===== 内部方法 =====

    /** 期间结束日期 */
    private function periodEndDate(string $period): string
    {
        [$y, $m] = explode('-', $period);

        return date('Y-m-d', strtotime("last day of $y-$m"));
    }

    /** startPeriod 之前的累计期初（含 year_debit/credit） */
    private function openingBalanceBefore(string $startPeriod): array
    {
        $rows = DB::table('ledger_opening_balances')
            ->where('period', '<', $startPeriod)
            ->select('subject_id', DB::raw('SUM(opening_debit) as od'), DB::raw('SUM(opening_credit) as oc'), DB::raw('SUM(year_debit) as yd'), DB::raw('SUM(year_credit) as yc'))
            ->groupBy('subject_id')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->subject_id] = [
                'debit' => (float) $r->od + (float) $r->yd,
                'credit' => (float) $r->oc + (float) $r->yc,
            ];
        }

        return $map;
    }

    /** startPeriod~endPeriod 之间已审核凭证的发生额 */
    private function occurrenceBetween(string $startPeriod, string $endPeriod): array
    {
        $rows = VoucherEntry::select('subject_id', DB::raw('SUM(debit_amount) as d'), DB::raw('SUM(credit_amount) as c'))
            ->whereHas('voucher', function ($q) use ($startPeriod, $endPeriod) {
                $q->whereBetween('period', [$startPeriod, $endPeriod])
                    ->where('status', Voucher::STATUS_AUDITED);
            })
            ->groupBy('subject_id')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->subject_id] = ['debit' => (float) $r->d, 'credit' => (float) $r->c];
        }

        return $map;
    }

    /** 把期初 + 发生额聚合成 subject_code => [debit, credit] */
    private function aggregateSubjectBalances(array $openingMap, array $occMap): array
    {
        $subjects = Subject::all()->keyBy('id');
        $byCode = [];
        foreach ($subjects as $s) {
            $opening = $openingMap[$s->id] ?? ['debit' => 0, 'credit' => 0];
            $occur = $occMap[$s->id] ?? ['debit' => 0, 'credit' => 0];
            $byCode[$s->code] = [
                'debit' => (float) $opening['debit'] + (float) $occur['debit'],
                'credit' => (float) $opening['credit'] + (float) $occur['credit'],
            ];
        }

        return $byCode;
    }

    /** 某科目在某发生额map里的净额（贷方科目取贷-借，借方科目取借-贷） */
    private function netByCode(string $code, array $occMap, array $subjectMeta): float
    {
        $meta = $subjectMeta[$code] ?? null;
        if (! $meta) {
            return 0;
        }
        $amt = $occMap[$meta['id']] ?? ['debit' => 0, 'credit' => 0];
        // 收入类（贷方）净额 = 贷 - 借；成本费用类（借方）净额 = 借 - 贷
        if ($meta['direction'] === 'credit') {
            return round((float) $amt['credit'] - (float) $amt['debit'], 2);
        }

        return round((float) $amt['debit'] - (float) $amt['credit'], 2);
    }
}
