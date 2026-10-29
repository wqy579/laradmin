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

    // =========================================================================
    // F3：4 KPI 卡统计 + 批量提醒
    // =========================================================================

    public function test_stats_returns_kpi_counts(): void
    {
        $this->postJson('admin/business/visit-reminder', $this->payload())->assertOk(); // pending
        $id2 = $this->postJson('admin/business/visit-reminder', $this->payload())->json('data.id');
        $this->postJson("admin/business/visit-reminder/{$id2}/send")->assertOk(); // sent

        $res = $this->getJson('admin/business/visit-reminder/stats')->assertOk();
        $this->assertSame(1, $res->json('data.pending'));
        $this->assertSame(1, $res->json('data.sent'));
        $this->assertArrayHasKey('today_pending', $res->json('data'));
        $this->assertArrayHasKey('coverage_rate', $res->json('data'));
        $this->assertArrayHasKey('plan_customers', $res->json('data'));
        $this->assertArrayHasKey('reached_customers', $res->json('data'));
    }

    public function test_stats_coverage_rate_uses_plan_vs_reached(): void
    {
        // 造一条「应访」线路客户（routes → customers route_id 非空）
        $routeId = DB::table('routes')->insertGetId([
            'code' => 'RT'.uniqid(), 'name' => 'A 线', 'employee_id' => $this->employeeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'name' => '覆盖客户', 'code' => 'C'.uniqid(), 'route_id' => $routeId,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('visit_logs')->insert([
            'visit_no' => 'VL'.uniqid(), 'employee_id' => $this->employeeId, 'customer_id' => $customerId,
            'checkin_time' => now()->toDateTimeString(), 'status' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('admin/business/visit-reminder/stats')->assertOk();
        $this->assertSame(1, $res->json('data.plan_customers'));
        $this->assertSame(1, $res->json('data.reached_customers'));
        $this->assertSame(100.0, (float) $res->json('data.coverage_rate'));
    }

    public function test_batch_send_marks_all_pending_and_skips_sent(): void
    {
        $id1 = $this->postJson('admin/business/visit-reminder', $this->payload())->json('data.id');
        $id2 = $this->postJson('admin/business/visit-reminder', $this->payload())->json('data.id');
        $id3 = $this->postJson('admin/business/visit-reminder', $this->payload())->json('data.id');
        $this->postJson("admin/business/visit-reminder/{$id3}/send")->assertOk(); // 已提醒，批量应跳过

        $res = $this->postJson('admin/business/visit-reminder/batch-send', ['ids' => [$id1, $id2, $id3]])->assertOk();
        $this->assertSame(2, $res->json('data.sent'));
        $this->assertSame(1, $res->json('data.skipped'));

        $this->assertSame('sent', DB::table('visit_reminders')->find($id1)->status);
        $this->assertSame('sent', DB::table('visit_reminders')->find($id2)->status);

        // 每条都已留痕
        foreach ([$id1, $id2] as $id) {
            $actions = collect(DB::table('order_operation_logs')->where('order_type', 'visit_reminder')->where('order_id', $id)->get())->pluck('action');
            $this->assertContains('已提醒', $actions);
        }
    }

    public function test_batch_send_requires_ids(): void
    {
        $this->postJson('admin/business/visit-reminder/batch-send', ['ids' => []])->assertStatus(422);
        $this->postJson('admin/business/visit-reminder/batch-send', [])->assertStatus(422);
    }
}
