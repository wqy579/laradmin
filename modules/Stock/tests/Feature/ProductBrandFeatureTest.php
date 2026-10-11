<?php

namespace Tests\Stock\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stock\Models\Brand;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\ProductCategory;
use Tests\TestCase;

/**
 * 商品挂品牌（brand_id）的端到端校验。
 *
 * 守住的是一条真实踩过的坑：ProductController::store/update 的 validate() 规则
 * 必须显式声明 brand_id，否则 Laravel 只返回规则里声明的字段，前端提交的品牌会被
 * 静默丢弃——表现为「品牌和商品筛选都做了，但商品永远挂不上品牌」，进而让
 * 仪表盘的 brandProportion 恒为空列表、回退到硬编码演示数据。
 * 仅用 Product::create() 建模型的测试抓不到这个，必须走 HTTP。
 */
class ProductBrandFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_store_product_persists_brand_id(): void
    {
        $category = ProductCategory::create(['name' => '饮料', 'is_main' => true, 'is_active' => true]);
        $brand = Brand::create(['name' => '云南恒吉工贸', 'code' => 'BR0001', 'is_active' => true]);

        $res = $this->postJson('/admin/business/product', [
            'name' => '可乐 500ml',
            'code' => 'P-COLA',
            'main_category_id' => $category->id,
            'brand_id' => $brand->id,
            'price_small' => 3,
            'price_unit_small' => '瓶',
        ])->assertOk();

        // 关键断言：brand_id 必须真的落库，而不是被 validate 丢掉
        $this->assertSame($brand->id, (int) Product::first()->brand_id, 'brand_id 必须持久化，不能被 validate 静默丢弃');
        // 列表接口的 brand 关系应带上品牌名
        $list = $this->getJson('/admin/business/product')->assertOk();
        $this->assertSame('云南恒吉工贸', $list->json('data.list.0.brand.name'));
    }

    public function test_update_product_persists_brand_id(): void
    {
        $category = ProductCategory::create(['name' => '饮料', 'is_main' => true, 'is_active' => true]);
        $brand = Brand::create(['name' => '康师傅食品', 'code' => 'BR0002', 'is_active' => true]);
        $product = Product::create(['name' => '矿泉水', 'code' => 'P-W', 'price_small' => 2, 'is_active' => true, 'main_category_id' => $category->id]);

        $this->putJson('/admin/business/product/'.$product->id, [
            'name' => '矿泉水',
            'brand_id' => $brand->id,
        ])->assertOk();

        $this->assertSame($brand->id, (int) $product->fresh()->brand_id, 'update 也必须持久化 brand_id');
    }

    public function test_brand_id_must_exist(): void
    {
        $category = ProductCategory::create(['name' => '饮料', 'is_main' => true, 'is_active' => true]);

        // 不存在的品牌 → 422（exists 规则生效）
        $this->postJson('/admin/business/product', [
            'name' => 'X',
            'main_category_id' => $category->id,
            'brand_id' => 999999,
        ])->assertStatus(422);

        // 未填品牌 → 允许（品牌是可选维度）
        $this->postJson('/admin/business/product', [
            'name' => '无品牌商品',
            'main_category_id' => $category->id,
            'price_small' => 1,
        ])->assertOk();
        $this->assertNull(Product::first()->brand_id);
    }
}
