<?php

namespace Tests\Business\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B8 资金看板验收测试。
 *
 * 覆盖验收标准：
 *  - 4 卡（现金 / 银行 / 今日收入 / 今日支出）与资金流水（cash_flows）口径一致
 *  - 近 7 日折线补齐全部日期（无流水日为 0，禁 NaN）
 *  - 待确认上交款只取 status=remitted（出纳未确认）
 *  - 空数据下不报错、不出现 NaN，折线仍为 7 个点全 0
 */
class FinanceB8FundDashboardFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/business/finance-fund-dashboard';

    private function addFlow(string $type, string $method, float $amount, ?string $date = null): void
    {
        static $seq = 0;
        DB::table('cash_flows')->insert([
            'flow_no' => 'CFTEST'.str_pad((string) (++$seq), 6, '0', STR_PAD_LEFT),
            'flow_type' => $type,
            'flow_date' => $date ?? now()->toDateString(),
            'amount' => $amount,
            'payment_method' => $method,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_empty_data_returns_zero_cards_and_seven_zero_trend_days(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson(self::BASE)->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');

        $this->assertEqualsWithDelta(0, $data['cash_balance'], 0.001);
        $this->assertEqualsWithDelta(0, $data['bank_balance'], 0.001);
        $this->assertEqualsWithDelta(0, $data['today_income'], 0.001);
        $this->assertEqualsWithDelta(0, $data['today_expense'], 0.001);
        $this->assertEqualsWithDelta(0, $data['income_ratio'], 0.001);
        $this->assertEqualsWithDelta(0, $data['expense_ratio'], 0.001);

        $this->assertCount(7, $data['trend']);
        foreach ($data['trend'] as $point) {
            $this->assertEqualsWithDelta(0, $point['income'], 0.001);
            $this->assertEqualsWithDelta(0, $point['expense'], 0.001);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $point['date']);
        }
        // JSON 里不得出现 NaN / Infinity
        $this->assertStringNotContainsString('NaN', $res->getContent());
        $this->assertStringNotContainsString('Infinity', $res->getContent());
    }

    public function test_cards_split_cash_and_bank_and_match_flow_signs(): void
    {
        $this->actingAsAdmin();

        // 现金类：收 1000（现金）+ 收 300（微信）- 付 200（现金）= 1100
        $this->addFlow('receive', '现金', 1000);
        $this->addFlow('receive', '微信', 300);
        $this->addFlow('pay', '现金', 200);
        // 银行类：收 5000（银行卡）- 费用 1000（bank 英文键）= 4000
        $this->addFlow('receive', '银行卡', 5000);
        $this->addFlow('expense', 'bank', 1000);
        // 挂账为信用类，不入两张资金卡，但计入今日收入
        $this->addFlow('receive', '挂账', 700);

        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        $this->assertEqualsWithDelta(1100, $data['cash_balance'], 0.001);
        $this->assertEqualsWithDelta(4000, $data['bank_balance'], 0.001);
        $this->assertEqualsWithDelta(7000, $data['today_income'], 0.001);
        $this->assertEqualsWithDelta(1200, $data['today_expense'], 0.001);
    }

    public function test_negative_pay_rows_and_receive_reversals_keep_signs_sane(): void
    {
        $this->actingAsAdmin();

        // 退货退款：flow_type=pay + 负数直插 → 仍算支出 300，现金净额不变（-300）
        $this->addFlow('receive', '现金', 1000);
        $this->addFlow('pay', '退货退款', -300);
        // 收款红冲：flow_type=receive + 负数直插 → 冲减收入
        $this->addFlow('receive', '现金', -400);

        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        $this->assertEqualsWithDelta(600, $data['cash_balance'], 0.001);   // 1000 - 400（退货退款不入卡）
        $this->assertEqualsWithDelta(600, $data['today_income'], 0.001);   // 1000 - 400
        $this->assertEqualsWithDelta(300, $data['today_expense'], 0.001);
    }

    public function test_trend_covers_seven_days_and_fills_missing_dates(): void
    {
        $this->actingAsAdmin();

        $this->addFlow('receive', '现金', 100, now()->toDateString());
        $this->addFlow('pay', '现金', 50, now()->subDays(3)->toDateString());
        // 窗口外（8 天前）不进折线
        $this->addFlow('receive', '现金', 999, now()->subDays(8)->toDateString());

        $trend = $this->getJson(self::BASE)->assertOk()->json('data.trend');

        $this->assertCount(7, $trend);
        $this->assertSame(now()->subDays(6)->toDateString(), $trend[0]['date']);
        $this->assertSame(now()->toDateString(), $trend[6]['date']);
        $this->assertEqualsWithDelta(0, $trend[0]['income'], 0.001);
        $this->assertEqualsWithDelta(100, $trend[6]['income'], 0.001);

        $byDate = array_column($trend, null, 'date');
        $this->assertEqualsWithDelta(50, $byDate[now()->subDays(3)->toDateString()]['expense'], 0.001);
        $this->assertEqualsWithDelta(0, $byDate[now()->subDays(5)->toDateString()]['income'], 0.001);
        $this->assertArrayNotHasKey(now()->subDays(8)->toDateString(), $byDate);
    }

    public function test_growth_rate_compares_against_yesterday(): void
    {
        $this->actingAsAdmin();

        $this->addFlow('receive', '现金', 100, now()->subDay()->toDateString());
        $this->addFlow('receive', '现金', 150, now()->toDateString());
        $this->addFlow('pay', '现金', 80, now()->subDay()->toDateString());
        $this->addFlow('pay', '现金', 40, now()->toDateString());

        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        $this->assertEqualsWithDelta(50, $data['income_ratio'], 0.001);
        $this->assertEqualsWithDelta(-50, $data['expense_ratio'], 0.001);
    }

    public function test_pending_handover_lists_only_remitted_rows(): void
    {
        $this->actingAsAdmin();

        $row = fn (string $no, string $status) => [
            'remit_no' => $no,
            'remit_date' => now()->toDateString(),
            'delivery_person_name' => '张三',
            'cash_amount' => 100,
            'bank_amount' => 50,
            'total_amount' => 150,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('delivery_remit')->insert([
            $row('SJ001', 'remitted'),
            $row('SJ002', 'pending'),
            $row('SJ003', 'confirmed'),
            $row('SJ004', 'remitted'),
        ]);

        $res = $this->getJson(self::BASE.'/pending-handover')->assertOk()->assertJson(['code' => 200]);
        $list = $res->json('data.list');

        $this->assertSame(2, $res->json('data.total'));
        $this->assertSame(['SJ001', 'SJ004'], array_column($list, 'remit_no'));
        $this->assertEqualsWithDelta(150, $list[0]['total_amount'], 0.001);
    }

    public function test_pending_handover_empty_returns_empty_list_not_error(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson(self::BASE.'/pending-handover')->assertOk()->assertJson(['code' => 200]);

        $this->assertSame([], $res->json('data.list'));
        $this->assertSame(0, $res->json('data.total'));
    }
}
