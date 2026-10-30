<?php

namespace Tests\Cash\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cash\Models\CashIntegralGrant;
use Modules\Cash\Models\CashIntegralGrantItem;
use Modules\Cash\Models\CashIntegralRule;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Tests\TestCase;

/**
 * D5 验收：积分规则 + 积分发放
 *
 *  - 规则 CRUD / 启停 / 导出，rule_name 唯一
 *  - 发放单 草稿 → 提交 → 审核 → 作废 状态机，编辑仅草稿
 *  - 审核同事务写 members.points + member_consume_logs（direction=in、points_after 正确）
 *  - 审核幂等：重复审核既不重复加分也不重复写流水
 */
class CashIntegralFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function member(int $points = 0): Member
    {
        return Member::create([
            'member_no' => 'HY'.now()->format('Ymd').str_pad((string) Str::random(4), 4, '0', STR_PAD_LEFT),
            'name' => '会员_'.Str::random(6),
            'points' => $points,
        ]);
    }

    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'rule_name' => '默认规则_'.Str::random(6),
            'ratio' => 1,
            'effective_date' => '2026-01-01',
            'status' => 1,
            'remark' => '测试规则',
        ], $overrides);
    }

    private function grantPayload(Member $member, array $overrides = []): array
    {
        return array_merge([
            'grant_date' => now()->format('Y-m-d'),
            'grant_type' => CashIntegralGrant::TYPE_DAILY,
            'remark' => '日常发放',
            'items' => [
                ['member_id' => $member->id, 'points' => 100, 'remark' => '消费返积分'],
            ],
        ], $overrides);
    }

    // ==================== 规则 ====================

    public function test_rule_crud_toggle_and_export(): void
    {
        $create = $this->postJson('/admin/cash/integral/rules', $this->rulePayload())
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.status_label', '启用');

        $id = $create->json('data.id');
        $this->assertGreaterThan(0, $id);

        $this->getJson('/admin/cash/integral/rules?rule_name='.$create->json('data.rule_name'))
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->putJson("/admin/cash/integral/rules/{$id}", $this->rulePayload([
            'rule_name' => $create->json('data.rule_name'),
            'ratio' => 2.5,
            'effective_date' => '2026-06-01',
        ]))->assertOk()->assertJsonPath('data.ratio', 2.5);

        $this->postJson("/admin/cash/integral/rules/{$id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.status', CashIntegralRule::STATUS_DISABLED)
            ->assertJsonPath('data.status_label', '停用');

        // 停用后才能删除
        $this->deleteJson("/admin/cash/integral/rules/{$id}")->assertOk();

        $export = $this->getJson('/admin/cash/integral/rules/export')->assertOk();
        $this->assertStringContainsString('text/csv', $export->headers->get('Content-Type'));
        $this->assertStringContainsString('规则名称', $export->getContent());
    }

    public function test_rule_name_must_be_unique_and_enabled_rule_cannot_delete(): void
    {
        $payload = $this->rulePayload();
        $first = $this->postJson('/admin/cash/integral/rules', $payload)->assertOk()->json('data.id');

        $this->postJson('/admin/cash/integral/rules', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', '规则名称已存在');

        $this->deleteJson("/admin/cash/integral/rules/{$first}")
            ->assertStatus(422)
            ->assertJsonPath('message', '启用中的规则不能删除，请先停用');
    }

    // ==================== 发放单 ====================

    public function test_grant_lifecycle_writes_points_and_consume_logs(): void
    {
        $member = $this->member(points: 50);
        $balanceBefore = (float) $member->balance;

        $grantNo = $this->postJson('/admin/cash/integral/grants', $this->grantPayload($member))
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->json('data.grant_no');

        $this->assertMatchesRegularExpression('/^JF-\d{6}-\d{4}$/', $grantNo);
        $grant = CashIntegralGrant::query()->where('grant_no', $grantNo)->firstOrFail();
        $this->assertSame(CashIntegralGrant::STATUS_DRAFT, (int) $grant->status);
        $this->assertSame(100, (int) $grant->total_points);

        $id = $grant->id;

        // 草稿可编辑，明细整体覆盖
        $this->putJson("/admin/cash/integral/grants/{$id}", $this->grantPayload($member, [
            'items' => [['member_id' => $member->id, 'points' => 200]],
        ]))->assertOk();
        $this->assertSame(200, (int) $grant->fresh()->total_points);

        $this->postJson("/admin/cash/integral/grants/{$id}/submit")->assertOk()->assertJsonPath('data.status', CashIntegralGrant::STATUS_PENDING);

        // 提交后不能再编辑
        $this->putJson("/admin/cash/integral/grants/{$id}", $this->grantPayload($member))
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态的发放单可以编辑');

        $this->postJson("/admin/cash/integral/grants/{$id}/audit")->assertOk()->assertJsonPath('data.status', CashIntegralGrant::STATUS_AUDITED);

        $this->assertSame(250, (int) $member->fresh()->points);

        $log = MemberConsumeLog::query()->where('related_order_no', $grantNo)->sole();
        $this->assertSame(MemberConsumeLog::DIRECTION_IN, $log->direction);
        $this->assertSame('points_grant', $log->consume_type);
        $this->assertSame(200, (int) $log->earn_points);
        $this->assertSame(250, (int) $log->points_after);
        $this->assertSame($member->member_no, $log->member_no);
        $this->assertSame($balanceBefore, (float) $log->balance_after);
        $this->assertSame('积分发放 '.$grantNo, $log->remark);

        $this->assertNotNull($grant->fresh()->audited_at);
        $this->assertNotNull(CashIntegralGrantItem::query()->where('grant_id', $id)->sole()->settled_at);

        $list = $this->getJson('/admin/cash/integral/grants?grant_no='.$grantNo)->assertOk();
        $this->assertSame(1, $list->json('data.total'));
        $this->assertSame('已审核', $list->json('data.list.0.status_label'));
        $this->assertSame($member->name, $list->json('data.list.0.member_names'));

        $detail = $this->getJson("/admin/cash/integral/grants/{$id}")->assertOk();
        $this->assertSame(200, $detail->json('data.items.0.points'));

        $export = $this->getJson('/admin/cash/integral/grants/export?grant_no='.$grantNo)->assertOk();
        $this->assertStringContainsString($grantNo, $export->getContent());
    }

    /** 幂等：同一发放单重复审核不重复加分、不重复写流水 */
    public function test_audit_is_idempotent(): void
    {
        $member = $this->member(points: 10);
        $id = $this->postJson('/admin/cash/integral/grants', $this->grantPayload($member))->json('data.id');
        $this->postJson("/admin/cash/integral/grants/{$id}/submit")->assertOk();

        $this->postJson("/admin/cash/integral/grants/{$id}/audit")->assertOk()->assertJsonPath('data.status', CashIntegralGrant::STATUS_AUDITED);
        $this->assertSame(110, (int) $member->fresh()->points);
        $logCount = MemberConsumeLog::query()->where('member_id', $member->id)->count();
        $this->assertSame(1, $logCount);

        // 第二次审核：状态守卫拦下，积分与流水都不变
        $this->postJson("/admin/cash/integral/grants/{$id}/audit")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有待审核的发放单可以审核，当前状态：已审核');
        $this->assertSame(110, (int) $member->fresh()->points);
        $this->assertSame(1, MemberConsumeLog::query()->where('member_id', $member->id)->count());

        // 绕过状态守卫（人为把状态改回待审核）时，明细 settled_at 仍拦住重复加分
        CashIntegralGrant::query()->whereKey($id)->update(['status' => CashIntegralGrant::STATUS_PENDING]);
        $this->postJson("/admin/cash/integral/grants/{$id}/audit")->assertOk();
        $this->assertSame(110, (int) $member->fresh()->points);
        $this->assertSame(1, MemberConsumeLog::query()->where('member_id', $member->id)->count());
    }

    public function test_grant_state_guards_and_void(): void
    {
        $member = $this->member();

        // 明细为空不能建单
        $this->postJson('/admin/cash/integral/grants', $this->grantPayload($member, ['items' => []]))
            ->assertStatus(422);

        // 会员必须存在
        $this->postJson('/admin/cash/integral/grants', $this->grantPayload($member, [
            'items' => [['member_id' => 999999, 'points' => 10]],
        ]))->assertStatus(422);

        // 积分必须为正整数
        $this->postJson('/admin/cash/integral/grants', $this->grantPayload($member, [
            'items' => [['member_id' => $member->id, 'points' => 0]],
        ]))->assertStatus(422);

        $id = $this->postJson('/admin/cash/integral/grants', $this->grantPayload($member))->json('data.id');

        // 未提交不能审核
        $this->postJson("/admin/cash/integral/grants/{$id}/audit")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有待审核的发放单可以审核，当前状态：草稿');

        $this->postJson("/admin/cash/integral/grants/{$id}/void")->assertOk()->assertJsonPath('data.status', CashIntegralGrant::STATUS_VOID);

        $this->postJson("/admin/cash/integral/grants/{$id}/void")
            ->assertStatus(422)
            ->assertJsonPath('message', '已审核或已作废的发放单不能作废');
        $this->postJson("/admin/cash/integral/grants/{$id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态的发放单可以提交');

        $this->assertSame(0, (int) $member->fresh()->points);
        $this->assertSame(0, MemberConsumeLog::query()->where('member_id', $member->id)->count());
    }

    public function test_grant_filters(): void
    {
        $matched = $this->member();
        $other = $this->member();

        $id = $this->postJson('/admin/cash/integral/grants', $this->grantPayload($matched, [
            'grant_type' => CashIntegralGrant::TYPE_ACTIVITY,
        ]))->json('data.id');

        $otherNo = $this->postJson('/admin/cash/integral/grants', $this->grantPayload($other, [
            'grant_date' => '2020-01-01',
        ]))->json('data.grant_no');

        $this->getJson('/admin/cash/integral/grants?member_id='.$matched->id)
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.id', $id);

        $this->getJson('/admin/cash/integral/grants?keyword='.$matched->name)
            ->assertOk()->assertJsonPath('data.total', 1);

        $this->getJson('/admin/cash/integral/grants?grant_type='.CashIntegralGrant::TYPE_ACTIVITY)
            ->assertOk()->assertJsonPath('data.total', 1);

        $this->getJson('/admin/cash/integral/grants?status=0')
            ->assertOk()->assertJsonPath('data.total', 2);

        $this->getJson('/admin/cash/integral/grants?date_start=2025-01-01')
            ->assertOk()->assertJsonPath('data.total', 1);

        $this->getJson('/admin/cash/integral/grants?grant_no='.$otherNo)
            ->assertOk()->assertJsonPath('data.total', 1);
    }
}
