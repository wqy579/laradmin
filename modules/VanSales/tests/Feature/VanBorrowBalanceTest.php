<?php

namespace Tests\VanSales\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

/**
 * 车销借还余额清零保护测试（P3）
 *
 * 缺陷：VanReturnBorrowOrderController::decreaseBorrowBalance（VanSales 侧实现）
 * 无负数下限守卫，超还（return_qty > balance.qty）时 qty 直接被写为负数；
 * BorrowReturn 模块两处实现均为 $newQty <= 0 时删行。
 * 修复：VanSales 实现补同等守卫，三处行为一致。
 *
 * 流程事实（modules/VanSales/routes/admin.php + 两个 Controller）：
 *   借货：POST /admin/business/van-borrow-order（store 草稿，items.*.borrow_qty）
 *        → POST /{id}/approve（stockOut 车上仓 + increaseBorrowBalance）
 *   还货：POST /admin/business/van-return-borrow-order（store 草稿，items.*.return_qty）
 *        → POST /{id}/approve（stockIn 车上仓 + decreaseBorrowBalance）
 */
class VanBorrowBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_over_return_deletes_balance_row_instead_of_writing_negative_qty(): void
    {
        [$borrowOrderId, $customerId, $productId] = $this->makeApprovedBorrow(1);

        // 借货余额应为 1
        $this->assertSame(1, (int) $this->balanceQty($customerId, $productId));

        // 超额归还：return_qty=10 > balance.qty=1
        $returnId = $this->storeReturnBorrow($borrowOrderId, $customerId, $productId, 10);
        $this->postJson("/admin/business/van-return-borrow-order/{$returnId}/approve")->assertStatus(200);

        // 修复语义：超还清零 → 删行（与 BorrowReturn 两处实现一致），qty 不得为负
        $balance = DB::table('van_customer_borrow_balances')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)->first();

        $this->assertNull($balance, '超还清零后余额行应被删除，而不是保留或写负数');
    }

    public function test_partial_return_keeps_remaining_balance(): void
    {
        [$borrowOrderId, $customerId, $productId] = $this->makeApprovedBorrow(5);

        $this->assertSame(5, (int) $this->balanceQty($customerId, $productId));

        // 部分归还：return_qty=2 < balance.qty=5
        $returnId = $this->storeReturnBorrow($borrowOrderId, $customerId, $productId, 2);
        $this->postJson("/admin/business/van-return-borrow-order/{$returnId}/approve")->assertStatus(200);

        // 余额应减为 3，行保留
        $this->assertSame(3, (int) $this->balanceQty($customerId, $productId), '部分归还后余额应剩余 3');
    }

    public function test_exact_return_clears_balance_row(): void
    {
        [$borrowOrderId, $customerId, $productId] = $this->makeApprovedBorrow(3);

        // 等额归还：return_qty=3 == balance.qty=3 → newQty=0 → 删行
        $returnId = $this->storeReturnBorrow($borrowOrderId, $customerId, $productId, 3);
        $this->postJson("/admin/business/van-return-borrow-order/{$returnId}/approve")->assertStatus(200);

        $this->assertNull(
            DB::table('van_customer_borrow_balances')
                ->where('customer_id', $customerId)
                ->where('product_id', $productId)->first(),
            '等额还清后余额行应被删除'
        );
    }

    /** 建一辆车 + 车辆仓（type=vehicle + vehicle_id），复刻 VanReturnToWarehouseFlowTest */
    private function makeVehicleWarehouse(): array
    {
        $plate = '京A'.uniqid();
        $vehicleId = DB::table('vehicles')->insertGetId([
            'plate_no' => $plate,
            'driver_name' => '测试司机',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'code' => 'VW'.uniqid(),
            'name' => '车辆仓_'.$plate,
            'type' => 'vehicle',
            'vehicle_id' => $vehicleId,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$vehicleId, Warehouse::find($warehouseId)];
    }

    /**
     * 建一笔已审核的车销借货（borrow_qty 件），返回 [borrowOrderId, customerId, productId]。
     * 车上仓预置 10 件库存供 approve 时 stockOut。
     */
    private function makeApprovedBorrow(int $borrowQty): array
    {
        $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();

        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $vehicleWh->id,
            'quantity' => 10, 'frozen_qty' => 0,
        ]);

        $storeRes = $this->postJson('/admin/business/van-borrow-order', [
            'vehicle_id' => $vehicle,
            'vehicle_warehouse_id' => $vehicleWh->id,
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $product->id, 'borrow_qty' => $borrowQty],
            ],
        ]);
        $storeRes->assertStatus(200);
        $borrowOrderId = $storeRes->json('data.id');

        // approve 才触发 stockOut + increaseBorrowBalance
        $this->postJson("/admin/business/van-borrow-order/{$borrowOrderId}/approve")->assertStatus(200);

        return [$borrowOrderId, $customer->id, $product->id];
    }

    /** 建一笔还货草稿并返回其 id */
    private function storeReturnBorrow(int $borrowOrderId, int $customerId, int $productId, int $returnQty): int
    {
        $storeRes = $this->postJson('/admin/business/van-return-borrow-order', [
            'customer_id' => $customerId,
            'vehicle_warehouse_id' => $this->currentVehicleWarehouseId(),
            'borrow_order_id' => $borrowOrderId,
            'items' => [
                ['product_id' => $productId, 'return_qty' => $returnQty],
            ],
        ]);
        $storeRes->assertStatus(200);

        return $storeRes->json('data.id');
    }

    private function currentVehicleWarehouseId(): int
    {
        return (int) DB::table('warehouses')->where('type', 'vehicle')->orderByDesc('id')->value('id');
    }

    private function balanceQty(int $customerId, int $productId): ?int
    {
        $qty = DB::table('van_customer_borrow_balances')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)->value('qty');

        return $qty === null ? null : (int) $qty;
    }
}
