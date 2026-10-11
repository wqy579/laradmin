<?php

namespace Tests\Stock\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stock\Models\Brand;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\ProductCategory;
use Tests\TestCase;

/**
 * 品牌档案：CRUD + 软删保护 + 批量启停 + 编码唯一。
 *
 * 这批测试同时守住两件事：
 *  - RefreshDatabase 会重放全量迁移，2026_10_11 的新增列在 sqlite 上必须能建出来；
 *  - Product 加了 $with = ['brand']，任何走 Product::create / 列表查询的既有路径
 *    都会触发 brand() 关系，若 brands 表缺列或关系写错，现有测试也会在这里炸。
 */
class BrandFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_create_and_list_brand_with_product_count(): void
    {
        $this->postJson('/admin/business/brand', [
            'name' => '云南恒吉', 'code' => 'BR0012', 'sort' => 1,
        ])->assertOk();

        $brand = Brand::first();
        Product::create(['name' => '脉动', 'code' => 'P1', 'price_small' => 10, 'is_active' => true, 'brand_id' => $brand->id]);
        Product::create(['name' => '水', 'code' => 'P2', 'price_small' => 2, 'is_active' => true, 'brand_id' => $brand->id]);

        $res = $this->getJson('/admin/business/brand');
        $res->assertOk();
        $this->assertSame(1, $res->json('data.total'));
        $this->assertSame(2, $res->json('data.list.0.product_count'));
        $this->assertNull($res->json('data.list.0.category'));
    }

    public function test_code_must_be_unique(): void
    {
        $this->postJson('/admin/business/brand', ['name' => 'A', 'code' => 'BR0001'])->assertOk();
        $this->postJson('/admin/business/brand', ['name' => 'B', 'code' => 'BR0001'])->assertStatus(422);

        // 把第二个品牌创建出来，再把它的编码改成和第一个品牌重复，必须拦下
        $this->postJson('/admin/business/brand', ['name' => 'B', 'code' => 'BR0002'])->assertOk();
        $b = Brand::where('code', 'BR0002')->first();
        $this->putJson('/admin/business/brand/'.$b->id, ['name' => 'B改', 'code' => 'BR0001'])->assertStatus(422);

        // 用自己的编码编辑自己，不应误报重复
        $a = Brand::where('code', 'BR0001')->first();
        $this->putJson('/admin/business/brand/'.$a->id, ['name' => 'A改', 'code' => 'BR0001'])->assertOk();
    }

    public function test_options_only_returns_active_brands(): void
    {
        $this->postJson('/admin/business/brand', ['name' => '启用品牌'])->assertOk();
        $off = $this->postJson('/admin/business/brand', ['name' => '停用品牌', 'is_active' => false]);
        $this->assertTrue($off->json('data.is_active') === false);

        $res = $this->getJson('/admin/business/brand/options')->assertOk();
        $this->assertCount(1, $res->json('data.list'));
        $this->assertSame('启用品牌', $res->json('data.list.0.name'));
    }

    public function test_delete_blocked_when_products_attached(): void
    {
        $brand = Brand::create(['name' => '有商品的品牌']);
        Product::create(['name' => '商品', 'code' => 'P1', 'price_small' => 5, 'is_active' => true, 'brand_id' => $brand->id]);

        $this->deleteJson('/admin/business/brand/'.$brand->id)->assertStatus(422);
        $this->assertNotNull($brand->fresh());
    }

    public function test_delete_soft_removes_brand_without_products(): void
    {
        $brand = Brand::create(['name' => '空品牌', 'category_id' => null]);

        $this->deleteJson('/admin/business/brand/'.$brand->id)->assertOk();

        // 软删：普通查询不可见，withTrashed 可见。options() 必须排除它。
        $this->assertNull(Brand::find($brand->id));
        $this->assertNotNull(Brand::withTrashed()->find($brand->id));
        $this->assertCount(0, $this->getJson('/admin/business/brand/options')->json('data.list'));
    }

    public function test_batch_update_status(): void
    {
        $a = Brand::create(['name' => '甲']);
        $b = Brand::create(['name' => '乙']);
        Brand::create(['name' => '丙', 'is_active' => false]);

        $this->postJson('/admin/business/brand/batch-status', [
            'ids' => [$a->id, $b->id], 'is_active' => false,
        ])->assertOk();

        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);

        // options() 只回启用品牌，被停用的两个都该消失
        $this->assertCount(0, $this->getJson('/admin/business/brand/options')->json('data.list'));
    }

    public function test_update_syncs_linked_category_name(): void
    {
        // 一级分类（is_main=true）
        $category = ProductCategory::create(['name' => '原分类名', 'is_main' => true, 'is_active' => true]);
        $brand = Brand::create(['name' => '品牌A', 'category_id' => $category->id]);

        $this->putJson('/admin/business/brand/'.$brand->id, ['name' => '新品牌名'])->assertOk();

        // 品牌改名的同时同步关联分类名，保持「品牌=一级分类」展示一致
        $this->assertSame('新品牌名', $category->fresh()->name);
    }

    public function test_list_filters_by_keyword_and_status(): void
    {
        Brand::create(['name' => '农夫山泉', 'is_active' => true]);
        Brand::create(['name' => '康师傅', 'is_active' => false]);

        $this->assertSame(1, $this->getJson('/admin/business/brand?keyword=农夫')->json('data.total'));
        $this->assertSame(1, $this->getJson('/admin/business/brand?is_active=1')->json('data.total'));
        $this->assertSame(1, $this->getJson('/admin/business/brand?is_active=0')->json('data.total'));
    }

    /**
     * 回填命令：brands.code(BR+分类ID) → category_id。
     * 守住匹配规则与幂等性——已回填的不再动，code 不成规则的跳过。
     */
    public function test_backfill_command_parses_code_and_fills_category(): void
    {
        $cat = ProductCategory::create(['name' => '饮料', 'is_main' => true, 'is_active' => true]);
        $branded = Brand::create(['name' => '有规则编码', 'code' => sprintf('BR%04d', $cat->id)]);
        // 已回填的品牌：用一个确实存在的分类 ID，但 code 不同，避免和上面那条撞唯一键
        $alreadyCat = ProductCategory::create(['name' => '副食', 'is_main' => true, 'is_active' => true]);
        $filled = Brand::create(['name' => '已回填', 'code' => sprintf('BR%04d', $alreadyCat->id), 'category_id' => $alreadyCat->id]);
        $unparsable = Brand::create(['name' => '无规则编码', 'code' => 'XYZ']);
        $missing = Brand::create(['name' => '指向不存在分类', 'code' => 'BR9999']);

        $this->artisan('brand:backfill-category')->assertSuccessful();

        $this->assertSame($cat->id, $branded->fresh()->category_id, 'BR+分类ID 编码必须被解析回填');
        $this->assertSame($alreadyCat->id, $filled->fresh()->category_id, '已回填的不能被改写');
        $this->assertNull($unparsable->fresh()->category_id, '不成规则的 code 跳过');
        $this->assertNull($missing->fresh()->category_id, '指向不存在分类的跳过');

        // 幂等：重跑一遍结果不变
        $this->artisan('brand:backfill-category')->assertSuccessful();
        $this->assertSame($cat->id, $branded->fresh()->category_id);
    }
}
