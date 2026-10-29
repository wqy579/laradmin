<?php

namespace Tests\Agreement\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Agreement\Models\Agreement;
use Tests\TestCase;

/**
 * F4 验收 2/3：协议三态流转 + 兑现率。
 *
 *  - 三态：生效中(绿/success) / 已到期(灰/info) / 已终止(红/danger)
 *  - 到期不落人工维护：查询前按 end_date 与今天比对惰性刷新
 *  - 终止是终态：不能再改、不能再兑现
 *  - 兑现率 = 已兑现 / 协议额，协议额为 0 记 0（不出 NaN）
 *  - 兑现累计不得超过协议额；协议额不得改到小于已兑现额
 */
class AgreementLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '协议专员']);
    }

    public function test_status_tag_types_are_green_grey_red(): void
    {
        $this->assertSame('success', Agreement::statusTagType('active'));
        $this->assertSame('info', Agreement::statusTagType('expired'));
        $this->assertSame('danger', Agreement::statusTagType('terminated'));
        $this->assertSame(['生效中', '已到期', '已终止'], [
            Agreement::statusLabel('active'),
            Agreement::statusLabel('expired'),
            Agreement::statusLabel('terminated'),
        ]);
    }

    public function test_new_agreement_is_active_and_carries_tag_type(): void
    {
        $resp = $this->postJson('/admin/agr/agreements', $this->payload([
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(90)->toDateString(),
        ]));

        $resp->assertOk()->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_tag_type', 'success')
            ->assertJsonPath('data.redeemed_amount', 0)
            ->assertJsonPath('data.redemption_rate', 0);
        $this->assertMatchesRegularExpression('/^XY\d{8}0{5}1$/', $resp->json('data.agreement_no'));
    }

    public function test_query_lazy_expires_overdue_agreements(): void
    {
        $overdue = $this->make('active', now()->subDays(60), now()->subDays(2));
        $running = $this->make('active', now()->subDays(10), now()->addDays(20));
        $today = $this->make('active', now()->subDays(10), now());

        $resp = $this->getJson('/admin/agr/agreements')->assertOk();

        // 到期日已过 → 已到期；到期日就是今天 → 仍生效中
        $this->assertSame('expired', Agreement::find($overdue->id)->status);
        $this->assertSame('active', Agreement::find($running->id)->status);
        $this->assertSame('active', Agreement::find($today->id)->status);

        $this->assertSame(1, $resp->json('data.stat.expired_count'));
        $this->assertSame(2, $resp->json('data.stat.active_count'));

        // 已到期后按 status 过滤必须能查到
        $this->getJson('/admin/agr/agreements?status=expired')
            ->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $overdue->id);
    }

    public function test_terminate_is_final_state(): void
    {
        $agreement = $this->make('active', now()->subDays(5), now()->addDays(30), 10000, 2000);

        $resp = $this->postJson("/admin/agr/agreements/{$agreement->id}/terminate", ['terminated_date' => '2026-10-20']);
        $resp->assertOk()->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.status_tag_type', 'danger')
            ->assertJsonPath('data.terminated_date', '2026-10-20');

        // 终态：不能再兑现、不能再改、不能再终止
        $this->postJson("/admin/agr/agreements/{$agreement->id}/redeem", ['amount' => 100])->assertStatus(422);
        $this->putJson("/admin/agr/agreements/{$agreement->id}", ['title' => '改名'])->assertStatus(422);
        $this->postJson("/admin/agr/agreements/{$agreement->id}/terminate")->assertStatus(422);

        // 已到期协议也可以终止
        $expired = $this->make('expired', now()->subDays(30), now()->subDays(1));
        $this->postJson("/admin/agr/agreements/{$expired->id}/terminate")
            ->assertOk()->assertJsonPath('data.status', 'terminated');
    }

    public function test_redemption_rate_is_redeemed_over_amount(): void
    {
        $agreement = $this->make('active', now()->subDays(5), now()->addDays(30), 8000, 0);

        $this->postJson("/admin/agr/agreements/{$agreement->id}/redeem", ['amount' => 2000])
            ->assertOk()
            ->assertJsonPath('data.redeemed_amount', 2000)
            ->assertJsonPath('data.redemption_rate', 25)
            ->assertJsonPath('data.pending_amount', 6000);

        // 增量累计
        $this->postJson("/admin/agr/agreements/{$agreement->id}/redeem", ['amount' => 2909.5])
            ->assertOk()->assertJsonPath('data.redemption_rate', 61.37);

        // 兑现满额 → 100%
        $this->postJson("/admin/agr/agreements/{$agreement->id}/redeem", ['amount' => 3090.5])
            ->assertOk()->assertJsonPath('data.redemption_rate', 100)
            ->assertJsonPath('data.pending_amount', 0);
    }

    public function test_redeem_rejects_bad_amounts(): void
    {
        $agreement = $this->make('active', now()->subDays(5), now()->addDays(30), 1000, 900);

        $this->postJson("/admin/agr/agreements/{$agreement->id}/redeem", ['amount' => 200])->assertStatus(422);
        $this->postJson("/admin/agr/agreements/{$agreement->id}/redeem", ['amount' => 0])->assertStatus(422);
        $this->postJson("/admin/agr/agreements/{$agreement->id}/redeem", ['amount' => -50])->assertStatus(422);
        $this->postJson("/admin/agr/agreements/{$agreement->id}/redeem")->assertStatus(422);

        $this->assertSame(900.0, (float) $agreement->refresh()->redeemed_amount);
    }

    public function test_zero_amount_agreement_yields_zero_rate_not_nan(): void
    {
        $agreement = Agreement::create([
            'agreement_no' => 'XY-ZERO',
            'title' => '零额协议',
            'customer_name' => '客户甲',
            'type' => 'display',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'amount' => 0,
            'redeemed_amount' => 0,
            'status' => 'active',
        ]);

        $this->assertSame(0.0, $agreement->redemptionRate());

        $resp = $this->getJson('/admin/agr/agreements/stats')->assertOk();
        // JSON 里 0.0 序列化成 0，断言用松散比较：出 0 不出 null/NaN/Inf
        $this->assertEqualsWithDelta(0.0, $resp->json('data.stat.redemption_rate'), 0.0001);
        $this->assertEqualsWithDelta(0.0, $resp->json('data.list.0.redemption_rate'), 0.0001);
        $this->assertIsNumeric($resp->json('data.list.0.redemption_rate'));
    }

    public function test_amount_cannot_shrink_below_redeemed(): void
    {
        $agreement = $this->make('active', now()->subDays(5), now()->addDays(30), 10000, 4000);

        $this->putJson("/admin/agr/agreements/{$agreement->id}", ['amount' => 3000])->assertStatus(422);
        $this->putJson("/admin/agr/agreements/{$agreement->id}", ['amount' => 8000])
            ->assertOk()->assertJsonPath('data.redemption_rate', 50);
    }

    public function test_update_past_end_date_flips_active_to_expired(): void
    {
        $agreement = $this->make('active', now()->subDays(5), now()->addDays(30));

        $this->putJson("/admin/agr/agreements/{$agreement->id}", [
            'end_date' => now()->subDay()->toDateString(),
        ])->assertOk()->assertJsonPath('data.status', 'expired');
    }

    public function test_create_rejects_bad_dates(): void
    {
        $this->postJson('/admin/agr/agreements', $this->payload([
            'start_date' => '2026-12-31',
            'end_date' => '2026-01-01',
        ]))->assertStatus(422);
    }

    public function test_display_stats_filters_by_type_and_sorts_by_rate(): void
    {
        $low = $this->make('active', now()->subDays(5), now()->addDays(30), 10000, 2000, 'display');
        $high = $this->make('active', now()->subDays(5), now()->addDays(30), 10000, 9000, 'display');
        $general = $this->make('active', now()->subDays(5), now()->addDays(30), 10000, 10000, 'general');

        $resp = $this->getJson('/admin/agr/agreements/stats')->assertOk();

        $ids = array_column($resp->json('data.list'), 'id');
        $this->assertSame([$high->id, $low->id], $ids, '统计页只应列陈列协议，且按兑现率降序');
        $this->assertNotContains($general->id, $ids);
        $this->assertSame(2, $resp->json('data.total'));

        // 顶部聚合覆盖全部陈列协议：(2000+9000)/20000
        $stat = $resp->json('data.stat');
        $this->assertSame(20000.0, (float) $stat['amount']);
        $this->assertSame(11000.0, (float) $stat['redeemed_amount']);
        $this->assertSame(55.0, (float) $stat['redemption_rate']);
        $this->assertSame('陈列协议', $resp->json('data.list.0.type_label'));
    }

    public function test_agreement_with_redemption_cannot_be_deleted(): void
    {
        $fresh = $this->make('active', now()->subDays(5), now()->addDays(30), 1000, 0);
        $used = $this->make('active', now()->subDays(5), now()->addDays(30), 1000, 100);

        $this->deleteJson("/admin/agr/agreements/{$used->id}")->assertStatus(422);
        $this->deleteJson("/admin/agr/agreements/{$fresh->id}")->assertOk();
        $this->assertNull(Agreement::find($fresh->id));
        $this->assertNotNull(Agreement::find($used->id));
    }

    // ---------- helpers ----------

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => '陈列协议-门店堆头',
            'customer_id' => 1,
            'customer_name' => '连凯超市',
            'type' => 'display',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'amount' => 10000,
        ], $overrides);
    }

    private function make(string $status, \DateTimeInterface|string $start, \DateTimeInterface|string $end, float $amount = 10000, float $redeemed = 0, string $type = 'display'): Agreement
    {
        $fmt = fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d;

        return Agreement::create([
            'agreement_no' => 'XY'.strtoupper(bin2hex(random_bytes(5))),
            'title' => '协议-'.substr(md5($status.$amount.microtime().random_int(1, 99999)), 0, 6),
            'customer_id' => 1,
            'customer_name' => '连凯超市',
            'type' => $type,
            'start_date' => $fmt($start),
            'end_date' => $fmt($end),
            'amount' => $amount,
            'redeemed_amount' => $redeemed,
            'status' => $status,
        ]);
    }
}
