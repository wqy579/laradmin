<?php

namespace Tests\Ledger\Feature\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 业务单据夹具（QA-05 凭证生成测试）。
 *
 * 全部用**裸表插入**，不 import Order/Stock/Business 的模型类：
 *  - Ledger 的依赖方向必须是「业务 → Ledger」，测试若引对方模型会在
 *    ArchitectureTest 里长出 Ledger->Order / Ledger->Stock 的反向边；
 *  - 夹具只关心生成器实际读到的列，裸表插入能显式暴露这些列的口径。
 *
 * 科目由 Ledger 迁移预置（2026_10_14_000008 会跑 LedgerSubjectSeeder），
 * 因此这里用 code 直接取末级科目 id。
 */
trait CreatesBusinessBills
{
    private function subjectIdByCode(string $code): int
    {
        $id = DB::table('ledger_subjects')->where('code', $code)->where('is_leaf', true)->value('id');
        $this->assertNotNull($id, '预置科目缺失：'.$code);

        return (int) $id;
    }

    /** 分录按科目编码取回（凭证 → 分录 → 科目），便于断言借贷方向 */
    private function entriesOf(int $voucherId): array
    {
        return DB::table('ledger_voucher_entries as e')
            ->join('ledger_subjects as s', 's.id', '=', 'e.subject_id')
            ->where('e.voucher_id', $voucherId)
            ->orderBy('e.line_no')
            ->get(['s.code', 'e.summary', 'e.debit_amount', 'e.credit_amount', 'e.aux_type', 'e.aux_id', 'e.aux_name'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** 某科目在该凭证上的借/贷金额 */
    private function amountOf(array $entries, string $code): array
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($entries as $e) {
            if ($e['code'] === $code) {
                $debit += (float) $e['debit_amount'];
                $credit += (float) $e['credit_amount'];
            }
        }

        return ['debit' => round($debit, 2), 'credit' => round($credit, 2)];
    }

    private function billCustomer(string $name = '测试客户'): int
    {
        return (int) DB::table('customers')->insertGetId([
            'code' => 'C'.strtoupper(Str::random(6)),
            'name' => $name,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function billSupplier(string $name = '测试供应商'): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'code' => 'S'.strtoupper(Str::random(6)),
            'name' => $name,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function billProduct(float $costPrice = 80.0, string $name = '测试商品'): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => $name,
            'code' => 'P'.strtoupper(Str::random(6)),
            'cost_price' => $costPrice,
            'price_small' => 100,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 销售出库单。$items = [[product_id, quantity], ...]
     *
     * @param  array<int, array{0:int,1:float}>  $items
     */
    private function billDelivery(int $customerId, float $totalAmount, array $items, int $status = 1): int
    {
        $id = (int) DB::table('deliveries')->insertGetId([
            'delivery_no' => 'CK'.strtoupper(Str::random(8)),
            'customer_id' => $customerId,
            'total_amount' => $totalAmount,
            'delivery_date' => date('Y-m-d'),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($items as [$productId, $quantity]) {
            DB::table('delivery_items')->insert([
                'delivery_id' => $id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => 0,
                'amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function billReceive(int $customerId, float $amount, string $paymentMethod = '现金', int $status = 1, ?string $remark = null): int
    {
        return (int) DB::table('receives')->insertGetId([
            'receive_no' => 'SK'.strtoupper(Str::random(8)),
            'receive_type' => 1,
            'customer_id' => $customerId,
            'amount' => $amount,
            'receive_date' => date('Y-m-d'),
            'payment_method' => $paymentMethod,
            'remark' => $remark,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function billPay(int $supplierId, float $amount, string $paymentMethod = '银行转账', int $status = 1): int
    {
        return (int) DB::table('pays')->insertGetId([
            'pay_no' => 'FK'.strtoupper(Str::random(8)),
            'pay_type' => 1,
            'supplier_id' => $supplierId,
            'amount' => $amount,
            'pay_date' => date('Y-m-d'),
            'payment_method' => $paymentMethod,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function billExpense(string $expenseType, float $amount, int $status = 1, ?string $remark = null): int
    {
        return (int) DB::table('expenses')->insertGetId([
            'expense_no' => 'FY'.strtoupper(Str::random(8)),
            'expense_type' => $expenseType,
            'amount' => $amount,
            'expense_date' => date('Y-m-d'),
            'remark' => $remark,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 库存调整单。$items = [[product_id, adjust_qty, unit_cost], ...]
     *
     * total_cost 与业务侧一致 = abs(adjust_qty) × unit_cost
     *
     * @param  array<int, array{0:int,1:float,2:float}>  $items
     */
    private function billStockAdjust(array $items, string $status = 'approved'): int
    {
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'WH'.strtoupper(Str::random(6)),
            'name' => '测试仓库_'.Str::random(4),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (int) DB::table('stock_adjusts')->insertGetId([
            'adjust_no' => 'TZ'.strtoupper(Str::random(8)),
            'warehouse_id' => $warehouseId,
            'adjust_date' => date('Y-m-d'),
            'adjust_type' => 'other',
            'status' => $status,
            'total_qty' => array_sum(array_map(fn ($i) => (float) $i[1], $items)),
            'total_amount' => 0,
            'reason' => '测试调整',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($items as [$productId, $adjustQty, $unitCost]) {
            DB::table('stock_adjust_items')->insert([
                'adjust_id' => $id,
                'product_id' => $productId,
                'product_name' => '测试商品',
                'adjust_qty' => $adjustQty,
                'unit_cost' => $unitCost,
                'total_cost' => round(abs((float) $adjustQty) * (float) $unitCost, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** 某类单据已生成的凭证行（按 seq 升序） */
    private function sourceVouchers(string $type, int $billId): array
    {
        return DB::table('ledger_vouchers')
            ->where('source_type', $type)
            ->where('source_id', $billId)
            ->orderBy('source_seq')
            ->get(['id', 'voucher_no', 'source_seq', 'total_debit', 'total_credit', 'status', 'period', 'maker_name'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
