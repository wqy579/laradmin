<?php

namespace Tests\Feature;

use Modules\Business\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T6 采购/销售订单状态机测试
 *
 * 采购单：draft --approve--> approved --receive--> received
 * 销售单：draft --approve--> approved（无后续单据联动）
 *
 * 并钉住当前实现的两个边界：
 *  - approve 没有前置状态校验（任意状态可重复审批）；
 *  - receive 只改状态、不产生任何库存变动（入库需另行走 /admin/business/stock-in）。
 * 两者是设计取舍还是缺陷，报告里单独讨论；若后续加守卫，请同步调整对应用例。
 */
class OrderStateFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->productId, 'quantity' => 10, 'price' => 3.5],
                ['product_id' => $this->productId, 'quantity' => 5, 'price' => 2],
            ],
        ], $overrides);
    }

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->productId = $this->makeProduct()->id;
    }

    // ---------------------------------------------------------------- 采购单

    public function test_purchase_order_store_creates_draft_with_totals(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();

        $response = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()->assertJsonPath('message', '创建成功');

        $order = DB::table('purchase_orders')->latest('id')->first();
        $this->assertSame('draft', $order->status);
        $this->assertStringStartsWith('PO', $order->order_no);
        $this->assertSame(15, (int) $order->total_qty, '总数量应为 10 + 5');
        $this->assertSame(45.0, (float) $order->total_amount, '总金额应为 10*3.5 + 5*2');
        $this->assertSame(2, (int) DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->count());

        // 参数校验：空 items / 不存在的供应商
        $this->postJson('/admin/business/purchase-order', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
        ])->assertStatus(422);
        $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => 999999,
            'warehouse_id' => $warehouse->id,
        ]))->assertStatus(422);
    }

    public function test_purchase_order_update_only_in_draft(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $create = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]));
        $id = $create->json('data.id');

        $this->putJson("/admin/business/purchase-order/{$id}", $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $this->productId, 'quantity' => 1, 'price' => 1],
            ],
        ]))->assertOk();

        $order = DB::table('purchase_orders')->find($id);
        $this->assertSame(1, (int) $order->total_qty, '编辑草稿应重算明细');

        // 审批后不可编辑
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->putJson("/admin/business/purchase-order/{$id}", $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertStatus(422);
    }

    public function test_purchase_order_destroy_only_in_draft(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        // 审批后不可删
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->deleteJson("/admin/business/purchase-order/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态可以删除');

        // 新建一张草稿单可删
        $id2 = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');
        $this->deleteJson("/admin/business/purchase-order/{$id2}")
            ->assertOk()
            ->assertJsonPath('message', '删除成功');
        $this->assertNull(DB::table('purchase_orders')->find($id2));
    }

    public function test_purchase_order_approve_flow(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        $this->postJson("/admin/business/purchase-order/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('message', '审批成功');

        $order = DB::table('purchase_orders')->find($id);
        $this->assertSame('approved', $order->status);
        $this->assertNotNull($order->approved_at);
    }

    public function test_purchase_order_receive_requires_approved_status(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        // 草稿不能直接入库
        $this->postJson("/admin/business/purchase-order/{$id}/receive")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有已审批的订单可以入库');

        // 审批后可入库
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$id}/receive")
            ->assertOk()
            ->assertJsonPath('message', '入库成功');
        $this->assertSame('received', DB::table('purchase_orders')->find($id)->status);

        // 入库后不能重复入库
        $this->postJson("/admin/business/purchase-order/{$id}/receive")->assertStatus(422);
    }

    public function test_purchase_order_receive_does_not_change_stock(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $this->productId, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $this->productId, 'quantity' => 5, 'price' => 2],
            ],
        ]))->json('data.id');

        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$id}/receive")->assertOk();

        // 钉住现状：receive 只翻状态，库存不变（采购入库实际通过 /admin/business/stock-in 完成）。
        // 若后续把入库联动进 receive，本用例会红，届时请改为断言库存 += 明细数量。
        $this->assertSame(
            10,
            (int) Stock::where('product_id', $this->productId)->where('warehouse_id', $warehouse->id)->value('quantity'),
            'receive 不应改变库存（当前设计：入库走独立的 stock-in 接口）'
        );
    }

    public function test_purchase_order_approve_has_no_precondition(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$id}/receive")->assertOk();
        $this->assertSame('received', DB::table('purchase_orders')->find($id)->status);

        // 钉住现状：已入库的单据仍可再次审批并把状态打回 approved。
        // 这是缺少前置状态校验的表现（配合“可重复 receive 被拦截”看，实际危害有限，
        // 但状态可被来回翻转）。若加了守卫，本用例应改为断言 4xx。
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->assertSame('approved', DB::table('purchase_orders')->find($id)->status);
    }

    public function test_purchase_order_has_no_cancel_endpoint(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        // 钉住现状：订单没有取消端点（草稿之外的单据无法作废）。
        $response = $this->postJson("/admin/business/purchase-order/{$id}/cancel");
        $this->assertTrue(in_array($response->status(), [404, 405]), '当前不存在取消端点，若已新增请更新本用例与状态机文档');
    }

    public function test_purchase_order_statistics(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertOk();

        $response = $this->getJson('/admin/business/purchase-order/statistics');
        $response->assertOk();
        $stats = $response->json('data');
        $this->assertSame(1, (int) $stats['total_orders']);
        $this->assertSame(1, (int) $stats['pending']);
        $this->assertSame(0, (int) $stats['approved']);
    }

    // ---------------------------------------------------------------- 销售单

    public function test_sales_order_store_creates_draft_with_totals(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();

        $response = $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()->assertJsonPath('message', '创建成功');

        $order = DB::table('sales_orders')->latest('id')->first();
        $this->assertSame('draft', $order->status);
        $this->assertStringStartsWith('SO', $order->order_no);
        $this->assertSame(15, (int) $order->total_qty);
        $this->assertSame(45.0, (float) $order->total_amount);
    }

    public function test_sales_order_approve_and_update_guard(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $payload = $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $id = $this->postJson('/admin/business/sales-order', $payload)->json('data.id');

        $this->postJson("/admin/business/sales-order/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('message', '审批成功');
        $this->assertSame('approved', DB::table('sales_orders')->find($id)->status);

        // 审批后不可编辑/删除
        $this->putJson("/admin/business/sales-order/{$id}", $payload)->assertStatus(422);
        $this->deleteJson("/admin/business/sales-order/{$id}")->assertStatus(422);

        // 钉住现状：销售单审批同样无前置校验，可从 approved 再审批（幂等效果）
        $this->postJson("/admin/business/sales-order/{$id}/approve")->assertOk();
    }

    public function test_sales_order_has_no_cancel_endpoint(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        $response = $this->postJson("/admin/business/sales-order/{$id}/cancel");
        $this->assertTrue(in_array($response->status(), [404, 405]), '当前不存在取消端点，若已新增请更新本用例与状态机文档');
    }

    public function test_sales_order_statistics_and_list_filter(): void
    {
        $customer1 = $this->makeCustomer();
        $customer2 = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();

        $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer1->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertOk();
        $id2 = $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer2->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');
        $this->postJson("/admin/business/sales-order/{$id2}/approve")->assertOk();

        $stats = $this->getJson('/admin/business/sales-order/statistics')->json('data');
        $this->assertSame(2, (int) $stats['total_orders']);
        $this->assertSame(1, (int) $stats['pending']);
        $this->assertSame(1, (int) $stats['approved']);

        // 列表按状态/客户过滤
        $this->getJson('/admin/business/sales-order?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->getJson("/admin/business/sales-order?customer_id={$customer1->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }
}
