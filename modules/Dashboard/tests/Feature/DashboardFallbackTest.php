<?php

namespace Tests\Dashboard\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

/**
 * 复现生产状态：brands 表空、products.brand_id 全空。
 *
 * 这是「仪表盘仍然显示硬编码演示数据」的根因诊断用例：
 * 前端在列表为空时回退到 widgets.js 的硬编码 props，所以只要 brandProportion
 * 返回空列表，图表就永远显示电子产品/服装那套假数据。
 */
class DashboardFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_state_brand_proportion_returns_empty_and_falls_back_to_demo(): void
    {
        $this->actingAsAdmin();

        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => '主仓', 'type' => 'normal', 'is_active' => true]);
        $customer = Customer::create(['code' => 'C1', 'name' => '客户', 'is_active' => true]);
        // 商品不挂 brand_id —— 生产现状
        $p = Product::create(['name' => '可乐', 'code' => 'P1', 'price_small' => 3, 'is_active' => true]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'XS1', 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(), 'total_qty' => 10, 'total_amount' => 100, 'status' => '已收款',
        ]);
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $orderId, 'product_id' => $p->id, 'quantity' => 10, 'price' => 10, 'amount' => 100,
        ]);

        // 与 categoryProportion 同口径：未挂品牌的销售归入「其他」，而不是返回空列表。
        // 这是前端能否脱离硬编码演示值的关键——空列表会让 computeWidgetProps 回退到
        // widgets.js 的默认 props（电子产品/服装那套）。
        $bp = $this->getJson('/admin/dashboard/brand-proportion');
        $this->assertNotEmpty($bp->json('data.list'), '无商品挂品牌时仍应返回「其他」，不能是空列表');
        $this->assertSame('其他', $bp->json('data.list.0.name'));
        $this->assertEquals(100.0, (float) $bp->json('data.list.0.value'));
        $this->assertEquals(100.0, (float) $bp->json('data.total'));

        $bt = $this->getJson('/admin/dashboard/brand-top10');
        $this->assertNotEmpty($bt->json('data.list'));
        $this->assertSame('其他', $bt->json('data.list.0.category'));
        $this->assertEquals(100.0, (float) $bt->json('data.list.0.value'));

        // 对照：既有 categoryProportion 用 LEFT JOIN + COALESCE，行为一致
        $cp = $this->getJson('/admin/dashboard/category-proportion');
        $this->assertSame('其他', $cp->json('data.list.0.name'));

        // 挂上品牌后必须按品牌聚合，不再是「其他」
        $brand = DB::table('brands')->insertGetId(['name' => '云南恒吉工贸', 'sort' => 0, 'is_active' => 1]);
        DB::table('products')->where('id', $p->id)->update(['brand_id' => $brand]);

        $bp2 = $this->getJson('/admin/dashboard/brand-proportion');
        $this->assertSame('云南恒吉工贸', $bp2->json('data.list.0.name'));
        $this->assertEquals(100.0, (float) $bp2->json('data.total'));
    }
}
