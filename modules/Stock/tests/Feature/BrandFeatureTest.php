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

    /**
     * sync_category=false：用户明确拒绝同步时，品牌改名不应连带改分类名。
     * 分类改名会影响该分类下所有商品的展示，影响面比品牌大，故给用户选择权。
     */
    public function test_update_can_decline_category_sync(): void
    {
        $category = ProductCategory::create(['name' => '原分类名', 'is_main' => true, 'is_active' => true]);
        $brand = Brand::create(['name' => '品牌A', 'category_id' => $category->id]);

        $this->putJson('/admin/business/brand/'.$brand->id, [
            'name' => '新品牌名',
            'sync_category' => false,
        ])->assertOk();

        $this->assertSame('新品牌名', $brand->fresh()->name, '品牌名本身应更新');
        $this->assertSame('原分类名', $category->fresh()->name, '拒绝同步时分类名应保持不变');
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
     * 查询区两个独立输入框走 name / code 参数（旧 keyword 参数仍兼容）。
     * 两个字段同时填是 OR 语义：填 A 品牌的名称 + B 品牌的编码，两者都能搜到。
     */
    public function test_list_filters_by_name_and_code_separately(): void
    {
        Brand::create(['name' => '农夫山泉', 'code' => 'BR0001']);
        Brand::create(['name' => '康师傅', 'code' => 'BR0002']);
        Brand::create(['name' => '统一', 'code' => 'BR0003']);

        $this->assertSame(1, $this->getJson('/admin/business/brand?name=农夫')->json('data.total'));
        $this->assertSame(1, $this->getJson('/admin/business/brand?code=BR0002')->json('data.total'));

        // name + code 同时填 = OR，两个品牌都命中
        $res = $this->getJson('/admin/business/brand?name=农夫&code=BR0002');
        $this->assertSame(2, $res->json('data.total'));

        // 无匹配
        $this->assertSame(0, $this->getJson('/admin/business/brand?name=不存在的品牌')->json('data.total'));
    }

    /**
     * 编码格式校验：必须是 BR + 恰好 4 位数字。
     * 过短、过长、非数字、小写、缺前缀一律 422——格式是报表对账的锚点，不能含糊。
     */
    public function test_code_must_match_br_plus_four_digits(): void
    {
        foreach (['BR12', 'BR123', 'BR12345', 'BR123A', 'br0001', 'R0001', 'BR-0001'] as $bad) {
            $this->postJson('/admin/business/brand', ['name' => '坏编码'.$bad, 'code' => $bad])
                ->assertStatus(422);
        }

        $this->postJson('/admin/business/brand', ['name' => '好编码', 'code' => 'BR0001'])->assertOk();
    }

    /**
     * 新增时不填编码 → 按关联分类自动生成 BR+4位分类ID。
     *
     * 数字是分类 ID 而非品牌 ID：BackfillBrandCategory 按 ^BR0*(\d+)$ 反解回填
     * category_id，若填成品牌自增 ID 会把品牌错链到另一个分类。这条测试守住约定。
     */
    public function test_code_auto_generated_from_linked_category_id(): void
    {
        $cat = ProductCategory::create(['name' => '饮料', 'is_main' => true, 'is_active' => true]);

        $res = $this->postJson('/admin/business/brand', ['name' => '自动生成编码'])->assertOk();
        // 未关联分类：编码留空，不硬造一个数字
        $this->assertNull($res->json('data.code'));

        // 关联分类后重新生成，数字必须是分类 ID 而非品牌自增 ID
        $res = $this->postJson('/admin/business/brand', [
            'name' => '关联分类',
            'category_id' => $cat->id,
        ])->assertOk();
        $expected = 'BR'.str_pad((string) $cat->id, 4, '0', STR_PAD_LEFT);
        $this->assertSame($expected, $res->json('data.code'));
        $this->assertNotEquals('BR'.str_pad((string) Brand::where('name', '关联分类')->first()->id, 4, '0', STR_PAD_LEFT), $res->json('data.code'), '编码里的数字不应是品牌 ID');
    }

    /**
     * 编辑时不提交编码：保留原值，不触发自动生成。
     *
     * 前端改单个字段时通常只带变更项，若后端把「没带」当成「要生成」，
     * 用户手填的编码会被静默覆盖。
     */
    public function test_update_without_code_keeps_existing_code(): void
    {
        $this->postJson('/admin/business/brand', ['name' => '原品牌', 'code' => 'BR9999'])->assertOk();
        $brand = Brand::where('code', 'BR9999')->first();

        // 只改名称和排序，不带 code
        $this->putJson('/admin/business/brand/'.$brand->id, ['name' => '新名称', 'sort' => 5])->assertOk();
        $this->assertSame('BR9999', $brand->fresh()->code, '未提交编码时应保留原编码');
        $this->assertSame(5, $brand->fresh()->sort);
    }

    /**
     * 品牌 ↔ 一级分类按设计一对一：分类已关联其他品牌时拒绝。
     * 编辑自身所属分类不算冲突（自己改自己不该报错）。
     */
    public function test_category_cannot_be_linked_to_two_brands(): void
    {
        $cat = ProductCategory::create(['name' => '饮料', 'is_main' => true, 'is_active' => true]);
        $this->postJson('/admin/business/brand', ['name' => '品牌甲', 'category_id' => $cat->id])->assertOk();

        $res = $this->postJson('/admin/business/brand', ['name' => '品牌乙', 'category_id' => $cat->id]);
        $res->assertStatus(422);
        $this->assertStringContainsString('已关联', $res->json('message'));

        // 编辑品牌甲、保持它自己的分类：不算冲突
        $brand = Brand::where('category_id', $cat->id)->first();
        $this->putJson('/admin/business/brand/'.$brand->id, ['name' => '品牌甲改名', 'category_id' => $cat->id])->assertOk();
    }

    /**
     * 导出 CSV：带 BOM、含表头与中文标题行，列数与页面列一致。
     * 走 blob 不受 JSON 拦截器影响，故直接用 json() 之外的断言方式。
     */
    public function test_export_returns_csv_with_bom(): void
    {
        $cat = ProductCategory::create(['name' => '饮料', 'is_main' => true, 'is_active' => true]);
        $brand = Brand::create(['name' => '导出品牌', 'code' => 'BR0001', 'category_id' => $cat->id, 'sort' => 3]);
        Product::create(['name' => '可乐', 'code' => 'P1', 'price_small' => 3, 'is_active' => true, 'brand_id' => $brand->id]);

        $res = $this->getJson('/admin/business/brand/export');
        $res->assertOk();

        $this->assertStringContainsString('attachment; filename="brands.csv"', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $body = $res->getContent();
        $this->assertStringStartsWith("\u{FEFF}", $body, '必须以 UTF-8 BOM 开头，否则 Excel 打开乱码');
        $this->assertStringContainsString('品牌编码,品牌名称,关联分类,商品数量,排序,状态,创建时间', $body);
        $this->assertStringContainsString('BR0001,导出品牌,饮料,1,3,启用', $body);
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
