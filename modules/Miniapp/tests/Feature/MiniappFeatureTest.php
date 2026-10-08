<?php

namespace Tests\Miniapp\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 小程序设置接口冒烟。
 *
 * 重点验证密钥的两条约定：
 *  ① 读出来必须是 ******，不能把 app_secret / api_key 明文回给浏览器；
 *  ② 前端原样回传 ****** 时视为「没改」，绝不能把 ****** 写进库里覆盖真值。
 * 第二条最容易漏——界面上看着是「保存成功」，实际下一次支付就全挂。
 */
class MiniappFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_index_returns_defaults_and_group_meta(): void
    {
        $res = $this->getJson('admin/business/mini-program-settings?group=basic');

        $res->assertOk();
        $this->assertSame('basic', $res->json('data.group'));
        $this->assertArrayHasKey('name', $res->json('data.config'));
        $this->assertCount(9, $res->json('data.groups'));
        $this->assertArrayHasKey('notify_url', $res->json('data.readonly'));
    }

    public function test_secret_fields_are_masked_when_read_back(): void
    {
        $this->putJson('admin/business/mini-program-settings/basic', [
            'config' => ['name' => '连凯商城', 'app_id' => 'wx123456', 'app_secret' => 'real-secret-value'],
        ])->assertOk();

        $res = $this->getJson('admin/business/mini-program-settings?group=basic');
        $this->assertSame('******', $res->json('data.config.app_secret'));
        // 非敏感字段正常回显
        $this->assertSame('wx123456', $res->json('data.config.app_id'));
    }

    public function test_masked_secret_sent_back_does_not_overwrite_real_value(): void
    {
        $this->putJson('admin/business/mini-program-settings/basic', [
            'config' => ['app_secret' => 'real-secret-value'],
        ])->assertOk();

        // 前端把打码值原样回传
        $this->putJson('admin/business/mini-program-settings/basic', [
            'config' => ['name' => '改个名字', 'app_secret' => '******'],
        ])->assertOk();

        $stored = json_decode(DB::table('miniapp_settings')->where('group', 'basic')->value('config'), true);
        $this->assertSame('real-secret-value', $stored['app_secret']);
        $this->assertSame('改个名字', $stored['name']);
    }

    public function test_reset_restores_defaults(): void
    {
        $this->putJson('admin/business/mini-program-settings/payment', [
            'config' => ['mch_id' => '1234567890', 'points_ratio' => 50],
        ])->assertOk();

        $this->postJson('admin/business/mini-program-settings/payment/reset')->assertOk();

        $res = $this->getJson('admin/business/mini-program-settings?group=payment');
        $this->assertSame(100, $res->json('data.config.points_ratio'));
        $this->assertSame('', $res->json('data.config.mch_id'));
    }

    public function test_unknown_group_is_rejected(): void
    {
        $this->putJson('admin/business/mini-program-settings/not-a-group', ['config' => []])
            ->assertStatus(422);
    }

    public function test_miniapp_endpoints_require_auth(): void
    {
        $this->withToken('');
        $this->getJson('admin/business/mini-program-settings')->assertStatus(401);
    }
}
