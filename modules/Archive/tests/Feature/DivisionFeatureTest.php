<?php

namespace Tests\Archive\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Archive\Models\Company;
use Modules\Archive\Models\Division;
use Tests\TestCase;

/**
 * F6 块1：事业部档案。
 *
 * 与公司档案的差别都在「主体归属」这一层：
 *  - 新增必须指定公司，且公司要存在、要启用
 *  - 公司停用后存量事业部仍可维护，但不能再把新事业部挂上去 / 换挂过去
 *  - 列表要带出所属公司名（一次 with，不逐行查）
 *  - 归属未提交时沿用原公司，不能被写成 null
 */
class DivisionFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '档案管理员']);
    }

    public function test_full_lifecycle_with_company_name(): void
    {
        $company = $this->makeCompany(['code' => 'GS01']);

        $resp = $this->postJson('/admin/data/division', [
            'company_id' => $company->id,
            'code' => 'SYB01',
            'name' => '华南快消事业部',
            'manager_name' => '李强',
            'manager_phone' => '13900000000',
            'effective_at' => '2026-01-01',
            'sort' => 5,
        ])->assertOk()
            ->assertJsonPath('message', '已创建')
            ->assertJsonPath('data.code', 'SYB01')
            ->assertJsonPath('data.status', 'enabled');

        $id = $resp->json('data.id');

        $this->getJson('/admin/data/division')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.company_name', '连凯商业有限公司')
            // effective_at 走 date:Y-m-d cast，前端直接显示不再截串
            ->assertJsonPath('data.list.0.effective_at', '2026-01-01');

        $this->putJson("/admin/data/division/{$id}", ['name' => '华南快消事业部（合并）', 'company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('data.name', '华南快消事业部（合并）')
            ->assertJsonPath('data.company_name', '连凯商业有限公司');

        $this->deleteJson("/admin/data/division/{$id}")->assertOk();
        $this->assertSoftDeleted('archive_divisions', ['id' => $id]);
        $this->getJson('/admin/data/division')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_company_is_required_on_create(): void
    {
        $this->postJson('/admin/data/division', ['name' => '无主事业部'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        $this->postJson('/admin/data/division', ['company_id' => 999999, 'name' => '挂空事业部'])
            ->assertStatus(422);
    }

    public function test_division_cannot_be_created_under_disabled_company(): void
    {
        $company = $this->makeCompany(['code' => 'GS01']);
        $company->update(['status' => Company::STATUS_DISABLED]);

        $this->postJson('/admin/data/division', ['company_id' => $company->id, 'name' => '新事业部'])
            ->assertStatus(422)
            ->assertJsonPath('message', '所属公司「连凯商业有限公司」已停用，不能挂靠事业部');
    }

    public function test_existing_division_stays_editable_when_company_disabled(): void
    {
        $company = $this->makeCompany(['code' => 'GS01']);
        $other = $this->makeCompany(['code' => 'GS02', 'name' => '连凯供应链公司']);
        $id = $this->postJson('/admin/data/division', ['company_id' => $company->id, 'name' => '存量事业部'])
            ->assertOk()->json('data.id');

        $company->update(['status' => Company::STATUS_DISABLED]);

        // 停用公司下的存量事业部仍可改名（归属没变）
        $this->putJson("/admin/data/division/{$id}", ['name' => '存量事业部改名', 'company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('data.name', '存量事业部改名');

        // 编辑时不提交归属也不能把 company_id 洗成 null
        $this->putJson("/admin/data/division/{$id}", ['name' => '只改名字', 'remark' => '备注'])
            ->assertOk()
            ->assertJsonPath('data.company_id', $company->id);
        $this->assertSame($company->id, Division::find($id)->company_id);

        // 但换挂到另一家（停用中的）公司必须被挡下
        $other->update(['status' => Company::STATUS_DISABLED]);
        $this->putJson("/admin/data/division/{$id}", ['name' => '存量事业部改名', 'company_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('message', "所属公司「{$other->name}」已停用，不能挂靠事业部");
        $this->assertSame($company->id, Division::find($id)->company_id);
    }

    public function test_code_is_unique_and_query_filters_by_company_and_status(): void
    {
        $a = $this->makeCompany(['code' => 'GS01']);
        $b = $this->makeCompany(['code' => 'GS02', 'name' => '连凯供应链公司']);

        $this->postJson('/admin/data/division', ['company_id' => $a->id, 'code' => 'SYB01', 'name' => '华南事业部'])->assertOk();
        $this->postJson('/admin/data/division', ['company_id' => $b->id, 'code' => 'SYB02', 'name' => '华东事业部', 'status' => 'disabled'])
            ->assertOk();

        $this->postJson('/admin/data/division', ['company_id' => $b->id, 'code' => 'SYB01', 'name' => '重复编码'])
            ->assertStatus(422)
            ->assertJsonPath('message', '事业部编码已存在');

        // 编码可选，留空落 null
        $this->postJson('/admin/data/division', ['company_id' => $a->id, 'code' => '', 'name' => '无编码事业部'])->assertOk();
        $this->assertSame(1, Division::whereNull('code')->count());

        $this->getJson('/admin/data/division?company_id='.$b->id)
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.code', 'SYB02');
        $this->getJson('/admin/data/division?status=disabled')
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/admin/data/division?name=华南')
            ->assertOk()->assertJsonPath('data.total', 1);

        // options 只给启用中的事业部：b 下唯一那条是 disabled，所以应为空
        $this->getJson('/admin/data/division/options?company_id='.$b->id)
            ->assertOk()
            ->assertJsonPath('data.list', []);
        // a 下有启用中的两条，其中「无编码事业部」code 为 null 也要能出现在下拉里
        $codesA = $this->getJson('/admin/data/division/options?company_id='.$a->id)
            ->assertOk()->json('data.list');
        $this->assertSame(['SYB01', null], collect($codesA)->pluck('code')->all());
    }

    public function test_batch_status_and_export_csv_carries_bom(): void
    {
        $company = $this->makeCompany(['code' => 'GS01']);
        $one = $this->postJson('/admin/data/division', ['company_id' => $company->id, 'code' => 'SYB01', 'name' => '华南事业部'])
            ->assertOk()->json('data.id');
        $two = $this->postJson('/admin/data/division', ['company_id' => $company->id, 'code' => 'SYB02', 'name' => '华东事业部'])
            ->assertOk()->json('data.id');

        $this->postJson('/admin/data/division/batch-status', ['ids' => [$one, $two], 'status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.affected', 2);
        $this->assertSame('disabled', Division::find($one)->status);
        $this->assertSame('disabled', Division::find($two)->status);

        $this->postJson('/admin/data/division/batch-status', ['ids' => [$one], 'status' => 'unknown'])
            ->assertStatus(422);

        $resp = $this->get('/admin/data/division/export');
        $resp->assertOk();
        $this->assertStringContainsString('filename="divisions.csv"', $resp->headers->get('Content-Disposition'));

        $csv = $resp->getContent();
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('事业部编码,事业部名称,所属公司,负责人', $csv);
        $this->assertStringContainsString('SYB01,华南事业部,连凯商业有限公司', $csv);
    }

    private function makeCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'code' => 'GS01',
            'name' => '连凯商业有限公司',
            'status' => Company::STATUS_ENABLED,
            'sort' => 0,
        ], $overrides));
    }
}
