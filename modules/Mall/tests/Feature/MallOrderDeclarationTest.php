<?php

namespace Tests\Mall\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mall\Models\MallOrder;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Models\SalesOrderItem;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

/**
 * F5 验收 2/3：分销商城 → F1 订货申报单。
 *
 * 硬性要求：商城订单必须走 F1 通道生成申报单（sales_orders + sales_order_items）。
 *  - 下单即创建申报单，并回写 mall_orders.sales_order_id / sales_order_no；
 *  - 单一数据源：申报单金额/数量由服务按 products 三档价重算（不信前端 amount）；
 *  - 下单即冻结库存（对齐 F1「下单即冻结」语义）；
 *  - 校验：缺客户/仓库、零数量、零价、库存不足均 422；
 *  - 后台接单/拒单只改商城侧，不改写申报单数据。
 */
class MallOrderDeclarationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '商城运营']);
    }

    public function test_mall_order_generates_declaration_and_writes_back(): void
    {
        [$product, $warehouse, $customer] = $this->fixture();

        $resp = $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'items' => [[
                'product_id' => $product->id,
                'qty_large' => 2,
                'qty_medium' => 3,
                'qty_small' => 5,
            ]],
        ]);

        $resp->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.customer_name', $customer->name);

        // 申报单已生成并回写
        $mallOrder = MallOrder::findOrFail($resp->json('data.id'));
        $this->assertNotNull($mallOrder->sales_order_id);
        $this->assertNotNull($mallOrder->sales_order_no);

        $declaration = SalesOrder::findOrFail($mallOrder->sales_order_id);
        $this->assertSame($mallOrder->sales_order_no, $declaration->order_no);
        $this->assertSame($customer->id, $declaration->customer_id);
        $this->assertSame($warehouse->id, $declaration->warehouse_id);
        $this->assertSame('entry', $declaration->sort_type);
        $this->assertStringContainsString($mallOrder->order_no, $declaration->remark);

        // 申报单明细：数量 = 大×conv×convMd + 中×convMd + 小（2×2×3 + 3×3 + 5 = 26）
        $item = SalesOrderItem::where('sales_order_id', $declaration->id)->firstOrFail();
        $this->assertSame(26, (int) $item->quantity);
        $this->assertSame(2, (int) $item->qty_large);
        $this->assertSame(3, (int) $item->qty_medium);
        $this->assertSame(5, (int) $item->qty_small);
        // 金额 = 2×100 + 3×20 + 5×5 = 285（单一数据源重算）
        $this->assertSame(285.0, (float) $item->amount);
        $this->assertSame(285.0, (float) $declaration->total_amount);
        $this->assertSame(26, (int) $declaration->total_qty);

        // 库存已冻结 26（可用 100-26=74）
        $stock = Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
        $this->assertSame(26, (int) $stock->frozen_qty);
        $this->assertSame(74, (int) $stock->quantity - (int) $stock->frozen_qty);
    }

    public function test_mall_order_rejects_missing_customer_or_warehouse(): void
    {
        [$product, $warehouse, $customer] = $this->fixture();

        $this->postJson('/admin/f5/mall/orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 1]],
        ])->assertStatus(422);

        $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 1]],
        ])->assertStatus(422);
    }

    public function test_mall_order_rejects_zero_quantity(): void
    {
        [$product, $warehouse, $customer] = $this->fixture();

        $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 0, 'qty_medium' => 0, 'qty_small' => 0]],
        ])->assertStatus(422);

        $this->assertSame(0, MallOrder::count());
    }

    public function test_mall_order_rejects_zero_price_product(): void
    {
        [$product, $warehouse, $customer] = $this->fixture();
        $product->update(['price_large' => 0, 'price_medium' => 0, 'price_small' => 0]);

        $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, MallOrder::count());
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_mall_order_rejects_insufficient_stock(): void
    {
        [$product, $warehouse, $customer] = $this->fixture();

        $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 100]],
        ])->assertStatus(422); // 需要 100×2×3=600 > 库存 100

        $this->assertSame(0, MallOrder::count());
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_accept_and_reject_only_touch_mall_side(): void
    {
        [$product, $warehouse, $customer] = $this->fixture();
        $resp = $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 1]],
        ])->assertOk();
        $mallId = $resp->json('data.id');
        $mallOrder = MallOrder::findOrFail($mallId);
        $declarationStatus = $mallOrder->salesOrder->status;

        // 接单：只改商城侧
        $this->postJson("/admin/f5/mall/orders/{$mallId}/accept", ['remark' => '安排发车'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame('accepted', MallOrder::findOrFail($mallId)->status);
        $this->assertSame($declarationStatus, SalesOrder::findOrFail($mallOrder->sales_order_id)->status, '接单不改写申报单');

        // 拒绝：只能待接单拒绝
        $second = $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 1]],
        ])->assertOk()->json('data.id');
        $this->postJson("/admin/f5/mall/orders/{$second}/reject", ['remark' => '客户取消'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('rejected', MallOrder::findOrFail($second)->status);

        // 已接单不能重复接
        $this->postJson("/admin/f5/mall/orders/{$mallId}/accept")->assertStatus(422);
    }

    public function test_pending_order_can_be_cancelled(): void
    {
        [$product, $warehouse, $customer] = $this->fixture();
        $resp = $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 1]],
        ])->assertOk();

        $this->postJson("/admin/f5/mall/orders/{$resp->json('data.id')}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_index_and_show_carry_full_item_snapshot(): void
    {
        [$product, $warehouse, $customer] = $this->fixture();
        $resp = $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 2]],
        ])->assertOk();
        $mallId = $resp->json('data.id');

        $detail = $this->getJson("/admin/f5/mall/orders/{$mallId}")->assertOk()->json('data');
        $this->assertCount(1, $detail['items']);
        $item = $detail['items'][0];
        $this->assertSame($product->code, $item['product_code']);
        $this->assertSame($product->name, $item['product_name']);
        $this->assertSame(2, $item['qty_large']);
        $this->assertSame(200.0, (float) $item['amount']);
        $this->assertNotNull($detail['sales_order_no']);
        $this->assertSame($product->id, $item['product_id']);

        // 列表同样带申报单号
        $list = $this->getJson('/admin/f5/mall/orders')->assertOk()->json('data');
        $this->assertSame(1, $list['total']);
        $this->assertSame($mallId, $list['list'][0]['id']);
        $this->assertNotNull($list['list'][0]['sales_order_no']);
    }

    // ---------- helpers ----------

    /**
     * @return array{0: Product, 1: Warehouse, 2: Customer}
     */
    private function fixture(): array
    {
        $product = $this->makeProduct([
            'price_large' => 100,
            'price_medium' => 20,
            'price_small' => 5,
            'unit_conversion' => 2,          // 1大 = 2中
            'unit_conversion_medium' => 3,   // 1中 = 3小
            'price_unit' => '件',
            'barcode_medium_unit' => '盒',
            'price_unit_small' => '个',
        ]);
        $warehouse = $this->makeWarehouse();
        $customer = $this->makeCustomer();

        Stock::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'frozen_qty' => 0,
            'cost_price' => 50,
            'total_amount' => 5000,
            'updated_at' => now(),
        ]);

        return [$product, $warehouse, $customer];
    }
}
