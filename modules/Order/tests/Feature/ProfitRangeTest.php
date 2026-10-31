<?php

namespace Tests\Order\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 利润查询日期范围回归。
 *
 * 坑一：index() 曾只读 month 参数，前端传的 start_date/end_date 被忽略，
 * 日期范围筛选是死控件。
 * 坑二：date cast 列在 sqlite 里存成 'YYYY-MM-DD 00:00:00'，字符串上界
 * `<= 'YYYY-MM-DD'` 会静默丢掉 end_date 当天那一笔——必须开区间上界，
 * 所以「end_date 当天计入」必须显式断言，CI 的 MySQL 抓不到这个差异。
 */
class ProfitRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function insertReceive(string $no, string $date, float $amount, int $status = 1): void
    {
        DB::table('receives')->insert([
            'receive_no' => $no,
            'amount' => $amount,
            'receive_date' => $date,
            'status' => $status,
        ]);
    }

    private function insertPay(string $no, string $date, float $amount, int $status = 1): void
    {
        DB::table('pays')->insert([
            'pay_no' => $no,
            'amount' => $amount,
            'pay_date' => $date,
            'status' => $status,
        ]);
    }

    private function insertExpense(string $no, string $date, float $amount, int $status = 1): void
    {
        DB::table('expenses')->insert([
            'expense_no' => $no,
            'expense_type' => '运费',
            'amount' => $amount,
            'expense_date' => $date,
            'status' => $status,
        ]);
    }

    public function test_end_date_day_itself_is_included_and_next_day_excluded(): void
    {
        // 一笔落在 end_date 当天，一笔落在 end_date+1：只有前者应进汇总
        $this->insertReceive('RC-ON-END', '2026-10-15', 100.50);
        $this->insertReceive('RC-AFTER-END', '2026-10-16', 999.99);
        // 同边界各一笔在付款/费用表，验证三张表共用同一区间
        $this->insertPay('PY-ON-END', '2026-10-15', 20.25);
        $this->insertPay('PY-AFTER-END', '2026-10-16', 88.88);
        $this->insertExpense('EX-ON-END', '2026-10-15', 10.00);
        $this->insertExpense('EX-AFTER-END', '2026-10-16', 77.77);

        $res = $this->getJson('admin/business/profit?start_date=2026-10-01&end_date=2026-10-15');

        $res->assertOk()->assertJsonPath('code', 200);
        $this->assertEqualsWithDelta(100.50, $res->json('data.stats.total_income'), 0.001);
        $this->assertEqualsWithDelta(30.25, $res->json('data.stats.total_expense'), 0.001);
        $this->assertSame(1, $res->json('data.list.0.count'));
        $this->assertSame(1, $res->json('data.list.1.count'));
        $this->assertSame(1, $res->json('data.list.2.count'));
    }

    public function test_start_date_boundary_excludes_day_before(): void
    {
        $this->insertReceive('RC-BEFORE-START', '2026-10-31', 50.00);
        $this->insertReceive('RC-ON-START', '2026-11-01', 60.00);

        $res = $this->getJson('admin/business/profit?start_date=2026-11-01&end_date=2026-11-30');

        $res->assertOk();
        $this->assertEqualsWithDelta(60.00, $res->json('data.stats.total_income'), 0.001);
        $this->assertSame(1, $res->json('data.list.0.count'));
    }

    public function test_only_status_approved_rows_are_counted(): void
    {
        $this->insertReceive('RC-DRAFT', '2026-10-10', 500.00, status: 0);
        $this->insertReceive('RC-OK', '2026-10-10', 200.00);

        $res = $this->getJson('admin/business/profit?start_date=2026-10-01&end_date=2026-10-31');

        $res->assertOk();
        $this->assertEqualsWithDelta(200.00, $res->json('data.stats.total_income'), 0.001);
        $this->assertSame(1, $res->json('data.list.0.count'));
    }

    public function test_month_presets_and_default_still_work(): void
    {
        // 用 NoOverflow 造日期：31 号跑 subMonth 会溢出回本月，两个日期会撞进同一个月
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString();
        $thisMonth = now()->startOfMonth()->addDays(3)->toDateString();
        $this->insertReceive('RC-LAST', $lastMonth, 111.00);
        $this->insertReceive('RC-THIS', $thisMonth, 222.00);

        $last = $this->getJson('admin/business/profit?month=last');
        $last->assertOk();
        $this->assertEqualsWithDelta(111.00, $last->json('data.stats.total_income'), 0.001);

        // 上月可能落在本季度内（如 5 月的上月 4 月同在 Q2），期望值按数据算
        $quarter = $this->getJson('admin/business/profit?month=quarter');
        $quarter->assertOk();
        $lastInQuarter = CarbonImmutable::parse($lastMonth)->startOfQuarter()
            ->eq(CarbonImmutable::parse($thisMonth)->startOfQuarter());
        $this->assertEqualsWithDelta($lastInQuarter ? 333.00 : 222.00, $quarter->json('data.stats.total_income'), 0.001);

        // 默认口径不变：当前自然月
        $default = $this->getJson('admin/business/profit');
        $default->assertOk();
        $this->assertEqualsWithDelta(222.00, $default->json('data.stats.total_income'), 0.001);
    }

    public function test_range_is_echoed_back(): void
    {
        $res = $this->getJson('admin/business/profit?start_date=2026-10-05&end_date=2026-10-20');

        $res->assertOk();
        $this->assertSame('2026-10-05', $res->json('data.start_date'));
        $this->assertSame('2026-10-20', $res->json('data.end_date'));
    }

    public function test_export_returns_bom_csv_following_range(): void
    {
        $this->insertReceive('RC-IN', '2026-10-15', 100.50);
        $this->insertReceive('RC-OUT', '2026-10-16', 999.99);

        $res = $this->get('admin/business/profit/export?start_date=2026-10-01&end_date=2026-10-15');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('项目,金额,笔数', $body);
        $this->assertStringContainsString('收款收入,100.50,1', $body);
        $this->assertStringNotContainsString('999.99', $body);
        $this->assertMatchesRegularExpression('/合计,\d+\.\d{2},\d+/', $body);
    }
}
