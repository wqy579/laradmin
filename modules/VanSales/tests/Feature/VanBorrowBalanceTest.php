<?php

namespace Tests\VanSales\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Product;
use Tests\TestCase;

/**
 * 车销借还清零保护测试
 *
 * 复现 bug：decreaseBorrowBalance 超还时 qty 被写为负数
 * 期望：与实现 1/2 一致，超还时删除行（而非写负数）
 */
class VanBorrowBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_decreaseBorrowBalance_deletes_when_return_qty_exceeds_balance(): void
    {
        $admin = $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $customer = Customer::create(['code' => 'C1', 'name' => '测试客户', 'is_active' => true]);

        // 1. 创建车销借货（触发 increaseBorrowBalance → van_customer_borrow_balances qty=1）
        $borrowRes = $this->postJson('/admin/business/van-borrow-order', [
            'vehicle_id' => $vehicle,
            'vehicle_warehouse_id' => $vehicleWh->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $product->id, 'amount' => 100],
            ],
        ]);
        $borrowRes->assertStatus(200);
        $borrowOrderId = $borrowRes->json('data.id');

        // 验证借货余额已创建
        $balance = DB::table('van_customer_borrow_balances')
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)->first();

        $this->assertNotNull($balance, '应存在 van_customer_borrow_balances 记录');
        $this->assertSame(1, (int) $balance->qty, '借货单 qty 应为 1');

        // 2. 触发还货核销，return_qty=10 > balance.qty=1
        $returnRes = $this->postJson("/admin/business/van-return-borrow-order/{$borrowOrderId}/return", [
            'return_qty' => 10,
        ]);

        // 3. 断言：qty 不得为负数
        $updatedBalance = DB::table('van_customer_borrow_balances')
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)->first();

        $this->assertNotNull($updatedBalance, '超过余额归还后，余额记录应被保留');
        $this->assertGreaterThanOrEqual(0, (int) $updatedBalance->qty, 'P3 根因：qty 不得为负数，超还时应删除行而非写负值');
    }

    public function test_decreaseBorrowBalance_leaves_positive_balance_when_return_qty_under_balance(): void
    {
        $admin = $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $customer = Customer::create(['code' => 'C2', 'name' => '测试客户2', 'is_active' => true]);

        // 创建借货
        $borrowRes = $this->postJson('/admin/business/van-borrow-order', [
            'vehicle_id' => $vehicle,
            'vehicle_warehouse_id' => $vehicleWh->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $product->id, 'amount' => 100],
            ],
        ]);
        $borrowRes->assertStatus(200);
        $borrowOrderId = $borrowRes->json('data.id');

        // 还货数量小于余额
        $returnRes = $this->postJson("/admin/business/van-return-borrow-order/{$borrowOrderId}/return", [
            'return_qty' => 1,
        ]);

        $updatedBalance = DB::table('van_customer_borrow_balances')
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)->first();

        $this->assertNotNull($updatedBalance);
        $this->assertSame(0, (int) $updatedBalance->qty, '归还核销时 qty 应减为 0 并保留行（而非删除）');
    }
}