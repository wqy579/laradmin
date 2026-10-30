<?php

namespace Modules\Ledger\Services\Generators;

use Modules\Ledger\Models\Voucher;

/**
 * 付款单 → 凭证（QA-05）。
 *
 * 借 2202 应付账款（挂供应商辅助核算），
 * 贷 1001 库存现金 / 1002 银行存款（按 payment_method 是否含「现金」判定）。
 *
 * pays.status：0 草稿 / 1 已审核。
 */
class PayVoucherGenerator extends AbstractBillVoucherGenerator
{
    protected string $billTable = 'pays';

    protected string $billNoColumn = 'pay_no';

    protected array $auditedStatusValues = [1];

    protected function billType(): string
    {
        return Voucher::SOURCE_PAY;
    }

    protected function billEligible(object $bill): bool
    {
        return (float) $bill->amount > 0.005;
    }

    protected function buildPayloads(object $bill, array $context): array
    {
        $billDate = $this->billDate($bill->pay_date ?? null, '付款日期');
        $billNo = (string) ($bill->pay_no ?? $bill->id);
        $summary = '付款-'.$billNo;
        $amount = round((float) $bill->amount, 2);
        $supplierId = $bill->supplier_id ? (int) $bill->supplier_id : null;

        return [[
            'voucher_date' => $billDate,
            'remark' => '付款单自动生成：'.$billNo,
            'entries' => [
                $this->entry('2202', $summary, $amount, 'debit', 'supplier', $supplierId, $this->partyName('suppliers', $supplierId)),
                $this->entry($this->cashSubjectCode($bill->payment_method ?? null), $summary, $amount, 'credit'),
            ],
        ]];
    }
}
