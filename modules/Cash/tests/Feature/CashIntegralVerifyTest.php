<?php

namespace Tests\Cash\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cash\Models\CashIntegralGrant;
use Modules\Cash\Models\CashIntegralVerify;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Tests\TestCase;

/**
 * D6 验收：积分核销 + 积分查询
 *
 * - 核销单状态机（草稿→待审核→已审核 / 作废）与 D5 同口径；
 * - 审核是唯一积分副作用点：单事务扣 members.points + 追加 direction=out 流水，
 *   重复审核被状态守卫挡住，settled_at 再挡一层（状态被外部改回也不会二次扣分）；
 * - 积分不足在扣减前抛错并整体回滚，不留半条流水；
 * - 积分查询只读 member_consume_logs，期初 + 本期发放 − 本期核销 = 期末，
 *   且不 union 发放明细表（重复计数）。
 */
class CashIntegralVerifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_verify_lifecycle_deducts_points_and_writes_one_out_flow(): void
    {
        $member = $this->member(300);

        // remark 留空，用来覆盖流水备注回退成「积分核销 单号」这条分支
        $resp = $this->postJson('/admin/cash/integral/verifies', $this->verifyPayload($member, ['points' => 120, 'remark' => null]))
            ->assertOk()
            ->assertJsonPath('code', 200);

        $verifyNo = $resp->json('data.verify_no');
        $this->assertMatchesRegularExpression('/^HX\d{8}\d{6}$/', $verifyNo);

        $verify = CashIntegralVerify::query()->where('verify_no', $verifyNo)->firstOrFail();
        $this->assertSame(CashIntegralVerify::STATUS_DRAFT, (int) $verify->status);
        $this->assertNull($verify->settled_at);
        $this->assertSame(300, (int) $member->fresh()->points);

        $id = $verify->id;
        $this->postJson("/admin/cash/integral/verifies/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', CashIntegralVerify::STATUS_PENDING);

        $this->postJson("/admin/cash/integral/verifies/{$id}/audit")
            ->assertOk()
            ->assertJsonPath('data.status', CashIntegralVerify::STATUS_AUDITED)
            ->assertJsonPath('data.points_before', 300)
            ->assertJsonPath('data.points_after', 180);

        $this->assertSame(180, (int) $member->fresh()->points);

        $verify->refresh();
        $this->assertSame(300, (int) $verify->points_before);
        $this->assertSame(180, (int) $verify->points_after);
        $this->assertNotNull($verify->settled_at);
        $this->assertNotNull($verify->audited_at);

        $log = MemberConsumeLog::query()->where('related_order_no', $verifyNo)->sole();
        $this->assertSame(MemberConsumeLog::DIRECTION_OUT, $log->direction);
        $this->assertSame('points_use', $log->consume_type);
        $this->assertSame(120, (int) $log->use_points);
        $this->assertSame(0, (int) $log->earn_points);
        $this->assertSame(180, (int) $log->points_after);
        $this->assertSame($member->member_no, $log->member_no);
        $this->assertSame('积分核销 '.$verifyNo, $log->remark);
        $this->assertSame(1, MemberConsumeLog::query()->where('member_id', $member->id)->count());
    }

    public function test_audit_is_idempotent_and_never_deducts_twice(): void
    {
        $member = $this->member(500);
        $id = $this->createPendingVerify($member, 200);

        $this->postJson("/admin/cash/integral/verifies/{$id}/audit")->assertOk();
        $this->assertSame(300, (int) $member->fresh()->points);

        // 第二次审核：状态守卫挡住
        $this->postJson("/admin/cash/integral/verifies/{$id}/audit")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有待审核的核销单可以审核，当前状态：已审核');
        $this->assertSame(300, (int) $member->fresh()->points);

        // 状态被外部改回待审核：settled_at 这一层仍必须挡住扣减
        CashIntegralVerify::query()->whereKey($id)->update(['status' => CashIntegralVerify::STATUS_PENDING]);
        $this->postJson("/admin/cash/integral/verifies/{$id}/audit")
            ->assertOk()
            ->assertJsonPath('data.status', CashIntegralVerify::STATUS_AUDITED);

        $this->assertSame(300, (int) $member->fresh()->points);
        $this->assertSame(1, MemberConsumeLog::query()->where('member_id', $member->id)->count());
        // points_before 是扣减前余额快照，第二次审核不得覆盖它
        $this->assertSame(500, (int) CashIntegralVerify::query()->findOrFail($id)->points_before);
        $this->assertSame(300, (int) CashIntegralVerify::query()->findOrFail($id)->points_after);
    }

    public function test_insufficient_points_is_rejected_without_side_effects(): void
    {
        $member = $this->member(50);
        $id = $this->createPendingVerify($member, 100);

        $this->postJson("/admin/cash/integral/verifies/{$id}/audit")
            ->assertStatus(422)
            ->assertJsonPath('message', '会员可用积分不足，当前 50，本次核销 100');

        $this->assertSame(50, (int) $member->fresh()->points);
        $this->assertSame(0, MemberConsumeLog::query()->where('member_id', $member->id)->count());

        $verify = CashIntegralVerify::query()->findOrFail($id);
        $this->assertSame(CashIntegralVerify::STATUS_PENDING, (int) $verify->status);
        $this->assertNull($verify->settled_at);
        $this->assertNull($verify->points_before);

        // 补积分后可以重新审核成功
        Member::query()->whereKey($member->id)->update(['points' => 100]);
        $this->postJson("/admin/cash/integral/verifies/{$id}/audit")->assertOk();
        $this->assertSame(0, (int) $member->fresh()->points);
    }

    public function test_state_machine_blocks_edit_submit_and_void(): void
    {
        $member = $this->member(400);
        $id = $this->createPendingVerify($member, 100);

        // 非草稿不可编辑
        $this->putJson("/admin/cash/integral/verifies/{$id}", $this->verifyPayload($member, ['points' => 30]))
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态的核销单可以编辑');

        // 非草稿不可重复提交
        $this->postJson("/admin/cash/integral/verifies/{$id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态的核销单可以提交');

        $this->postJson("/admin/cash/integral/verifies/{$id}/audit")->assertOk();

        // 已审核不可作废，也不回滚积分
        $this->postJson("/admin/cash/integral/verifies/{$id}/void")
            ->assertStatus(422)
            ->assertJsonPath('message', '已审核或已作废的核销单不能作废');
        $this->assertSame(300, (int) $member->fresh()->points);

        // 草稿可直接作废
        $draftId = CashIntegralVerify::query()
            ->findOrFail($this->postJson('/admin/cash/integral/verifies', $this->verifyPayload($member, ['points' => 10]))
                ->assertOk()->json('data.id'))
            ->id;
        $this->postJson("/admin/cash/integral/verifies/{$draftId}/void")
            ->assertOk()
            ->assertJsonPath('data.status', CashIntegralVerify::STATUS_VOID);
        $this->assertSame(300, (int) $member->fresh()->points);
    }

    public function test_draft_is_editable_and_list_filters_apply(): void
    {
        $member = $this->member(400);
        $other = $this->member(400);

        $created = $this->postJson('/admin/cash/integral/verifies', $this->verifyPayload($member, ['points' => 40]))
            ->assertOk();
        $id = $created->json('data.id');
        $verifyNo = $created->json('data.verify_no');

        $this->putJson("/admin/cash/integral/verifies/{$id}", $this->verifyPayload($member, ['points' => 60, 'remark' => '改额度']))
            ->assertOk();
        $this->assertSame(60, (int) CashIntegralVerify::query()->findOrFail($id)->points);

        $this->postJson('/admin/cash/integral/verifies', $this->verifyPayload($other, ['points' => 20]))->assertOk();

        $this->getJson('/admin/cash/integral/verifies?verify_no='.$verifyNo)
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/admin/cash/integral/verifies?member_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/admin/cash/integral/verifies?status=0')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/admin/cash/integral/verifies?status=1')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->postJson("/admin/cash/integral/verifies/{$id}/submit")->assertOk();
        $this->getJson('/admin/cash/integral/verifies?status=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/admin/cash/integral/verifies?keyword='.$member->name)
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $show = $this->getJson("/admin/cash/integral/verifies/{$id}")
            ->assertOk()
            ->assertJsonPath('data.verify_no', $verifyNo)
            ->assertJsonPath('data.member_no', $member->member_no)
            ->assertJsonPath('data.status_label', '待审核');
        $this->assertArrayHasKey('settled_at', $show->json('data'));
    }

    public function test_member_summary_returns_current_and_cumulative_points(): void
    {
        $member = $this->member(0);
        $this->grantPoints($member, 300);
        $this->auditVerify($member, 80);

        $this->assertSame(220, (int) $member->fresh()->points);

        $data = $this->getJson('/admin/cash/integral/verifies/member-summary?member_id='.$member->id)
            ->assertOk()
            ->json('data');

        $this->assertSame($member->id, (int) $data['member_id']);
        $this->assertSame($member->member_no, $data['member_no']);
        $this->assertSame(220, (int) $data['points_current']);
        $this->assertSame(300, (int) $data['points_grant']);
        $this->assertSame(80, (int) $data['points_verify']);

        $this->getJson('/admin/cash/integral/verifies/member-summary')
            ->assertStatus(422)
            ->assertJsonPath('message', '请选择会员');
    }

    public function test_ledger_members_identity_open_plus_grant_minus_verify_equals_close(): void
    {
        $member = $this->member(0);
        $this->grantPoints($member, 300);
        $this->auditVerify($member, 80);
        $today = now()->format('Y-m-d');

        $row = $this->ledgerRow($member, ['start_date' => $today, 'end_date' => $today]);
        $this->assertSame(0, (int) $row['points_open']);
        $this->assertSame(300, (int) $row['points_grant']);
        $this->assertSame(80, (int) $row['points_verify']);
        $this->assertSame(220, (int) $row['points_close']);
        $this->assertSame(220, (int) $row['points_current']);
        $this->assertSame(
            (int) $row['points_open'] + (int) $row['points_grant'] - (int) $row['points_verify'],
            (int) $row['points_close']
        );
        $this->assertSame((int) $row['points_close'], (int) $member->fresh()->points);

        // 窗口推到未来：两笔都算期初，本期收发为 0，期末仍等于实时余额
        $later = $this->ledgerRow($member, [
            'start_date' => now()->addDay()->format('Y-m-d'),
            'end_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        $this->assertSame(220, (int) $later['points_open']);
        $this->assertSame(0, (int) $later['points_grant']);
        $this->assertSame(0, (int) $later['points_verify']);
        $this->assertSame(220, (int) $later['points_close']);
        $this->assertSame((int) $member->fresh()->points, (int) $later['points_close']);
    }

    public function test_ledger_reads_only_member_flows_and_never_double_counts(): void
    {
        $member = $this->member(0);
        $other = $this->member(0);
        $this->grantPoints($member, 300);
        $this->auditVerify($member, 80);
        $this->grantPoints($other, 50);

        $flows = $this->getJson('/admin/cash/integral/ledger/flows?member_id='.$member->id)
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->json('data.list');

        // 一笔发放 + 一笔核销，不多不少（没有把发放明细表再 union 进来）
        $this->assertSame(1, count(array_filter($flows, fn ($f) => $f['consume_type'] === 'points_grant')));
        $this->assertSame(1, count(array_filter($flows, fn ($f) => $f['consume_type'] === 'points_use')));

        $signed = array_sum(array_column($flows, 'points'));
        $this->assertSame(220, $signed);
        $this->assertSame((int) $member->fresh()->points, $signed);

        $out = collect($flows)->firstWhere('consume_type', 'points_use');
        $this->assertSame(-80, (int) $out['points']);
        $this->assertSame('out', $out['direction']);
        $this->assertSame(220, (int) $out['points_after']);

        $this->getJson('/admin/cash/integral/ledger/flows?member_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/admin/cash/integral/ledger/flows')
            ->assertStatus(422)
            ->assertJsonPath('message', '请选择会员');

        // 汇总只列有积分变动的会员，且恒等式对每一行都成立
        $list = $this->getJson('/admin/cash/integral/ledger/members')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->json('data.list');
        foreach ($list as $row) {
            $this->assertSame(
                (int) $row['points_open'] + (int) $row['points_grant'] - (int) $row['points_verify'],
                (int) $row['points_close']
            );
        }

        $filtered = $this->getJson('/admin/cash/integral/ledger/members?keyword='.$member->member_no)
            ->assertOk()
            ->assertJsonPath('data.total', 1);
        $this->assertSame($member->id, (int) $filtered->json('data.list.0.member_id'));
    }

    public function test_ledger_window_excludes_flows_outside_range(): void
    {
        $member = $this->member(0);
        $this->grantPoints($member, 300);
        DB::table('member_consume_logs')
            ->where('member_id', $member->id)
            ->update(['created_at' => now()->subDays(30)->format('Y-m-d H:i:s')]);
        $this->auditVerify($member, 80);

        $today = now()->format('Y-m-d');
        $row = $this->ledgerRow($member, ['start_date' => $today, 'end_date' => $today]);
        $this->assertSame(300, (int) $row['points_open']);
        $this->assertSame(0, (int) $row['points_grant']);
        $this->assertSame(80, (int) $row['points_verify']);
        $this->assertSame(220, (int) $row['points_close']);

        $this->getJson('/admin/cash/integral/ledger/flows?member_id='.$member->id.'&start_date='.$today)
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $lastMonth = now()->subDays(30)->format('Y-m-d');
        $this->getJson('/admin/cash/integral/ledger/flows?member_id='.$member->id.'&start_date='.$lastMonth.'&end_date='.$lastMonth)
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_exports_return_csv_with_bom_and_headers(): void
    {
        $member = $this->member(300);
        $this->auditVerify($member, 100);

        $verifyExport = $this->getJson('/admin/cash/integral/verifies/export')->assertOk();
        $this->assertStringContainsString('text/csv', $verifyExport->headers->get('Content-Type'));
        $this->assertStringContainsString('核销单号', $verifyExport->getContent());
        $this->assertStringContainsString('HX', $verifyExport->getContent());

        $ledgerExport = $this->getJson('/admin/cash/integral/ledger/export')->assertOk();
        $this->assertStringContainsString('text/csv', $ledgerExport->headers->get('Content-Type'));
        $this->assertStringContainsString('期初积分', $ledgerExport->getContent());
        $this->assertStringContainsString($member->member_no, $ledgerExport->getContent());
    }

    public function test_store_validates_payload(): void
    {
        $member = $this->member(10);

        $this->postJson('/admin/cash/integral/verifies', $this->verifyPayload($member, ['points' => 0]))
            ->assertStatus(422);
        $this->postJson('/admin/cash/integral/verifies', $this->verifyPayload($member, ['verify_type' => 'nope']))
            ->assertStatus(422);
        $this->postJson('/admin/cash/integral/verifies', $this->verifyPayload($member, ['member_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonPath('message', '会员不存在');

        $this->assertSame(0, CashIntegralVerify::query()->count());
    }

    private function member(int $points = 0): Member
    {
        return Member::create([
            'member_no' => 'HY'.now()->format('Ymd').str_pad((string) Str::random(4), 4, '0', STR_PAD_LEFT),
            'name' => '会员_'.Str::random(6),
            'points' => $points,
        ]);
    }

    /** @return array<string, mixed> */
    private function verifyPayload(Member $member, array $overrides = []): array
    {
        return array_merge([
            'verify_date' => now()->format('Y-m-d'),
            'member_id' => $member->id,
            'points' => 100,
            'verify_type' => CashIntegralVerify::TYPE_CONSUME,
            'remark' => '消费抵扣',
        ], $overrides);
    }

    private function createPendingVerify(Member $member, int $points): int
    {
        $id = $this->postJson('/admin/cash/integral/verifies', $this->verifyPayload($member, ['points' => $points]))
            ->assertOk()
            ->json('data.id');

        $this->postJson("/admin/cash/integral/verifies/{$id}/submit")->assertOk();

        return (int) $id;
    }

    private function auditVerify(Member $member, int $points): void
    {
        $id = $this->createPendingVerify($member, $points);
        $this->postJson("/admin/cash/integral/verifies/{$id}/audit")->assertOk();
    }

    /** 走 D5 已合并的发放接口造「发放」侧流水，用来验积分查询不重复计数 */
    private function grantPoints(Member $member, int $points): void
    {
        $grantNo = $this->postJson('/admin/cash/integral/grants', [
            'grant_date' => now()->format('Y-m-d'),
            'grant_type' => CashIntegralGrant::TYPE_DAILY,
            'remark' => '日常发放',
            'items' => [
                ['member_id' => $member->id, 'points' => $points, 'remark' => '消费返积分'],
            ],
        ])->assertOk()->json('data.grant_no');

        $grantId = CashIntegralGrant::query()->where('grant_no', $grantNo)->value('id');
        $this->postJson("/admin/cash/integral/grants/{$grantId}/submit")->assertOk();
        $this->postJson("/admin/cash/integral/grants/{$grantId}/audit")->assertOk();
    }

    /** @return array<string, mixed> */
    private function ledgerRow(Member $member, array $params): array
    {
        $list = $this->getJson('/admin/cash/integral/ledger/members?'.http_build_query($params + ['member_id' => $member->id]))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->json('data.list');

        return $list[0];
    }
}
