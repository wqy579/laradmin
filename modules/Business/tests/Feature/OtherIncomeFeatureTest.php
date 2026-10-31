<?php

namespace Tests\Business\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Order\Models\Receive;
use Tests\TestCase;

/**
 * B9 其它收入单验收测试（只读自 receives 表，receive_type=2）。
 *
 * 覆盖验收标准：
 *  - 列表/统计的日期区间：end_date 当天必须计入（date cast 写入 sqlite 是
 *    'YYYY-MM-DD 00:00:00'，字符串上界 `<=` 会静默丢掉当天那笔，MySQL 抓不到）
 */
class OtherIncomeFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/business/other-income';

    private int $seq = 0;

    private function createReceive(string $date, float $amount, int $status = 1, int $type = 2): void
    {
        // 走 Eloquent 写入，复现真实写入形态（date cast → 'YYYY-MM-DD 00:00:00'）
        Receive::create([
            'receive_no' => 'OI-'.str_pad((string) ++$this->seq, 4, '0', STR_PAD_LEFT),
            'receive_type' => $type,
            'amount' => $amount,
            'receive_date' => $date,
            'status' => $status,
        ]);
    }

    public function test_list_only_contains_other_income_type(): void
    {
        $this->actingAsAdmin();

        $this->createReceive('2026-10-10', 10.00, type: 1);
        $this->createReceive('2026-10-10', 20.00);

        $data = $this->getJson(self::BASE)->assertOk()->json('data');
        $this->assertSame(1, $data['total']);
        $this->assertSame(20.00, (float) $data['list'][0]['amount']);
    }

    public function test_end_date_day_itself_is_included_in_list_and_statistics(): void
    {
        $this->actingAsAdmin();

        $this->createReceive('2026-10-10', 10.00);
        $this->createReceive('2026-10-15', 88.88);
        $this->createReceive('2026-10-16', 77.77);

        $range = $this->getJson(self::BASE.'?start_date=2026-10-01&end_date=2026-10-15')
            ->assertOk()->json('data');
        $this->assertSame(2, $range['total']);
        $dates = array_map(fn ($row) => substr((string) $row['receive_date'], 0, 10), $range['list']);
        $this->assertContains('2026-10-15', $dates);
        $this->assertNotContains('2026-10-16', $dates);

        // 统计（只算已审核）：区间内两笔都在边界上，end_date 当天那笔必须计入
        $stats = $this->getJson(self::BASE.'/statistics?start_date=2026-10-01&end_date=2026-10-15')
            ->assertOk()->json('data');
        $this->assertSame(2, (int) $stats['count']);
        $this->assertSame(98.88, (float) $stats['total_amount']);
    }

    public function test_export_returns_bom_csv_following_list_filters(): void
    {
        $this->actingAsAdmin();

        $this->createReceive('2026-10-10', 10.00);
        $this->createReceive('2026-10-15', 88.88);
        $this->createReceive('2026-10-16', 77.77);
        $this->createReceive('2026-10-12', 55.55, status: 0);

        $res = $this->get(self::BASE.'/export?status=1&start_date=2026-10-01&end_date=2026-10-15');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('单号,往来单位,金额,收入日期,付款方式,经手人,状态,备注', $body);
        // end_date 当天那笔必须在；区间外（次日）与状态外（草稿）不得混入
        $this->assertStringContainsString(',88.88,2026-10-15,', $body);
        $this->assertStringContainsString(',10.00,2026-10-10,', $body);
        $this->assertStringNotContainsString('77.77', $body);
        $this->assertStringNotContainsString('55.55', $body);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
    }
}
