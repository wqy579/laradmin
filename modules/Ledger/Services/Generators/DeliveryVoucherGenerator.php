<?php

namespace Modules\Ledger\Services\Generators;

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Voucher;

/**
 * 销售出库单 → 凭证（QA-05）。
 *
 * 一张出库单产出两张凭证（各自借贷平衡，符合「收入与成本分别确认」的惯例）：
 *  1) 收入凭证：借 1122 应收账款(挂客户) / 贷 6001 主营业务收入，金额 = total_amount；
 *  2) 成本凭证：借 5401 主营业务成本 / 贷 1405 库存商品，金额 = Σ(明细数量 × 商品成本价)。
 *     成本全为 0（商品未录成本价）时**跳过成本凭证**——否则会长出一张 0 金额的无效凭证。
 *
 * 已发货(1) 与 已完成(2) 都视为业务成立；已取消(3) 与待发货(0) 不出凭证。
 *
 * 【口径简化，需业务确认】deliveries / delivery_items 里**没有任何成本列**
 * （见 modules/Business/database/migrations/2026_09_07_000004_create_deliveries_table.php），
 * 出库成本只能按 `delivery_items.quantity × products.cost_price`（商品当前综合成本价）估算。
 * 这意味着：
 *  - 商品成本价在出库后被修改，历史成本凭证不会跟着重算（凭证一旦生成即为快照）；
 *  - 不是移动加权/先进先出的真实结转成本，与库存台账的出库成本可能有分位差。
 * 若要精确成本，需给出库明细加成本快照列并在出库时落库，属另一件事。
 */
class DeliveryVoucherGenerator extends AbstractBillVoucherGenerator
{
    protected string $billTable = 'deliveries';

    protected string $billNoColumn = 'delivery_no';

    /** deliveries.status 是 int：0 待发货 / 1 已发货 / 2 已完成 / 3 已取消 */
    protected array $auditedStatusValues = [1, 2];

    protected function billType(): string
    {
        return Voucher::SOURCE_DELIVERY;
    }

    /** 金额 0 的出库单不出收入凭证，也就整单不生成 */
    protected function billEligible(object $bill): bool
    {
        return (float) $bill->total_amount > 0.005;
    }

    protected function buildPayloads(object $bill, array $context): array
    {
        $billDate = $this->billDate($bill->delivery_date ?? null, '出库日期');
        $billNo = (string) ($bill->delivery_no ?? $bill->id);
        $summary = '销售出库-'.$billNo;

        $payloads = [];

        $amount = round((float) $bill->total_amount, 2);
        if ($amount > 0.005) {
            $customerId = $bill->customer_id ? (int) $bill->customer_id : null;
            $payloads[] = [
                'voucher_date' => $billDate,
                'remark' => '销售出库单自动生成：'.$billNo,
                'entries' => [
                    $this->entry('1122', $summary, $amount, 'debit', 'customer', $customerId, $this->partyName('customers', $customerId)),
                    $this->entry('6001', $summary, $amount, 'credit'),
                ],
            ];
        }

        $cost = $this->estimatedCost((int) $bill->id);
        if ($cost > 0.005) {
            $payloads[] = [
                'voucher_date' => $billDate,
                'remark' => '销售出库成本自动生成：'.$billNo,
                'entries' => [
                    $this->entry('5401', '结转出库成本-'.$billNo, $cost, 'debit'),
                    $this->entry('1405', '结转出库成本-'.$billNo, $cost, 'credit'),
                ],
            ];
        }

        if (empty($payloads)) {
            throw new \DomainException('出库单 '.$billNo.' 金额与成本均为 0，无需生成凭证');
        }

        // 期间是否已月结不在此判断，统一由 VoucherService::ensurePeriodOpen 抛 DomainException
        return $payloads;
    }

    /**
     * 估算出库成本：Σ(quantity × products.cost_price)。
     *
     * 见类注释的口径简化说明。
     */
    private function estimatedCost(int $deliveryId): float
    {
        $items = DB::table('delivery_items as di')
            ->leftJoin('products as p', 'p.id', '=', 'di.product_id')
            ->where('di.delivery_id', $deliveryId)
            ->get(['di.quantity', 'p.cost_price']);

        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) $item->quantity * (float) ($item->cost_price ?? 0);
        }

        return round($total, 2);
    }
}
