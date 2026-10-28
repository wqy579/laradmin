<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T5.2 订货调整
 *
 * 订货调整单（针对已存在的销售订单）：
 *   - 新增（modify 调数量 / void 作废整单）→ 修改（仅 pending）→ 作废 → 审核流（approve/reject）
 *   - approve 时落地：modify 回写 sales_order_items 数量并重算总额；void 将订单置 cancelled
 *   - 每次动作写 order_operation_logs（order_type=order_adjustment），审核落地同时给源订单留痕
 */
class OrderAdjustFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    private int $customerId;

    private int $warehouseId;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->productId = $this->makeProduct()->id;
        $this->warehouseId = $this->makeWarehouse()->id;
        $this->orderId = $this->seedOrder();
    }

    private function seedOrder(): int
    {
        $now = now();
        $this->customerId = DB::table('customers')->insertGetId([
            'code' => 'C-ADJ',
            'name' => '调整客户',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO-ADJ-0001',
            'order_type' => 'wholesale',
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
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

    private function adjustPayload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => $this->orderId,
            'type' => 'modify',
            'reason' => '客户要求减少数量',
            'items' => [
                ['product_id' => $this->productId, 'old_qty' => 100, 'new_qty' => 80],
            ],
        ], $overrides);
    }

    public function test_can_create_modify_adjustment_and_it_leaves_history(): void
    {
        $res = $this->postJson('admin/business/order-adjustment', $this->adjustPayload());

        $res->assertOk();
        $adjustNo = $res->json('data.adjust_no');
        $this->assertNotEmpty($adjustNo);

        // 留痕：创建即有历史
        $id = $res->json('data.id');
        $detail = $this->getJson("admin/business/order-adjustment/{$id}")->assertOk();
        $history = $detail->json('data.history');
        $this->assertNotEmpty($history);
        $this->assertSame('创建', $history[0]['action']);
    }

    public function test_approve_modify_adjustment_applies_qty_and_totals(): void
    {
        $id = $this->postJson('admin/business/order-adjustment', $this->adjustPayload())->json('data.id');

        $this->postJson("admin/business/order-adjustment/{$id}/approve")->assertOk();

        // 数量已落到明细，金额同步重算
        $item = DB::table('sales_order_items')->where('sales_order_id', $this->orderId)->first();
        $this->assertSame(80.0, (float) $item->quantity);
        $this->assertSame(400.0, (float) $item->amount);

        $order = DB::table('sales_orders')->find($this->orderId);
        $this->assertSame(80.0, (float) $order->total_qty);
        $this->assertSame(400.0, (float) $order->total_amount);

        // 源订单留痕
        $orderLog = DB::table('order_operation_logs')
            ->where('order_id', $this->orderId)
            ->where('order_type', 'sales_order')
            ->where('action', '订货调整')
            ->first();
        $this->assertNotNull($orderLog);
    }

    public function test_approve_void_adjustment_cancels_source_order(): void
    {
        $id = $this->postJson('admin/business/order-adjustment', $this->adjustPayload([
            'type' => 'void',
            'items' => null,
            'reason' => '客户取消订单',
        ]))->json('data.id');

        $this->postJson("admin/business/order-adjustment/{$id}/approve")->assertOk();

        $this->assertSame('cancelled', DB::table('sales_orders')->find($this->orderId)->status);
    }

    public function test_reject_adjustment_keeps_order_untouched(): void
    {
        $id = $this->postJson('admin/business/order-adjustment', $this->adjustPayload())->json('data.id');

        $this->postJson("admin/business/order-adjustment/{$id}/reject")->assertOk();
        $this->assertSame('rejected', DB::table('order_adjustments')->find($id)->status);

        $item = DB::table('sales_order_items')->where('sales_order_id', $this->orderId)->first();
        $this->assertSame(100.0, (float) $item->quantity);
    }

    public function test_only_pending_adjustment_can_be_updated_or_cancelled(): void
    {
        $id = $this->postJson('admin/business/order-adjustment', $this->adjustPayload())->json('data.id');
        $this->postJson("admin/business/order-adjustment/{$id}/approve")->assertOk();

        // 已审核不可再改 / 作废
        $this->putJson("admin/business/order-adjustment/{$id}", ['reason' => '再改'])->assertStatus(422);
        $this->deleteJson("admin/business/order-adjustment/{$id}")->assertStatus(422);
        $this->postJson("admin/business/order-adjustment/{$id}/approve")->assertStatus(422);
    }

    public function test_void_adjustment_does_not_accept_items_and_modify_requires_items(): void
    {
        // modify 缺 items → 422
        $this->postJson('admin/business/order-adjustment', $this->adjustPayload(['items' => []]))->assertStatus(422);

        // void 带 items → 422
        $this->postJson('admin/business/order-adjustment', $this->adjustPayload([
            'type' => 'void',
        ]))->assertOk();
    }
}
