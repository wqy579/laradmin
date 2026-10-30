<?php

namespace Modules\Ledger\Services\Generators;

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Voucher;

/**
 * 库存调整单 → 凭证（QA-05）。
 *
 * 盘亏（明细 adjust_qty < 0）：借 6711 营业外支出 / 贷 1405 库存商品；
 * 盘盈（明细 adjust_qty > 0）：借 1405 库存商品 / 贷 6711 营业外支出。
 * 金额取 stock_adjust_items.total_cost（= |adjust_qty| × unit_cost，建单时已落库），
 * 只累加 > 0 的明细。
 *
 * 同一张调整单可能既有盘亏又有盘盈：拆成两张各自平衡的凭证
 * （seq=1 盘亏、seq=2 盘盈），任一为空即跳过——净成一行的写法会让借贷方向不定、
 * 摘要也难对齐实际业务。
 *
 * stock_adjusts.status 是字符串：draft / pending / approved / cancelled，只有 approved 出凭证。
 *
 * 【与派生单的关系】StockAdjustController::applyApproval 会顺手往 expenses/receives
 * 里塞一张「库存损耗/库存溢余」自动审核单。那两张派生单已被 Expense/Receive 生成器
 * 按 remark 前缀排除，凭证只在此处按存货成本口径出一次，避免同一笔损耗记两遍。
 */
class StockAdjustVoucherGenerator extends AbstractBillVoucherGenerator
{
    protected string $billTable = 'stock_adjusts';

    protected string $billNoColumn = 'adjust_no';

    protected array $auditedStatusValues = ['approved'];

    protected function billType(): string
    {
        return Voucher::SOURCE_STOCK_ADJUST;
    }

    /** 没有一条成本 > 0 的明细就不算「待生成」，否则结账检查③会一直报漏 */
    protected function billEligible(object $bill): bool
    {
        return $this->itemAmounts((int) $bill->id)['total'] > 0.005;
    }

    protected function buildPayloads(object $bill, array $context): array
    {
        $billDate = $this->billDate($bill->adjust_date ?? null, '调整日期');
        $billNo = (string) ($bill->adjust_no ?? $bill->id);
        ['loss' => $loss, 'gain' => $gain] = $this->itemAmounts((int) $bill->id);

        $payloads = [];

        if ($loss > 0.005) {
            $summary = '库存盘亏-'.$billNo;
            $payloads[] = [
                'voucher_date' => $billDate,
                'remark' => '库存调整盘亏自动生成：'.$billNo,
                'entries' => [
                    $this->entry('6711', $summary, $loss, 'debit'),
                    $this->entry('1405', $summary, $loss, 'credit'),
                ],
            ];
        }

        if ($gain > 0.005) {
            $summary = '库存盘盈-'.$billNo;
            $payloads[] = [
                'voucher_date' => $billDate,
                'remark' => '库存调整盘盈自动生成：'.$billNo,
                'entries' => [
                    $this->entry('1405', $summary, $gain, 'debit'),
                    $this->entry('6711', $summary, $gain, 'credit'),
                ],
            ];
        }

        if (empty($payloads)) {
            throw new \DomainException('调整单 '.$billNo.' 明细成本均为 0，无需生成凭证');
        }

        return $payloads;
    }

    /**
     * 按明细符号汇总成本。
     *
     * @return array{loss: float, gain: float, total: float}
     */
    private function itemAmounts(int $adjustId): array
    {
        $rows = DB::table('stock_adjust_items')
            ->where('adjust_id', $adjustId)
            ->get(['adjust_qty', 'total_cost']);

        $loss = 0.0;
        $gain = 0.0;
        foreach ($rows as $row) {
            $cost = round((float) $row->total_cost, 2);
            if ($cost <= 0.005) {
                continue;
            }
            if ((float) $row->adjust_qty < 0) {
                $loss += $cost;
            } else {
                $gain += $cost;
            }
        }

        return ['loss' => round($loss, 2), 'gain' => round($gain, 2), 'total' => round($loss + $gain, 2)];
    }
}
