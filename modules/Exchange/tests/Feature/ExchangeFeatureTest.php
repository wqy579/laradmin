<?php

namespace Tests\Exchange\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Exchange\Models\ExchangeOrder;
use Modules\Order\Models\Customer;
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

        // 换出 薯片(5) 换入 可乐(3)：差价 = 3*3 - 5*5 = 9 - 25 = -16（退给客户）
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
