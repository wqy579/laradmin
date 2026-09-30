<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * T6 采购/销售订单状态机测试
 *
 * 采购单：draft --approve--> approved --receive--> received
 *   - receive 把每张明细的数量加进收货仓库（库存随单据流动，不再需要另开 stock-in 补记）
 *   - 只有 draft/approved 可以 cancel；已入库的单据不能作废
 * 销售单：draft --approve--> approved（无后续单据联动）
 *
 * approve / receive 都有前置状态校验，非法流转一律 422。
 * 此前这三处是「钉住现状」的红灯：approve 无前置校验（状态可被来回翻转）、
 * receive 只翻状态不动库存、没有取消端点。现已修复，用例改为正向断言。
 * 退货单的状态机与库存不足保护见 ReturnOrderFeatureTest。
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

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->actingAsAdmin()->id;
        $this->productId = $this->makeProduct()->id;
    }

    /** 下单即冻结库存，需为 (商品, 仓库) 预置库存 */
    private function seedStock(int $warehouseId, int $qty = 1000): void
    {
        Stock::create([
            'product_id' => $this->productId,
            'warehouse_id' => $warehouseId,
            'quantity' => $qty,
            'frozen_qty' => 0,
        ]);
    }

    // ---------------------------------------------------------------- 销售单

    public function test_sales_order_store_creates_pending_with_totals(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $this->seedStock($warehouse->id);

        $response = $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()->assertJsonPath('message', '订单创建成功');

        $order = DB::table('sales_orders')->latest('id')->first();
        $this->assertSame('pending', $order->status);
        $this->assertStringStartsWith('SO', $order->order_no);
        $this->assertSame(15, (int) $order->total_qty);
        $this->assertSame(45.0, (float) $order->total_amount);
    }

    public function test_sales_order_approve_disabled_pending_editable(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $this->seedStock($warehouse->id);
        $payload = $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $id = $this->postJson('/admin/business/sales-order', $payload)->json('data.id');

        // 状态流转：待配货 → 配货中
        $this->postJson("/admin/business/sales-order/{$id}/approve")
            ->assertOk();
        $this->assertSame('配货中', \DB::table('sales_orders')->find($id)->status);

        // 待配货可编辑 / 可删除（配货中后不可删）
        $id2 = $this->postJson('/admin/business/sales-order', $payload)->json('data.id');
        $this->putJson("/admin/business/sales-order/{$id2}", $payload)->assertOk();
        $this->deleteJson("/admin/business/sales-order/{$id2}")->assertOk();
    }

    public function test_sales_order_cancel_only_from_pending(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $this->seedStock($warehouse->id);

        $id = $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        // 待配货可取消（并释放冻结库存）
        $this->postJson("/admin/business/sales-order/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', '取消成功');
        $this->assertSame('cancelled', \DB::table('sales_orders')->find($id)->status);

        // 已取消的不能再取消
        $this->postJson("/admin/business/sales-order/{$id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有待配货/配货中状态的订单可以作废');
    }

    public function test_sales_order_statistics_and_list_filter(): void
    {
        $customer1 = $this->makeCustomer();
        $customer2 = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $this->seedStock($warehouse->id);

        $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer1->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertOk();
        $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer2->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertOk();

        $stats = $this->getJson('/admin/business/sales-order/statistics')->json('data');
        $this->assertSame(2, (int) $stats['total_orders']);
        $this->assertSame(2, (int) $stats['pending']);
        $this->assertSame(0, (int) $stats['approved']);

        // 列表按状态/客户过滤（分页统一走 paginated 信封：data.list）
        $this->getJson('/admin/business/sales-order?status=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data.list');
        $this->getJson("/admin/business/sales-order?customer_id={$customer1->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.list');
    }
}
