<?php

namespace Tests\Stock\Feature;

use Modules\Stock\Models\Product;
use Modules\Stock\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T4 商品与分类 Feature 测试
 *
 * 覆盖：商品 CRUD、各筛选参数（keyword/main_category_id/sub_category_id/is_online/is_active/分页）、
 * 分类树接口的商品计数（与逐分类 COUNT 的原始 SQL 基准比对，守住 N+1 修复成果）、
 * 分类删除后的商品归属清理（destroyCategory）。
 */
class ProductCategoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- 商品列表

    public function test_product_index_returns_full_envelope(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/admin/business/product');

        $response->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => ['list', 'total', 'page', 'page_size', 'last_page', 'mainCategories', 'subCategories'],
            ]);
    }

    public function test_product_index_keyword_filter(): void
    {
        $this->actingAsAdmin();

        $this->makeProduct(['name' => '矿泉水 550ml', 'code' => 'P001', 'barcode_small' => '6900000000017', 'spec' => '550ml/瓶']);
        $this->makeProduct(['name' => '可乐 330ml', 'code' => 'P002']);
        $this->makeProduct(['name' => '巧克力饼干', 'code' => 'P003']);

        // 名称
        $this->getJson('/admin/business/product?keyword=矿泉水')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.code', 'P001');

        // 编码
        $this->getJson('/admin/business/product?keyword=P003')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.name', '巧克力饼干');

        // 小码条码
        $this->getJson('/admin/business/product?keyword=6900000000017')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        // 规格
        $this->getJson('/admin/business/product?keyword=550ml')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        // 无命中
        $this->getJson('/admin/business/product?keyword=不存在的关键词xyz')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.list', []);
    }

    public function test_product_index_category_filters(): void
    {
        $this->actingAsAdmin();

        $main1 = $this->makeCategory(['name' => '饮料', 'is_main' => true]);
        $main2 = $this->makeCategory(['name' => '食品', 'is_main' => true]);
        $sub1 = $this->makeCategory(['name' => '水', 'parent_id' => $main1->id]);

        $this->makeProduct(['name' => 'A', 'main_category_id' => $main1->id, 'sub_category_id' => $sub1->id]);
        $this->makeProduct(['name' => 'B', 'main_category_id' => $main1->id]);
        $this->makeProduct(['name' => 'C', 'main_category_id' => $main2->id]);
        $this->makeProduct(['name' => 'D']);

        $this->getJson("/admin/business/product?main_category_id={$main1->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson("/admin/business/product?sub_category_id={$sub1->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.name', 'A');

        // 组合过滤：主分类 + 子分类
        $this->getJson("/admin/business/product?main_category_id={$main1->id}&sub_category_id={$sub1->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        // 列表项应携带分类名称（冗余展示字段）
        $this->getJson("/admin/business/product?sub_category_id={$sub1->id}")
            ->assertOk()
            ->assertJsonPath('data.list.0.main_category_name', $main1->name)
            ->assertJsonPath('data.list.0.sub_category_name', $sub1->name);
    }

    public function test_product_index_status_filters(): void
    {
        $this->actingAsAdmin();

        $this->makeProduct(['name' => '在线-启用', 'is_online' => true, 'is_active' => true]);
        $this->makeProduct(['name' => '在线-停用', 'is_online' => true, 'is_active' => false]);
        $this->makeProduct(['name' => '下线-启用', 'is_online' => false, 'is_active' => true]);
        $this->makeProduct(['name' => '下线-停用', 'is_online' => false, 'is_active' => false]);

        $this->getJson('/admin/business/product?is_online=1')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/admin/business/product?is_online=0')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/admin/business/product?is_active=0')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/admin/business/product?is_active=1&is_online=0')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.name', '下线-启用');
    }

    public function test_product_index_pagination(): void
    {
        $this->actingAsAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->makeProduct(['name' => "商品{$i}"]);
        }

        $response = $this->getJson('/admin/business/product?page=2&page_size=2');

        $response->assertOk()
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.page', 2)
            ->assertJsonPath('data.page_size', 2)
            ->assertJsonPath('data.last_page', 3);
        $this->assertCount(2, $response->json('data.list'));

        // 默认排序 id desc：第 2 页包含第 3、4 新的商品
        $names = array_column($response->json('data.list'), 'name');
        $this->assertContains('商品2', $names);
        $this->assertContains('商品1', $names);
    }

    // ---------------------------------------------------------------- 商品 CRUD

    public function test_store_product_creates_and_validates(): void
    {
        $this->actingAsAdmin();
        $category = $this->makeCategory(['name' => '饮料', 'is_main' => true]);

        // 缺少 name → 422
        $this->postJson('/admin/business/product', [])
            ->assertStatus(422);

        // 不存在的分类 → 422
        $this->postJson('/admin/business/product', [
            'name' => 'X',
            'main_category_id' => 999999,
        ])->assertStatus(422);

        // 正常创建
        $response = $this->postJson('/admin/business/product', [
            'name' => '椰子水 1L',
            'code' => 'P-COCO',
            'main_category_id' => $category->id,
            'price_large' => 9.9,
            'price_unit' => '箱',
            'is_online' => true,
        ]);

        $response->assertOk()->assertJsonPath('code', 200);

        $this->assertDatabaseHas('products', [
            'name' => '椰子水 1L',
            'code' => 'P-COCO',
            'main_category_id' => $category->id,
        ]);
    }

    public function test_update_product(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct(['name' => '旧名字', 'price_large' => 1]);

        $response = $this->putJson("/admin/business/product/{$product->id}", [
            'name' => '新名字',
            'price_large' => 12.5,
        ]);

        $response->assertOk()->assertJsonPath('code', 200);

        $product->refresh();
        $this->assertSame('新名字', $product->name);
        $this->assertSame('12.50', (string) $product->price_large);
    }

    public function test_destroy_and_batch_delete_product(): void
    {
        $this->actingAsAdmin();

        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $p3 = $this->makeProduct();

        $this->deleteJson("/admin/business/product/{$p1->id}")
            ->assertOk()
            ->assertJsonPath('code', 200);
        $this->assertNull(Product::find($p1->id));

        $this->postJson('/admin/business/product/batch-delete', ['ids' => [$p2->id, $p3->id]])
            ->assertOk()
            ->assertJsonPath('code', 200);
        $this->assertSame(0, Product::count());
    }

    public function test_batch_update_status(): void
    {
        $this->actingAsAdmin();

        $p1 = $this->makeProduct(['is_active' => true]);
        $p2 = $this->makeProduct(['is_active' => true]);

        $this->postJson('/admin/business/product/batch-status', [
            'ids' => [$p1->id, $p2->id],
            'is_active' => false,
        ])->assertOk();

        $this->assertFalse((bool) $p1->fresh()->is_active);
        $this->assertFalse((bool) $p2->fresh()->is_active);
    }

    // ---------------------------------------------------------------- 分类树与计数

    public function test_categories_tree_counts_match_raw_sql_baseline(): void
    {
        $this->actingAsAdmin();

        $main1 = $this->makeCategory(['name' => '饮料', 'is_main' => true]);
        $main2 = $this->makeCategory(['name' => '食品', 'is_main' => true]);
        $main3 = $this->makeCategory(['name' => '停用主分类', 'is_main' => true, 'is_active' => false]);
        $sub1 = $this->makeCategory(['name' => '水', 'parent_id' => $main1->id]);
        $sub2 = $this->makeCategory(['name' => '茶', 'parent_id' => $main1->id]);
        $sub3 = $this->makeCategory(['name' => '停用子分类', 'parent_id' => $main1->id, 'is_active' => false]);
        $sub4 = $this->makeCategory(['name' => '零食', 'parent_id' => $main2->id]);

        $p1 = $this->makeProduct(['main_category_id' => $main1->id, 'sub_category_id' => $sub1->id]); // 活跃
        $p2 = $this->makeProduct(['main_category_id' => $main1->id, 'sub_category_id' => $sub1->id]); // 活跃
        $this->makeProduct(['main_category_id' => $main1->id, 'sub_category_id' => $sub2->id, 'is_active' => false]); // 停用不计
        $p4 = $this->makeProduct(['main_category_id' => $main2->id, 'sub_category_id' => $sub4->id]);
        $this->makeProduct(['main_category_id' => $main1->id, 'sub_category_id' => null]); // 活跃、无子分类
        $p6 = $this->makeProduct(['main_category_id' => $main3->id, 'sub_category_id' => $sub1->id]); // 主分类停用，但子分类计数仍含它

        $response = $this->getJson('/admin/business/product/categories');

        $response->assertOk()->assertJsonPath('code', 200);
        $data = $response->json('data');

        // 停用分类不出现在树里
        $ids = array_column($data, 'id');
        $this->assertNotContains($main3->id, $ids);
        $this->assertContains($main1->id, $ids);
        $this->assertContains($main2->id, $ids);

        $main1Node = collect($data)->firstWhere('id', $main1->id);
        $main2Node = collect($data)->firstWhere('id', $main2->id);

        // ---- 与原始 SQL 基准比对：逐分类 COUNT（修复 N+1 前的实现方式）----
        $expectedMain1 = Product::where('main_category_id', $main1->id)->where('is_active', true)->count();
        $expectedMain2 = Product::where('main_category_id', $main2->id)->where('is_active', true)->count();

        $this->assertSame($expectedMain1, $main1Node['product_count'], '主分类计数与基准 SQL 不一致');
        $this->assertSame($expectedMain2, $main2Node['product_count'], '主分类计数与基准 SQL 不一致');
        $this->assertSame(3, $main1Node['product_count']); // p1, p2, p5
        $this->assertSame(1, $main2Node['product_count']); // p4

        $sub1Count = collect($main1Node['children'])->firstWhere('id', $sub1->id)['product_count'];
        $sub2Count = collect($main1Node['children'])->firstWhere('id', $sub2->id)['product_count'];
        $sub4Count = collect($main2Node['children'])->firstWhere('id', $sub4->id)['product_count'];

        $expectedSub1 = Product::where('sub_category_id', $sub1->id)->where('is_active', true)->count();
        $expectedSub2 = Product::where('sub_category_id', $sub2->id)->where('is_active', true)->count();
        $expectedSub4 = Product::where('sub_category_id', $sub4->id)->where('is_active', true)->count();

        $this->assertSame($expectedSub1, $sub1Count);
        $this->assertSame($expectedSub2, $sub2Count);
        $this->assertSame($expectedSub4, $sub4Count);
        $this->assertSame(3, $sub1Count); // p1, p2, p6（主分类停用的 p6 也计入子分类）
        $this->assertSame(0, $sub2Count); // 只有停用商品
        $this->assertSame(1, $sub4Count);

        // 停用子分类不出现
        $sub1SiblingIds = array_column($main1Node['children'], 'id');
        $this->assertNotContains($sub3->id, $sub1SiblingIds);

        // 基准总量守恒：响应中所有计数之和 == 活跃且有归属分类的商品数（按归属口径）
        $activeCount = Product::where('is_active', true)->count();
        $this->assertEqualsWithDelta(
            $expectedMain1 + $expectedMain2,
            $main1Node['product_count'] + $main2Node['product_count'],
            1,
            '主分类计数总和应与活跃商品数基本守恒（p6 归属停用主分类，允许差 1）'
        );
        unset($activeCount);
    }

    // ---------------------------------------------------------------- 分类 CRUD 与删除清理

    public function test_store_and_update_category_validates(): void
    {
        $this->actingAsAdmin();

        // name / is_main 必填
        $this->postJson('/admin/business/product/categories', [])
            ->assertStatus(422);
        $this->postJson('/admin/business/product/categories', ['name' => '只有名字'])
            ->assertStatus(422);

        $response = $this->postJson('/admin/business/product/categories', [
            'name' => '新建主分类',
            'is_main' => true,
        ]);
        $response->assertOk()->assertJsonPath('code', 200);

        $category = ProductCategory::where('name', '新建主分类')->first();
        $this->assertNotNull($category);
        $this->assertTrue((bool) $category->is_main);

        $this->putJson("/admin/business/product/categories/{$category->id}", [
            'name' => '改名后的分类',
            'is_main' => true,
            'sort_order' => 5,
        ])->assertOk();

        $this->assertSame('改名后的分类', $category->fresh()->name);
        $this->assertSame(5, (int) $category->fresh()->sort_order);
    }

    public function test_destroy_sub_category_detaches_products_only(): void
    {
        $this->actingAsAdmin();

        $main = $this->makeCategory(['is_main' => true]);
        $sub = $this->makeCategory(['parent_id' => $main->id]);
        $product = $this->makeProduct(['main_category_id' => $main->id, 'sub_category_id' => $sub->id]);

        $response = $this->deleteJson("/admin/business/product/categories/{$sub->id}");
        $response->assertOk()->assertJsonPath('code', 200);

        $this->assertNull(ProductCategory::find($sub->id), '子分类应被删除');
        $this->assertNotNull(Product::find($product->id), '商品不应被删除');
        $this->assertNull($product->fresh()->sub_category_id, '商品的 sub_category_id 应被清空');
        $this->assertSame($main->id, $product->fresh()->main_category_id, '主分类归属应保留');
        $this->assertNotNull(ProductCategory::find($main->id), '父分类不应被误删');
    }

    public function test_destroy_main_category_detaches_products_and_removes_children(): void
    {
        $this->actingAsAdmin();

        $main1 = $this->makeCategory(['is_main' => true]);
        $main2 = $this->makeCategory(['is_main' => true]);
        $child1 = $this->makeCategory(['parent_id' => $main1->id]);
        $child2 = $this->makeCategory(['parent_id' => $main1->id]);
        $otherChild = $this->makeCategory(['parent_id' => $main2->id]);

        $pMainOnly = $this->makeProduct(['main_category_id' => $main1->id, 'sub_category_id' => null]);
        $pWithChild = $this->makeProduct(['main_category_id' => $main1->id, 'sub_category_id' => $child1->id]);
        $pChildOnly = $this->makeProduct(['main_category_id' => null, 'sub_category_id' => $child2->id]);
        $untouched = $this->makeProduct(['main_category_id' => $main2->id, 'sub_category_id' => $otherChild->id]);

        $response = $this->deleteJson("/admin/business/product/categories/{$main1->id}");
        $response->assertOk()->assertJsonPath('code', 200);

        // 主分类与其子分类一并删除
        $this->assertNull(ProductCategory::find($main1->id));
        $this->assertNull(ProductCategory::find($child1->id));
        $this->assertNull(ProductCategory::find($child2->id));

        // 商品全部保留，归属被清空
        $this->assertNull($pMainOnly->fresh()->main_category_id);
        $this->assertNull($pWithChild->fresh()->main_category_id);
        $this->assertNull($pWithChild->fresh()->sub_category_id);
        $this->assertNull($pChildOnly->fresh()->sub_category_id);
        $this->assertNotNull(Product::find($pMainOnly->id));
        $this->assertNotNull(Product::find($pWithChild->id));
        $this->assertNotNull(Product::find($pChildOnly->id));

        // 其它主分类体系不受影响
        $this->assertNotNull(ProductCategory::find($main2->id));
        $this->assertNotNull(ProductCategory::find($otherChild->id));
        $this->assertSame($main2->id, $untouched->fresh()->main_category_id);
        $this->assertSame($otherChild->id, $untouched->fresh()->sub_category_id);
    }
}
