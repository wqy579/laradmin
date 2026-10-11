<?php

namespace Tests\Stock\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stock\Models\Brand;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

/**
 * 库存查询按品牌筛选。
 *
 * StockService::query 的 brand_id 过滤走 whereHas('product')，是新增逻辑，
 * 这里守住：选了品牌只能看到该品牌商品的库存，其他品牌商品不混入。
 */
class StockBrandFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_filter_stock_by_brand(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => '主仓库', 'type' => 'normal', 'is_active' => true]);
        $brandA = Brand::create(['name' => '品牌A', 'code' => 'BR0001']);
        $brandB = Brand::create(['name' => '品牌B', 'code' => 'BR0002']);

        $pA = Product::create(['name' => '可乐', 'code' => 'PA', 'price_small' => 3, 'is_active' => true, 'brand_id' => $brandA->id]);
        $pB = Product::create(['name' => '雪碧', 'code' => 'PB', 'price_small' => 3, 'is_active' => true, 'brand_id' => $brandB->id]);

        Stock::create(['product_id' => $pA->id, 'warehouse_id' => $warehouse->id, 'quantity' => 50, 'cost_price' => 2]);
        Stock::create(['product_id' => $pB->id, 'warehouse_id' => $warehouse->id, 'quantity' => 30, 'cost_price' => 2]);

        // 不带品牌筛选：两条库存都返回
        $all = $this->getJson('/admin/business/stock')->assertOk();
        $this->assertSame(2, $all->json('data.total'));

        // 按品牌A筛选：只返回可乐那条
        $filtered = $this->getJson('/admin/business/stock?brand_id='.$brandA->id)->assertOk();
        $this->assertSame(1, $filtered->json('data.total'));
        $this->assertSame('可乐', $filtered->json('data.data.0.product.name'));
    }
}
