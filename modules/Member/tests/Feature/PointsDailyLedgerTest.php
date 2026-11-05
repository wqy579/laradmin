<?php

namespace Tests\Member\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Modules\Member\Models\MemberLevel;
use Tests\TestCase;

/**
 * 积分日台账（QA2 D-01）：按「会员 × 自然日」的
 * 昨日积分 / 积分变动 / 兑换积分 / 今日积分 滚动口径。
 *
 * 关键断言是「窗口首日期初吃到窗口前历史累计」——
 * Cash 域期间汇总按 start_date 截断，日台账不行，首日昨日积分必须含历史。
 */
class PointsDailyLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function makeMember(string $name): Member
    {
        return Member::create([
            'member_no' => 'HY'.now()->format('Ymd').random_int(100000, 999999),
            'customer_id' => null,
            'name' => $name,
            'level_id' => MemberLevel::where('level_no', 'LV0')->firstOrFail()->id,
            'points' => 0,
            'balance' => 0,
            'total_consume' => 0,
            'total_orders' => 0,
            'status' => 1,
            'register_date' => now()->toDateString(),
        ]);
    }

    private function addLog(Member $m, string $time, int $earn, int $use, string $type = 'points_grant'): void
    {
        static $seq = 0;
        $log = MemberConsumeLog::create([
            'log_no' => 'LOG'.str_pad((string) ++$seq, 8, '0', STR_PAD_LEFT),
            'member_id' => $m->id,
            'member_no' => $m->member_no,
            'member_name' => $m->name,
            'consume_type' => $type,
            'direction' => $earn > 0 ? 'in' : 'out',
            'earn_points' => $earn,
            'use_points' => $use,
            'points_after' => $earn - $use,
        ]);
        // created_at 不在 fillable，历史日期要显式回写
        MemberConsumeLog::whereKey($log->id)->update(['created_at' => $time]);
    }

    public function test_daily_ledger_rolls_yday_change_exchange_today(): void
    {
        $this->actingAsAdmin();
        $m = $this->makeMember('台账甲');

        // 窗口前历史：+100 → 窗口首日昨日积分应为 100
        $this->addLog($m, '2026-03-01 10:00:00', 100, 0);
        // 03-05：发放 50、兑换 20 → 昨日 100 / 变动 50 / 兑换 20 / 今日 130
        $this->addLog($m, '2026-03-05 09:00:00', 50, 0, 'points_grant');
        $this->addLog($m, '2026-03-05 18:00:00', 0, 20, 'points_deduct');
        // 03-06：无积分变动的流水必须被忽略，台账不出 03-06 行
        $this->addLog($m, '2026-03-06 12:00:00', 0, 0, 'order');
        // 03-07：昨日应为 130（100+50-20），无新变动
        $this->addLog($m, '2026-03-07 08:00:00', 10, 5, 'points_grant');

        $res = $this->getJson('/admin/business/member-consume-log/daily-ledger?member_id='.$m->id
           .'&start_date=2026-03-05&end_date=2026-03-07')->assertOk();

        $rows = collect($res->json('data.list'))->keyBy('stat_date');
        $this->assertSame(2, $res->json('data.total'), '零积分变动不产生台账行');

        $d5 = $rows->get('2026-03-05');
        $this->assertSame(100, $d5['points_yday']);
        $this->assertSame(50, $d5['points_change']);
        $this->assertSame(20, $d5['points_exchange']);
        $this->assertSame(130, $d5['points_today']);

        $d7 = $rows->get('2026-03-07');
        $this->assertSame(130, $d7['points_yday']);
        $this->assertSame(10, $d7['points_change']);
        $this->assertSame(5, $d7['points_exchange']);
        $this->assertSame(135, $d7['points_today']);
    }

    public function test_daily_ledger_requires_admin_auth(): void
    {
        $this->getJson('/admin/business/member-consume-log/daily-ledger')->assertUnauthorized();
    }
}
