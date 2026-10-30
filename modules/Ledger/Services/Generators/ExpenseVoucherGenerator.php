<?php

namespace Modules\Ledger\Services\Generators;

use Modules\Ledger\Models\Voucher;

/**
 * 费用单 → 凭证（QA-05）。
 *
 * 借方按 expense_type 映射费用科目：
 *   含「销售/运输/配送/仓储/业务招待」→ 6601 销售费用
 *   含「利息/汇兑/手续费/财务」      → 6603 财务费用（**必须先于「费」兜底判定**）
 *   其它（含管理类、未匹配）        → 6602 管理费用 兜底
 * 贷方支付方式含「现金」→ 1001 库存现金，否则 1002 银行存款。
 *
 * 【口径缺口，需业务确认】expenses 表**没有 payment_method 列**
 * （见 modules/Business/database/migrations/2026_09_08_000001_create_financial_tables.php），
 * 所以贷方无法自动判定，默认按现金 1001 出账；调用方可通过
 * `$context['payment_method']` 显式指定（如「银行转账」→ 1002）。
 * expenses 加支付方式列属另一件事，本任务不改业务表。
 *
 * expenses.status：0 草稿 / 1 已审核。
 */
class ExpenseVoucherGenerator extends AbstractBillVoucherGenerator
{
    protected string $billTable = 'expenses';

    protected string $billNoColumn = 'expense_no';

    protected array $auditedStatusValues = [1];

    /**
     * 库存调整/盘点审核时自动塞进 expenses 的派生单：
     * 这两类业务的凭证由 stock_adjust 生成器（或盘点自身的现金流水口径）出账，
     * 若这里再按「费用→现金流出」出一张，同一笔损耗就记了两遍（利润表虚减、货币资金虚减）。
     */
    protected array $excludedRemarkPrefixes = [
        '库存调整损耗-',
        '库存盘亏-',
        '库存盘盈-',
    ];

    /** expense_type 关键词 → 费用科目（顺序即优先级，财务类须先兜底前判定） */
    private const TYPE_MAP = [
        '6603' => ['利息', '汇兑', '手续费', '财务'],
        '6601' => ['销售', '运输', '配送', '仓储', '业务招待'],
    ];

    protected function billType(): string
    {
        return Voucher::SOURCE_EXPENSE;
    }

    protected function billEligible(object $bill): bool
    {
        return (float) $bill->amount > 0.005;
    }

    protected function buildPayloads(object $bill, array $context): array
    {
        $billDate = $this->billDate($bill->expense_date ?? null, '费用日期');
        $billNo = (string) ($bill->expense_no ?? $bill->id);
        $amount = round((float) $bill->amount, 2);
        $expenseType = trim((string) ($bill->expense_type ?? ''));
        $summary = ($expenseType !== '' ? $expenseType : '费用').'-'.$billNo;

        // expenses 无支付方式列：默认按现金出账（费用报销通行口径），允许调用方用 context 覆盖（见类注释）
        $paymentMethod = $context['payment_method'] ?? '现金';

        return [[
            'voucher_date' => $billDate,
            'remark' => '费用单自动生成：'.$billNo,
            'entries' => [
                $this->entry($this->expenseSubjectCode($expenseType), $summary, $amount, 'debit'),
                $this->entry($this->cashSubjectCode($paymentMethod), $summary, $amount, 'credit'),
            ],
        ]];
    }

    /** 费用类型 → 费用科目编码，未匹配一律落 6602 管理费用 */
    private function expenseSubjectCode(string $expenseType): string
    {
        foreach (self::TYPE_MAP as $code => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($expenseType, $keyword)) {
                    return $code;
                }
            }
        }

        return '6602';
    }
}
