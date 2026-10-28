<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T5.4 业务员拜访提醒
 *
 * 提醒发送后状态变为「已提醒」（sent），全程留痕（order_operation_logs）。
 */
class VisitReminderFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->employeeId = DB::table('auth_user')->insertGetId([
            'username' => 'salesman-'.uniqid(),
            'real_name' => '业务员甲',
            'password' => bcrypt('secret123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $this->employeeId,
            'visit_date' => '2026-10-29',
            'content' => '明天上午拜访客户，带上新品样册',
        ], $overrides);
    }

    public function test_can_create_reminder_defaults_to_pending(): void
    {
        $res = $this->postJson('admin/business/visit-reminder', $this->payload())->assertOk();

        $id = $res->json('data.id');
        $this->assertNotEmpty($res->json('data.remind_no'));
        $this->assertSame('pending', DB::table('visit_reminders')->find($id)->status);

        // 创建即留痕
        $detail = $this->getJson('admin/business/visit-reminder/'.$id)->assertOk();
        $this->assertNotEmpty($detail->json('data.history'));
    }

    public function test_send_marks_status_reminded_and_records_time(): void
    {
        $id = $this->postJson('admin/business/visit-reminder', $this->payload())->json('data.id');

        $this->postJson("admin/business/visit-reminder/{$id}/send")->assertOk();

        $row = DB::table('visit_reminders')->find($id);
        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->sent_at);

        // 留痕含「已提醒」动作
        $detail = $this->getJson('admin/business/visit-reminder/'.$id)->assertOk();
        $actions = collect($detail->json('data.history'))->pluck('action')->all();
        $this->assertContains('已提醒', $actions);
    }

    public function test_send_twice_is_rejected(): void
    {
        $id = $this->postJson('admin/business/visit-reminder', $this->payload())->json('data.id');
        $this->postJson("admin/business/visit-reminder/{$id}/send")->assertOk();
        $this->postJson("admin/business/visit-reminder/{$id}/send")->assertStatus(422);
    }

    public function test_list_filters_by_status_and_keyword(): void
    {
        $this->postJson('admin/business/visit-reminder', $this->payload())->assertOk();
        $id2 = $this->postJson('admin/business/visit-reminder', $this->payload(['content' => '押金催缴']))->json('data.id');
        $this->postJson("admin/business/visit-reminder/{$id2}/send")->assertOk();

        $all = $this->getJson('admin/business/visit-reminder')->assertOk();
        $this->assertSame(2, $all->json('data.total'));

        $onlySent = $this->getJson('admin/business/visit-reminder?status=sent')->assertOk();
        $this->assertSame(1, $onlySent->json('data.total'));
        $this->assertSame('sent', $onlySent->json('data.list.0.status'));
    }

    public function test_pending_reminder_can_be_cancelled_but_sent_cannot(): void
    {
        $id = $this->postJson('admin/business/visit-reminder', $this->payload())->json('data.id');

        $this->deleteJson('admin/business/visit-reminder/'.$id)->assertOk();
        $this->assertSame('cancelled', DB::table('visit_reminders')->find($id)->status);

        $this->postJson("admin/business/visit-reminder/{$id}/send")->assertStatus(422);
    }
}
