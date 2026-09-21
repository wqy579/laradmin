<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * 新增销售订单 —— 对齐旧系统行为
 *
 * 旧系统（laravel/09-handlers-order.js）的新增订单有几条硬性业务规则，
 * 新系统必须逐条对齐：
 *
 *  1. 三档数量 + 三档单价，金额 = 大*大价 + 中*中价 + 小*小价（calcAmount）
 *  2. 销售模式为「赠品」或「陈列费」时单价清零，price_source 标记「特殊」
 *  3. 明细折算成小单位的 quantity 按 unit_conversion / unit_conversion_medium 计算
 *  4. 订单头带 salesman_id（业务员）与 remark
 *  5. 只有草稿状态可编辑/删除
 *
 * 这些用例此前不存在——新系统控制器只收 product_id/quantity/price 三个字段，
 * 赠品清零、三档单价、业务员全部无处落地。
 */
class SalesOrderCreateFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    private int $productId;

    private int $threeUnitProductId;

    private int $warehouseId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->actingAsAdmin()->id;

        // 两单位商品：1 件 = 480 个，没有中单位
        $this->productId = Product::create([
            'name' => '两单位商品', 'code' => 'P2U', 'is_active' => true, 'is_online' => true,
            'price_unit' => '件', 'price_unit_small' => '个',
            'unit_conversion' => 480, 'unit_conversion_medium' => 0,
            'price_small' => 1, 'price_large' => 480, 'price_medium' => 0,
        ])->id;

        // 三单位商品：1 件 = 4 盒 = 480 个（unit_conversion=大→小 480，unit_conversion_medium=中→小 120）
        $this->threeUnitProductId = Product::create([
            'name' => '三单位商品', 'code' => 'P3U', 'is_active' => true, 'is_online' => true,
            'price_unit' => '件', 'barcode_medium_unit' => '盒', 'price_unit_small' => '个',
            'unit_conversion' => 480, 'unit_conversion_medium' => 120,
            'price_small' => 1, 'price_medium' => 120, 'price_large' => 480,
        ])->id;

        $this->warehouseId = $this->makeWarehouse()->id;
        $this->customerId = $this->makeCustomer()->id;

        // 下单即冻结库存，需预置库存供冻结（旧系统下单前同样要求有库存）
        Stock::create(['product_id' => $this->productId, 'warehouse_id' => $this->warehouseId, 'quantity' => 100000, 'frozen_qty' => 0]);
        Stock::create(['product_id' => $this->threeUnitProductId, 'warehouse_id' => $this->warehouseId, 'quantity' => 100000, 'frozen_qty' => 0]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->productId, 'qty_small' => 10, 'price_small' => 3.5],
            ],
        ], $overrides);
    }

    // ---------------------------------------------------------------- 三档金额

    public function test_store_computes_amount_across_three_units(): void
    {
        $response = $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [[
                'product_id' => $this->threeUnitProductId,
                'qty_large' => 1, 'qty_medium' => 2, 'qty_small' => 30,
                'price_large' => 480, 'price_medium' => 120, 'price_small' => 1,
                'sale_mode' => '正常销售',
            ]],
        ]));

        $response->assertOk();

        $item = $response->json('data.items.0');
        // 1*480 + 2*120 + 30*1 = 750
        $this->assertSame(750.0, (float) $item['amount']);
        $this->assertSame(1, (int) $item['qty_large']);
        $this->assertSame(2, (int) $item['qty_medium']);
        $this->assertSame(30, (int) $item['qty_small']);

        $order = $response->json('data');
        // 小单位折算（unit_conversion=大→小 480，unit_conversion_medium=中→小 120）：
        // 1*480*120 + 2*120 + 30 = 57870
        $this->assertSame(57870, (int) $order['total_qty']);
        $this->assertSame(750.0, (float) $order['total_amount']);
    }

    public function test_store_consolidates_quantity_to_small_units(): void
    {
        // 两单位商品：1 件 = 480 个
        $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [[
                'product_id' => $this->productId,
                'qty_large' => 2, 'qty_small' => 5,
                'price_large' => 480, 'price_small' => 1,
            ]],
        ]))->assertOk();

        $order = \DB::table('sales_orders')->latest('id')->first();
        $item = \DB::table('sales_order_items')->where('sales_order_id', $order->id)->first();

        // 2*480 + 5 = 965
        $this->assertSame(965, (int) $item->quantity);
        // 2*480 + 5*1 = 965
        $this->assertSame(965.0, (float) $item->amount);
        // price 约定等于小单位单价
        $this->assertSame(1.0, (float) $item->price);
    }

    // ---------------------------------------------------------------- 赠品清零

    public function test_gift_mode_stores_zeroed_price_with_special_source(): void
    {
        $response = $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [[
                'product_id' => $this->threeUnitProductId,
                'qty_small' => 10,
                'price_large' => 0, 'price_medium' => 0, 'price_small' => 0,
                'sale_mode' => '赠品',
                'price_source' => '特殊',
            ]],
        ]));

        $response->assertOk();
        $item = $response->json('data.items.0');

        // 赠品：三档单价全 0，price_source 落「特殊」
        $this->assertSame('赠品', $item['sale_mode']);
        $this->assertSame(0.0, (float) $item['price_small']);
        $this->assertSame(0.0, (float) $item['price_medium']);
        $this->assertSame(0.0, (float) $item['price_large']);
        $this->assertSame('特殊', $item['price_source']);
        $this->assertSame(0.0, (float) $item['amount']);
        $this->assertSame(10, (int) $item['quantity']);
        // 赠品也算量不算钱
        $this->assertSame(10, (int) $response->json('data.total_qty'));
        $this->assertSame(0.0, (float) $response->json('data.total_amount'));
    }

    public function test_display_fee_mode_also_marks_special(): void
    {
        $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [[
                'product_id' => $this->productId,
                'qty_small' => 5, 'sale_mode' => '陈列费', 'price_source' => '特殊',
            ]],
        ]))->assertOk();

        $item = \DB::table('sales_order_items')->latest('id')->first();
        $this->assertSame('陈列费', $item->sale_mode);
        $this->assertSame('特殊', $item->price_source);
        $this->assertSame(0.0, (float) $item->amount);
    }

    /** 普通模式不写 price_source，避免把「特殊」标记误存到正常订单 */
    public function test_normal_mode_leaves_price_source_empty(): void
    {
        $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [[
                'product_id' => $this->productId,
                'qty_small' => 3, 'price_small' => 2, 'sale_mode' => '正常销售',
            ]],
        ]))->assertOk();

        $item = \DB::table('sales_order_items')->latest('id')->first();
        $this->assertSame('正常销售', $item->sale_mode);
        $this->assertNull($item->price_source);
        $this->assertSame(6.0, (float) $item->amount);
    }

    /** 非法 price_source 值不允许入库，只认空值和「特殊」 */
    public function test_rejects_unknown_price_source(): void
    {
        $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [[
                'product_id' => $this->productId,
                'qty_small' => 1, 'price_source' => '手改',
            ]],
        ]))->assertStatus(422);

        $this->assertSame(0, \DB::table('sales_orders')->count());
    }

    // ---------------------------------------------------------------- 订单头

    public function test_store_saves_salesman_and_remark(): void
    {
        $salesman = User::create([
            'username' => 'seller_'.Str::random(6),
            'password' => Hash::make('secret123'),
            'real_name' => '业务员张', 'status' => 1,
        ]);

        $response = $this->postJson('/admin/business/sales-order', $this->basePayload([
            'salesman_id' => $salesman->id,
            'remark' => '整箱配送，上午送达',
        ]));

        $response->assertOk();
        $order = $response->json('data');
        $this->assertSame($salesman->id, (int) $order['salesman_id']);
        $this->assertSame('业务员张', $order['salesman_name']);
        $this->assertSame('整箱配送，上午送达', $order['remark']);
        $this->assertSame('pending', $order['status']);
        $this->assertStringStartsWith('SO', $order['order_no']);
    }

    // ---------------------------------------------------------------- 校验

    public function test_store_requires_customer_warehouse_date(): void
    {
        $payload = $this->basePayload();
        unset($payload['customer_id']);
        $this->postJson('/admin/business/sales-order', $payload)->assertStatus(422);

        $payload = $this->basePayload();
        unset($payload['warehouse_id']);
        $this->postJson('/admin/business/sales-order', $payload)->assertStatus(422);

        $payload = $this->basePayload();
        unset($payload['order_date']);
        $this->postJson('/admin/business/sales-order', $payload)->assertStatus(422);
    }

    public function test_store_requires_at_least_one_item(): void
    {
        $this->postJson('/admin/business/sales-order', $this->basePayload(['items' => []]))
            ->assertStatus(422);
    }

    public function test_update_recalculates_items_and_totals(): void
    {
        $create = $this->postJson('/admin/business/sales-order', $this->basePayload())->assertOk();
        $id = $create->json('data.id');

        $response = $this->putJson("/admin/business/sales-order/$id", $this->basePayload([
            'remark' => '改备注',
            'items' => [
                ['product_id' => $this->productId, 'qty_small' => 4, 'price_small' => 2, 'sale_mode' => '赠品', 'price_source' => '特殊'],
            ],
        ]));

        $response->assertOk();
        $order = $response->json('data');
        $this->assertSame('改备注', $order['remark']);
        $this->assertSame(1, (int) \DB::table('sales_order_items')->where('sales_order_id', $id)->count());
        $this->assertSame(4, (int) $order['total_qty']);
        $this->assertSame(0.0, (float) $order['total_amount']);
    }

    public function test_cancelled_order_cannot_be_updated(): void
    {
        $create = $this->postJson('/admin/business/sales-order', $this->basePayload())->assertOk();
        $id = $create->json('data.id');

        // 对齐旧系统：下单即 pending，取消后不可编辑
        $this->postJson("/admin/business/sales-order/$id/cancel")->assertOk();
        $this->putJson("/admin/business/sales-order/$id", $this->basePayload())
            ->assertStatus(422)
            ->assertJsonPath('message', '只有待配货状态的订单可以编辑');
    }

    // ---------------------------------------------------------------- 库存同步 products.stock_qty

    /** 下单冻结后，products.stock_qty（冗余汇总字段）应同步等于 stocks.quantity 之和 */
    public function test_freeze_syncs_products_stock_qty(): void
    {
        $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [['product_id' => $this->productId, 'qty_small' => 10, 'price_small' => 3.5]],
        ]))->assertOk();

        // 初始 stocks.quantity=100000，冻结 10 → 99990；products.stock_qty 应同步为 99990
        $this->assertSame(99990, (int) Stock::where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(99990.0, (float) \DB::table('products')->where('id', $this->productId)->value('stock_qty'));
    }

    /** 取消（解冻）后，products.stock_qty 应随之恢复 */
    public function test_unfreeze_on_cancel_restores_products_stock_qty(): void
    {
        $id = $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [['product_id' => $this->productId, 'qty_small' => 10, 'price_small' => 3.5]],
        ]))->assertOk()->json('data.id');

        $this->postJson("/admin/business/sales-order/$id/cancel")->assertOk();

        $this->assertSame(100000, (int) Stock::where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(100000.0, (float) \DB::table('products')->where('id', $this->productId)->value('stock_qty'));
    }

    // ---------------------------------------------------------------- 操作日志 order_operation_logs

    /** 下单写一条「创建订单」日志，字段对齐旧系统 MpController::logOperation */
    public function test_store_logs_creation_operation(): void
    {
        $resp = $this->postJson('/admin/business/sales-order', $this->basePayload([
            'items' => [['product_id' => $this->productId, 'qty_small' => 2, 'price_small' => 5]],
        ]))->assertOk();
        $orderId = $resp->json('data.id');
        $orderNo = $resp->json('data.order_no');

        $log = \DB::table('order_operation_logs')->where('order_id', $orderId)->first();
        $this->assertNotNull($log, '下单应写操作日志');
        $this->assertSame('创建订单', $log->action);
        $this->assertSame('sales_order', $log->order_type);
        $this->assertSame($orderNo, $log->order_no);
        $this->assertNull($log->from_status);
        $this->assertSame('pending', $log->to_status);
        $this->assertStringContainsString('1种商品', $log->detail);
        $this->assertNotNull($log->operator_id, '应记录操作人');
    }

    /** 取消写一条「取消订单」日志，记录 pending→cancelled 流转 */
    public function test_cancel_logs_cancellation_operation(): void
    {
        $id = $this->postJson('/admin/business/sales-order', $this->basePayload())->assertOk()->json('data.id');
        $this->postJson("/admin/business/sales-order/$id/cancel")->assertOk();

        $log = \DB::table('order_operation_logs')
            ->where('order_id', $id)->where('action', '取消订单')->first();
        $this->assertNotNull($log);
        $this->assertSame('pending', $log->from_status);
        $this->assertSame('cancelled', $log->to_status);
    }

    /** show 接口带回该单的操作历史 */
    public function test_show_returns_operation_logs(): void
    {
        $id = $this->postJson('/admin/business/sales-order', $this->basePayload())->assertOk()->json('data.id');

        $resp = $this->getJson("/admin/business/sales-order/$id");
        $resp->assertOk();
        $logs = $resp->json('data.operation_logs');
        $this->assertIsArray($logs);
        $this->assertCount(1, $logs);
        $this->assertSame('创建订单', $logs[0]['action']);
    }
}
