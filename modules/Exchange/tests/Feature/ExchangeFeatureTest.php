<?php

namespace Tests\Exchange\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Exchange\Models\ExchangeOrder;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Models\SalesOrderItem;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

class ExchangeFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): array
    {
        $admin = $this->actingAsAdmin();
        $customer = Customer::create(['code' => 'C1', 'name' => '测试客户', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => '主仓库', 'type' => 'normal', 'is_active' => true]);

        $out = Product::create(['name' => '薯片', 'code' => 'P1', 'price_small' => 5, 'price_unit_small' => '包', 'is_active' => true]);
        $in = Product::create(['name' => '可乐', 'code' => 'P2', 'price_small' => 3, 'price_unit_small' => '瓶', 'is_active' => true]);

        DB::table('stocks')->insert([
            ['product_id' => $out->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100, 'cost_price' => 3],
            ['product_id' => $in->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100, 'cost_price' => 2],
        ]);

        return ['admin' => $admin, 'customer' => $customer, 'warehouse' => $warehouse, 'out' => $out, 'in' => $in];
    }

    public function test_exchange_approve_swaps_stock_and_settles_diff(): void
    {
        $d = $this->seedData();

        // 换出 薯片(5) 换入 可乐(3)，两侧数量同为 5：差价 = 5*3 - 5*5 = 15 - 25 = -10（退给客户）
        $store = $this->postJson('/admin/business/exchange-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'exchange_reason' => '质量问题',
            'items' => [
                ['product_id_out' => $d['out']->id, 'qty' => 5, 'unit_price_out' => 5, 'product_id_in' => $d['in']->id, 'unit_price_in' => 3],
            ],
        ]);
        $store->assertOk();
        $id = $store->json('data.id');

        $submit = $this->postJson("/admin/business/exchange-order/{$id}/submit", ['refund_method' => 'offset']);
        $submit->assertOk();

        $approve = $this->postJson("/admin/business/exchange-order/{$id}/approve");
        $approve->assertOk();
        $this->assertEquals(ExchangeOrder::STATUS_APPROVED, $approve->json('data.status'));

        // 换出薯片回库: 100+5=105；换入可乐出库: 100-5=95
        $this->assertEquals(105, (int) DB::table('stocks')->where('product_id', $d['out']->id)->value('quantity'));
        $this->assertEquals(95, (int) DB::table('stocks')->where('product_id', $d['in']->id)->value('quantity'));
        $this->assertEquals(-10, (float) $approve->json('data.diff_amount'));
    }

    public function test_sales_order_items_can_be_loaded_for_exchange(): void
    {
        $d = $this->seedData();

        $so = SalesOrder::create([
            'order_no' => 'XS202610140001',
            'order_type' => 'normal',
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'order_date' => now()->toDateString(),
            'total_amount' => 50,
            'total_qty' => 10,
            'paid_amount' => 0,
            'status' => 'approved',
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $d['out']->id,
            'quantity' => 10,
            'price' => 5,
            'amount' => 50,
        ]);

        // 按 id 查：带出原销售单及其商品明细，供换货弹窗自动填充
        $byId = $this->getJson("/admin/business/exchange-order/sales-order-items?sales_order_id={$so->id}");
        $byId->assertOk();
        $this->assertEquals('XS202610140001', $byId->json('data.order.order_no'));
        $this->assertEquals($d['customer']->id, $byId->json('data.order.customer_id'));
        $this->assertCount(1, $byId->json('data.items'));
        $this->assertEquals($d['out']->id, $byId->json('data.items.0.product_id'));
        $this->assertEquals(10, (int) $byId->json('data.items.0.quantity'));
        $this->assertEquals(50, (float) $byId->json('data.items.0.amount'));

        // 按单号查同样可用
        $byNo = $this->getJson('/admin/business/exchange-order/sales-order-items?sales_order_no=XS202610140001');
        $byNo->assertOk();
        $this->assertEquals($so->id, $byNo->json('data.order.id'));

        // 不存在的单号返回 404
        $this->getJson('/admin/business/exchange-order/sales-order-items?sales_order_no=NOT-EXIST')->assertStatus(404);
    }

    public function test_exchange_order_can_reference_original_sales_order(): void
    {
        $d = $this->seedData();

        $so = SalesOrder::create([
            'order_no' => 'XS202610140002',
            'order_type' => 'normal',
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'order_date' => now()->toDateString(),
            'total_amount' => 50,
            'total_qty' => 10,
            'paid_amount' => 0,
            'status' => 'approved',
        ]);

        $store = $this->postJson('/admin/business/exchange-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'sales_order_id' => $so->id,
            'sales_order_no' => $so->order_no,
            'exchange_reason' => '包装破损',
            'items' => [['product_id_out' => $d['out']->id, 'qty' => 5, 'unit_price_out' => 5, 'product_id_in' => $d['in']->id, 'unit_price_in' => 3]],
        ]);
        $store->assertOk();

        $this->assertEquals($so->id, DB::table('exchange_orders')->where('id', $store->json('data.id'))->value('sales_order_id'));
        $this->assertEquals('XS202610140002', DB::table('exchange_orders')->where('id', $store->json('data.id'))->value('sales_order_no'));
    }

    public function test_exchange_summary_endpoints(): void
    {
        $d = $this->seedData();
        $this->postJson('/admin/business/exchange-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'exchange_reason' => '质量问题',
            'items' => [['product_id_out' => $d['out']->id, 'qty' => 5, 'unit_price_out' => 5, 'product_id_in' => $d['in']->id, 'unit_price_in' => 3]],
        ]);
        $this->postJson('/admin/business/exchange-order/1/submit', ['refund_method' => 'offset']);
        $this->postJson('/admin/business/exchange-order/1/approve');

        $this->getJson('/admin/business/exchange-summary')->assertOk();
        $this->getJson('/admin/business/exchange-summary/reason-distribution')->assertOk();
        $this->getJson('/admin/business/exchange-summary/product-rank')->assertOk();
        $this->getJson('/admin/business/exchange-summary/customer-detail')->assertOk();
    }
}
