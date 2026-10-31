<?php

namespace Modules\Ledger\Services\Generators;

use Modules\Ledger\Models\Voucher;

/**
 * 收款单 → 凭证（QA-05）。
 *
 * 借 1001 库存现金 / 1002 银行存款（按 payment_method 是否含「现金」判定），
 * 贷 1122 应收账款（挂客户辅助核算）。
 *
 * receives.status：0 草稿 / 1 已审核 / 2 已红冲——只有 1 出凭证。
 * 「其他收款」（receive_type=2，如库存溢余自动生成的收款单）没有客户，
 * 同样按现金/银行收款入账，只是贷方应收账款不挂客户。
 */
class ReceiveVoucherGenerator extends AbstractBillVoucherGenerator
{
    protected string $billTable = 'receives';

    protected string $billNoColumn = 'receive_no';

    protected array $auditedStatusValues = [1];

    protected function billType(): string
    {
        return Voucher::SOURCE_RECEIVE;
    }

    /** 金额为 0 的收款单不出凭证 */
    protected function billEligible(object $bill): bool
    {
        return (float) $bill->amount > 0.005;
    }

    protected function buildPayloads(object $bill, array $context): array
    {
        $billDate = $this->billDate($bill->receive_date ?? null, '收款日期');
        $billNo = (string) ($bill->receive_no ?? $bill->id);
        $summary = '收款-'.$billNo;
        $amount = round((float) $bill->amount, 2);
        $customerId = $bill->customer_id ? (int) $bill->customer_id : null;

        return [[
            'voucher_date' => $billDate,
            'remark' => '收款单自动生成：'.$billNo,
            'entries' => [
                $this->entry($this->cashSubjectCode($bill->payment_method ?? null), $summary, $amount, 'debit'),
                $this->entry('1122', $summary, $amount, 'credit', 'customer', $customerId, $this->partyName('customers', $customerId)),
            ],
        ]];
    }
}
