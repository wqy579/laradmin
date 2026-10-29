<?php

namespace Tests\Archive\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Archive\Models\Company;
use Tests\TestCase;

/**
 * F6 块1：公司档案。
 *
 * 验收点：
 *  - 建单 → 查询 → 编辑 → 删除全链路（删除是软删，档案可追溯）
 *  - 编码唯一；留空归一成 null 而不是 ''（'' 会撞唯一索引）
 *  - 默认公司全库唯一，且默认公司不可删
 *  - 名下有事业部不可删（否则事业部成孤儿档案）
 *  - options 只给启用中的公司，供事业部表单下拉
 *  - 导出 CSV 带 BOM
 */
class CompanyFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '档案管理员']);
    }

    public function test_menu_migrations_registered_both_pages_under_data_parent(): void
    {
        $this->assertDatabaseHas('auth_permission', [
            'name' => 'data.company',
            'path' => '/data/company',
            'component' => 'data/company/index',
        ]);
        $this->assertDatabaseHas('auth_permission', [
            'name' => 'data.division',
            'path' => '/data/division',
            'component' => 'data/division/index',
        ]);
        // 前端 v-permission 用冒号形式，两种写法都要登记
        $this->assertDatabaseHas('auth_permission', ['name' => 'data:company:create']);
        $this->assertDatabaseHas('auth_permission', ['name' => 'data:division:export']);
    }

    public function test_store_then_query_then_update_then_soft_delete(): void
    {
        $resp = $this->postJson('/admin/data/company', $this->payload(['code' => 'GS01']))
            ->assertOk()
            ->assertJsonPath('message', '已创建')
            ->assertJsonPath('data.code', 'GS01')
            ->assertJsonPath('data.status', 'enabled');

        $id = $resp->json('data.id');
        $this->assertNotEmpty($id);

        $this->getJson('/admin/data/company?name=连凯&code=GS01')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $id)
            ->assertJsonPath('data.list.0.division_count', 0);

        $this->putJson("/admin/data/company/{$id}", ['name' => '连凯商业改名', 'short_name' => '连凯商业'])
            ->assertOk()
            ->assertJsonPath('data.name', '连凯商业改名')
            // 未提交 code 时保留原编码
            ->assertJsonPath('data.code', 'GS01');

        $this->deleteJson("/admin/data/company/{$id}")->assertOk()->assertJsonPath('message', '已删除');

        $this->assertSoftDeleted('archive_companies', ['id' => $id]);
        // 软删后列表与详情都查不到
        $this->getJson('/admin/data/company')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson("/admin/data/company/{$id}")->assertStatus(404);
    }

    public function test_code_is_unique_and_blank_code_becomes_null(): void
    {
        $this->postJson('/admin/data/company', $this->payload(['code' => 'GS01']))->assertOk();

        $this->postJson('/admin/data/company', $this->payload(['code' => 'GS01', 'name' => '另一家公司']))
            ->assertStatus(422)
            ->assertJsonPath('message', '公司编码已存在');

        // 编码可选：留空必须落 null，落 '' 会与另一行 '' 争唯一索引（且导出成空列）
        $first = $this->postJson('/admin/data/company', $this->payload(['code' => '']))->assertOk()->json('data.id');
        $second = $this->postJson('/admin/data/company', $this->payload(['name' => '无编码公司']))->assertOk()->json('data.id');

        $this->assertNull(Company::find($first)->code);
        $this->assertNull(Company::find($second)->code);
        $this->assertSame(2, Company::whereNull('code')->count());
    }

    public function test_name_is_required(): void
    {
        $this->postJson('/admin/data/company', ['code' => 'GS09'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_default_company_is_single_and_cannot_be_deleted(): void
    {
        $a = $this->postJson('/admin/data/company', $this->payload(['code' => 'GS01', 'is_default' => true]))
            ->assertOk()->json('data.id');
        $this->assertTrue(Company::find($a)->is_default);

        $b = $this->postJson('/admin/data/company', $this->payload(['code' => 'GS02', 'is_default' => true]))
            ->assertOk()->json('data.id');

        // 设新默认要自动摘掉旧默认，否则开账/取主体时会二义
        $this->assertTrue(Company::find($b)->is_default);
        $this->assertFalse(Company::find($a)->is_default);
        $this->assertSame(1, Company::where('is_default', true)->count());

        $this->deleteJson("/admin/data/company/{$b}")->assertStatus(422)->assertJsonPath('message', '默认公司不可删除，请先指定其他公司为默认');
        $this->assertDatabaseHas('archive_companies', ['id' => $b, 'deleted_at' => null]);
    }

    public function test_company_with_divisions_cannot_be_deleted(): void
    {
        $companyId = $this->postJson('/admin/data/company', $this->payload(['code' => 'GS01']))->assertOk()->json('data.id');
        $this->postJson('/admin/data/division', ['company_id' => $companyId, 'name' => '华南快消事业部', 'code' => 'SYB01'])
            ->assertOk();

        $this->getJson('/admin/data/company')
            ->assertOk()
            // 事业部数走 GROUP BY 附在列表行上，不能是逐行 count
            ->assertJsonPath('data.list.0.division_count', 1);

        $this->deleteJson("/admin/data/company/{$companyId}")
            ->assertStatus(422)
            ->assertJsonPath('message', '该公司下还有 1 个事业部，请先迁移或删除事业部');

        // 详情带上名下事业部，便于就地处理
        $this->getJson("/admin/data/company/{$companyId}")
            ->assertOk()
            ->assertJsonPath('data.division_count', 1)
            ->assertJsonPath('data.divisions.0.code', 'SYB01');
    }

    public function test_options_only_return_enabled_companies(): void
    {
        $this->postJson('/admin/data/company', $this->payload(['code' => 'GS01']))->assertOk();
        $disabledId = $this->postJson('/admin/data/company', $this->payload(['code' => 'GS02', 'name' => '停用公司']))
            ->assertOk()->json('data.id');
        $this->postJson('/admin/data/company/batch-status', ['ids' => [$disabledId], 'status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);
        $this->assertSame('disabled', Company::find($disabledId)->status);

        $resp = $this->getJson('/admin/data/company/options')->assertOk();
        $ids = collect($resp->json('data.list'))->pluck('id')->all();

        $this->assertNotContains($disabledId, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_status_filter_and_export_csv_carries_bom(): void
    {
        $id = $this->postJson('/admin/data/company', $this->payload(['code' => 'GS01']))->assertOk()->json('data.id');

        $this->getJson('/admin/data/company?status=disabled')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/admin/data/company?status=enabled')->assertOk()->assertJsonPath('data.total', 1);

        $resp = $this->get('/admin/data/company/export');
        $resp->assertOk();
        $this->assertStringContainsString('text/csv', $resp->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="companies.csv"', $resp->headers->get('Content-Disposition'));

        $csv = $resp->getContent();
        // 无 BOM 时 Excel 会按 GBK 猜，中文列名全乱码
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('公司编码,公司名称,简称,纳税人识别号', $csv);
        $this->assertStringContainsString('GS01,连凯商业有限公司', $csv);

        $this->assertDatabaseHas('archive_companies', ['id' => $id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '连凯商业有限公司',
            'code' => null,
            'short_name' => '连凯',
            'tax_no' => '91330100MA2GX0000X',
            'invoice_title' => '连凯商业有限公司',
            'bank_name' => '中国工商银行杭州分行',
            'bank_account' => '1202021109900000001',
            'contact_name' => '王明',
            'contact_phone' => '13800000000',
            'address' => '浙江省杭州市滨江区',
            'is_default' => false,
            'status' => 'enabled',
            'sort' => 0,
            'remark' => '主体公司',
        ], $overrides);
    }
}
