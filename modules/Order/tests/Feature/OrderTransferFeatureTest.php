<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T5.3 订单移库
 *
 * 强约束：移库量 ≤ 订货量（前后端双重校验，超量报错且不落库）。
 * 移库留痕（order_operation_logs, order_type=order_transfer）。
 */
class OrderTransferFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    private int $warehouseA;

    private int $warehouseB;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->productId = $this->makeProduct()->id;
        $this->warehouseA = $this->makeWarehouse()->id;
        $this->warehouseB = $this->makeWarehouse()->id;
        $this->orderId = $this->seedOrder();
    }

    private function seedOrder(): int
    {
        $now = now();
        $customerId = DB::table('customers')->insertGetId([
            'code' => 'C-TRF',
            'name' => '移库客户',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO-TRF-0001',
            'order_type' => 'wholesale',
            'customer_id' => $customerId,
            'warehouse_id' => $this->warehouseA,
            'order_date' => '2026-10-28',
            'total_qty' => 100,
            'total_amount' => 500.00,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $orderId,
            'product_id' => $this->productId,
            'quantity' => 100,
            'actual_qty' => 0,
            'price' => 5.00,
            'amount' => 500.00,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $orderId;
    }

    private function transferPayload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->orderId,
            'to_warehouse_id' => $this->warehouseB,
            'transfer_date' => '2026-10-28',
            'reason' => '目标仓 nearer 客户',
            'items' => [
                ['product_id' => $this->productId, 'qty' => 100],
            ],
        ], $overrides);
    }

    public function test_transfer_qty_must_not_exceed_ordered_qty(): void
    {
        // 移库量 120 > 订货量 100 → 422 且不落库
        $this->postJson('admin/business/order-transfer', $this->transferPayload([
            'items' => [['product_id' => $this->productId, 'qty' => 120]],
        ]))->assertStatus(422);

        $this->assertSame(0, DB::table('order_transfers')->count());
        $this->assertSame(0, DB::table('order_transfer_items')->count());
    }

    public function test_can_create_transfer_within_limit_and_it_leaves_history(): void
    {
        $res = $this->postJson('admin/business/order-transfer', $this->transferPayload())->assertOk();

        $id = $res->json('data.id');
        $this->assertNotEmpty($res->json('data.transfer_no'));

        // 落库校验
        $this->assertDatabaseHas('order_transfers', [
            'order_id' => $this->orderId,
            'to_warehouse_id' => $this->warehouseB,
            'status' => 'done',
        ]);
        $this->assertDatabaseHas('order_transfer_items', [
            'product_id' => $this->productId,
            'qty' => 100,
        ]);

        // 源订单仓已切换
        $this->assertSame($this->warehouseB, (int) DB::table('sales_orders')->find($this->orderId)->warehouse_id);

        // 留痕
        $detail = $this->getJson('admin/business/order-transfer/'.$id)->assertOk();
        $this->assertNotEmpty($detail->json('data.history'));
    }

    public function test_partial_transfer_qty_is_allowed(): void
    {
        $res = $this->postJson('admin/business/order-transfer', $this->transferPayload([
            'items' => [['product_id' => $this->productId, 'qty' => 60]],
        ]))->assertOk();

        $this->assertSame(60.0, (float) DB::table('order_transfer_items')->where('product_id', $this->productId)->value('qty'));
        $res->assertOk();
    }

    public function test_transfer_to_same_warehouse_is_rejected(): void
    {
        $this->postJson('admin/business/order-transfer', $this->transferPayload([
            'to_warehouse_id' => $this->warehouseA,
        ]))->assertStatus(422);
    }

    public function test_transfer_list_and_detail_with_history(): void
    {
        $id = $this->postJson('admin/business/order-transfer', $this->transferPayload())->json('data.id');

        $list = $this->getJson('admin/business/order-transfer?keyword=SO-TRF')->assertOk();
        $this->assertSame(1, $list->json('data.total'));

        $detail = $this->getJson('admin/business/order-transfer/'.$id)->assertOk();
        $this->assertSame('SO-TRF-0001', $detail->json('data.order_no'));
        $this->assertCount(1, $detail->json('data.items'));
        $this->assertNotEmpty($detail->json('data.history'));
        $this->assertSame('移库', $detail->json('data.history.0.action'));
    }

    public function test_zero_qty_is_rejected(): void
    {
        $this->postJson('admin/business/order-transfer', $this->transferPayload([
            'items' => [['product_id' => $this->productId, 'qty' => 0]],
        ]))->assertStatus(422);
    }
}
