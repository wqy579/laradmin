<?php

namespace Tests\BorrowReturn\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\BorrowReturn\Models\BorrowOrder;
use Modules\BorrowReturn\Models\BorrowReturnOrder;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

class BorrowReturnFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): array
    {
        $admin = $this->actingAsAdmin();
        $customer = Customer::create(['code' => 'C1', 'name' => '测试客户', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => '主仓库', 'type' => 'normal', 'is_active' => true]);

        $p1 = Product::create(['name' => '可乐', 'code' => 'P1', 'price_small' => 3, 'price_unit_small' => '瓶', 'is_active' => true]);
        $p2 = Product::create(['name' => '薯片', 'code' => 'P2', 'price_small' => 5, 'price_unit_small' => '包', 'is_active' => true]);

        DB::table('stocks')->insert([
            ['product_id' => $p1->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100, 'cost_price' => 2],
            ['product_id' => $p2->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100, 'cost_price' => 3],
        ]);

        return ['admin' => $admin, 'customer' => $customer, 'warehouse' => $warehouse, 'p1' => $p1, 'p2' => $p2];
    }

    public function test_borrow_confirm_deducts_stock_and_records_balance(): void
    {
        $d = $this->seedData();

        $store = $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [
                ['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3],
                ['product_id' => $d['p2']->id, 'borrow_qty' => 5, 'unit_price' => 5],
            ],
        ]);
        $store->assertOk();
        $id = $store->json('data.id');

        $this->assertEquals(100, (int) DB::table('stocks')->where('product_id', $d['p1']->id)->where('warehouse_id', $d['warehouse']->id)->value('quantity'), '草稿不扣库存');

        $confirm = $this->postJson("/admin/business/borrow-order/{$id}/confirm");
        $confirm->assertOk();
        $this->assertEquals(90, (int) DB::table('stocks')->where('product_id', $d['p1']->id)->where('warehouse_id', $d['warehouse']->id)->value('quantity'), '确认借货应扣库存');
        $this->assertEquals(BorrowOrder::STATUS_UNRETURNED, $confirm->json('data.status'));

        $this->assertEquals(10, (int) DB::table('customer_borrow_balances')->where('product_id', $d['p1']->id)->where('customer_id', $d['customer']->id)->value('qty'));
    }

    public function test_return_approve_restores_stock_and_updates_status(): void
    {
        $d = $this->seedData();

        $borrow = $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3]],
        ])->json('data.id');
        $this->postJson("/admin/business/borrow-order/{$borrow}/confirm")->assertOk();

        $pending = $this->getJson("/admin/business/return-order/pending-borrow-items?borrow_order_id={$borrow}");
        $pending->assertOk();
        $boItemId = $pending->json('data.items.0.borrow_order_item_id');

        $return = $this->postJson('/admin/business/return-order', [
            'borrow_order_id' => $borrow,
            'items' => [
                ['borrow_order_item_id' => $boItemId, 'product_id' => $d['p1']->id, 'return_qty' => 4, 'good_qty' => 4, 'bad_qty' => 0],
            ],
        ]);
        $return->assertOk();
        $rid = $return->json('data.id');

        $approve = $this->postJson("/admin/business/return-order/{$rid}/approve");
        $approve->assertOk();
        $this->assertEquals(BorrowReturnOrder::STATUS_APPROVED, $approve->json('data.status'));

        $this->assertEquals(94, (int) DB::table('stocks')->where('product_id', $d['p1']->id)->where('warehouse_id', $d['warehouse']->id)->value('quantity'), '完好数量应回库(100-10+4)');
        $this->assertEquals(BorrowOrder::STATUS_PARTIAL, DB::table('borrow_orders')->where('id', $borrow)->value('status'), '部分还');
    }

    public function test_convert_creates_sales_order_and_increases_ar(): void
    {
        $d = $this->seedData();

        $borrow = $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3]],
        ])->json('data.id');
        $this->postJson("/admin/business/borrow-order/{$borrow}/confirm")->assertOk();

        $arBefore = (float) DB::table('customers')->where('id', $d['customer']->id)->value('balance');
        $convert = $this->postJson("/admin/business/borrow-order/{$borrow}/convert");
        $convert->assertOk();
        $this->assertEquals(BorrowOrder::STATUS_CONVERTED, $convert->json('data.status'));
        $this->assertSame(1, DB::table('sales_orders')->where('order_type', 'borrow_convert')->count());
        $this->assertEquals($arBefore + 30, (float) DB::table('customers')->where('id', $d['customer']->id)->value('balance'), '应收账款+30');
    }

    public function test_borrow_summary_endpoints(): void
    {
        $d = $this->seedData();
        $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3]],
        ]);
        $this->getJson('/admin/business/borrow-summary')->assertOk();
        $this->getJson('/admin/business/borrow-summary/customer-detail')->assertOk();
        $this->getJson('/admin/business/borrow-summary/trend')->assertOk();
    }
}
