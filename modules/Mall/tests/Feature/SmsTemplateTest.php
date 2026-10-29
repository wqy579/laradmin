<?php

namespace Tests\Mall\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mall\Models\SmsRecord;
use Modules\Mall\Models\SmsTemplate;
use Tests\TestCase;

/**
 * F5 验收 3/3：短信模板 + 变量实时预览 + 发送记录。
 *
 *  - 模板体 {变量名} 占位，variables 存 {变量名: 示例值}；
 *  - preview：替换示例值出预览，缺省变量保持原样；返回变量清单供前端补填；
 *  - render：发送时校验变量齐全，缺变量抛 DomainException → 422；
 *  - 发送：渲染后落 sms_records（pending），状态机 success/failed/pending；
 *  - 记录列表带状态 Tag（success=成功/failed=失败/pending=发送中）。
 */
class SmsTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '短信专员']);
    }

    public function test_preview_replaces_variables_with_example_values(): void
    {
        $resp = $this->postJson('/admin/f5/sms/preview', [
            'content' => '尊敬的{客户名}，您的订单{订单号}已发货，请查收。',
            'variables' => ['客户名' => '连凯超市', '订单号' => 'SO123'],
        ])->assertOk();

        $this->assertSame('尊敬的连凯超市，您的订单SO123已发货，请查收。', $resp->json('data.preview'));
        $this->assertSame(['客户名', '订单号'], $resp->json('data.variables'));
    }

    public function test_preview_keeps_missing_variable_as_placeholder(): void
    {
        $resp = $this->postJson('/admin/f5/sms/preview', [
            'content' => '您好{客户名}，余额{余额}元',
            'variables' => ['客户名' => '张三'],
        ])->assertOk();

        $this->assertSame('您好张三，余额{余额}元', $resp->json('data.preview'));
        $this->assertSame(['客户名', '余额'], $resp->json('data.variables'));
    }

    public function test_template_crud_and_variable_detection(): void
    {
        $resp = $this->postJson('/admin/f5/sms/templates', [
            'code' => 'ORDER_NOTIFY',
            'name' => '订单通知',
            'content' => '尊敬的{客户名}，订单{订单号}已发货',
            'variables' => ['客户名' => '示例客户', '订单号' => '示例单号'],
        ])->assertOk();

        $id = $resp->json('data.id');
        $this->assertSame('ORDER_NOTIFY', $resp->json('data.code'));
        $this->assertSame(1, $resp->json('data.status'));
        $this->assertSame('启用', $resp->json('data.status_label'));
        $this->assertSame('success', $resp->json('data.status_tag_type'));

        // 编码唯一
        $this->postJson('/admin/f5/sms/templates', [
            'code' => 'ORDER_NOTIFY',
            'name' => '重复',
            'content' => 'x',
        ])->assertStatus(422);

        // 更新
        $this->putJson("/admin/f5/sms/templates/{$id}", [
            'code' => 'ORDER_NOTIFY',
            'name' => '订单通知V2',
            'content' => '您好{客户名}',
            'variables' => ['客户名' => '示例客户'],
        ])->assertOk()->assertJsonPath('data.name', '订单通知V2');

        // 停用/启用
        $this->postJson("/admin/f5/sms/templates/{$id}/toggle", ['status' => 0])
            ->assertOk()->assertJsonPath('data.status', 0);
        $this->postJson("/admin/f5/sms/templates/{$id}/toggle")
            ->assertOk()->assertJsonPath('data.status', 1);

        // 列表可查到
        $list = $this->getJson('/admin/f5/sms/templates?keyword=订单通知')->assertOk()->json('data');
        $this->assertSame(1, $list['total']);
        $this->assertSame($id, $list['list'][0]['id']);

        // 删除
        $this->deleteJson("/admin/f5/sms/templates/{$id}")->assertOk();
        $this->assertNull(SmsTemplate::find($id));
    }

    public function test_send_renders_and_creates_record(): void
    {
        $template = SmsTemplate::create([
            'code' => 'BALANCE_ALERT',
            'name' => '余额预警',
            'content' => '尊敬的{客户名}，您的账户余额{余额}元，请及时补货。',
            'variables' => ['客户名' => '示例客户', '余额' => '0.00'],
            'status' => 1,
        ]);

        $resp = $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id,
            'mobile' => '13800000000',
            'receiver_name' => '连凯超市',
            'variables' => ['客户名' => '连凯超市', '余额' => '120.50'],
        ])->assertOk();

        $this->assertSame('success', $resp->json('data.status'));
        $this->assertSame('成功', $resp->json('data.status_label'));
        $this->assertSame('success', $resp->json('data.status_tag_type'));
        $this->assertSame('尊敬的连凯超市，您的账户余额120.50元，请及时补货。', $resp->json('data.content'));
        $this->assertSame('13800000000', $resp->json('data.mobile'));
        $this->assertMatchesRegularExpression('/^SMS\d{14}\d{4}$/', $resp->json('data.send_no'));
        $this->assertNotNull($resp->json('data.sent_at'));
    }

    public function test_send_rejects_missing_variable(): void
    {
        $template = SmsTemplate::create([
            'code' => 'GREET',
            'name' => '问候',
            'content' => '您好{客户名}',
            'variables' => ['客户名' => '示例'],
            'status' => 1,
        ]);

        // 缺变量 → 422
        $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id,
            'mobile' => '13900000000',
        ])->assertStatus(422);

        $this->assertSame(0, SmsRecord::count());
    }

    public function test_send_rejects_disabled_template(): void
    {
        $template = SmsTemplate::create([
            'code' => 'OFF',
            'name' => '停用模板',
            'content' => '您好{客户名}',
            'variables' => ['客户名' => '示例'],
            'status' => 0,
        ]);

        $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id,
            'mobile' => '13900000000',
            'variables' => ['客户名' => '张三'],
        ])->assertStatus(422);
    }

    public function test_records_list_filters_by_status_and_keyword(): void
    {
        $template = SmsTemplate::create([
            'code' => 'REC',
            'name' => '记录',
            'content' => '您好{客户名}',
            'variables' => ['客户名' => '示例'],
            'status' => 1,
        ]);

        $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id,
            'mobile' => '13800000001',
            'receiver_name' => '张三',
            'variables' => ['客户名' => '张三'],
        ])->assertOk();

        $list = $this->getJson('/admin/f5/sms/records?status=success')->assertOk()->json('data');
        $this->assertSame(1, $list['total']);
        $this->assertSame('成功', $list['list'][0]['status_label']);
        $this->assertSame('success', $list['list'][0]['status_tag_type']);

        // 按手机号过滤
        $byMobile = $this->getJson('/admin/f5/sms/records?keyword=13800000001')->assertOk()->json('data');
        $this->assertSame(1, $byMobile['total']);

        // 状态过滤：失败无记录
        $failed = $this->getJson('/admin/f5/sms/records?status=failed')->assertOk()->json('data');
        $this->assertSame(0, $failed['total']);
    }

    public function test_resend_copies_record_with_new_send_no(): void
    {
        $template = SmsTemplate::create([
            'code' => 'RESEND',
            'name' => '重发',
            'content' => '您好{客户名}',
            'variables' => ['客户名' => '示例'],
            'status' => 1,
        ]);
        $resp = $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id,
            'mobile' => '13800000002',
            'variables' => ['客户名' => '李四'],
        ])->assertOk();
        $original = $resp->json('data');

        $resend = $this->postJson("/admin/f5/sms/records/{$original['id']}/resend")->assertOk();

        $this->assertNotSame($original['send_no'], $resend->json('data.send_no'));
        $this->assertSame('13800000002', $resend->json('data.mobile'));
        $this->assertSame('您好李四', $resend->json('data.content'));
        $this->assertSame(2, SmsRecord::count());
    }
}
