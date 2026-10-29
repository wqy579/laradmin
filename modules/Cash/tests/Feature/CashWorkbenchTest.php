<?php

namespace Tests\Cash\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashCardTxn;
use Modules\Cash\Models\CashFlow;
use Modules\Cash\Models\CashHandover;
use Tests\TestCase;

/**
 * D8 验收：出纳工作台（只读聚合，cash_ 表内自取，不依赖 B 域资金看板）
 *
 *  - summary 只取当日：非当日流水/刷卡记录一律剔除
 *  - 已退款刷卡记录不得计入当日收款（口径同 CashCardController::stat：只对 status=success 求和）
 *  - 账户余额合计 = 启用账户期初 + 启用账户全部流水净额，停用账户不参与
 *  - 待确认上交款取 status=pending，已确认不参与
 *  - trend 日期必须连续补零且升序，days 默认 7、上限 31
 *  - 金额合计与明细之和一致，空库出 0 不出 NaN
 */
class CashWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $account;

    private CashAccount $disabledAccount;

    private CashAccount $secondAccount;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '出纳']);

        $this->account = $this->account('现金主账户', 1000, CashAccount::STATUS_ENABLED, 1);
        $this->secondAccount = $this->account('银行辅助户', 0, CashAccount::STATUS_ENABLED, 2);
        $this->disabledAccount = $this->account('已停用户', 500, CashAccount::STATUS_DISABLED, 3);
    }

    private function account(string $name, float $opening, string $status, int $sort): CashAccount
    {
        return CashAccount::create([
            'name' => $name,
            'type' => CashAccount::TYPE_CASH,
            'account_no' => '62220202001122'.str_pad((string) $sort, 3, '0', STR_PAD_LEFT),
            'opening_balance' => $opening,
            'status' => $status,
            'sort' => $sort,
        ]);
    }

    private function flow(int $accountId, string $date, string $direction, float $amount): CashFlow
    {
        $this->seq++;

        return CashFlow::create([
            'account_id' => $accountId,
            'flow_no' => 'LS'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'flow_date' => $date,
            'direction' => $direction,
            'summary' => '工作台测试流水',
            'amount' => $amount,
        ]);
    }

    private function cardTxn(string $time, float $amount, string $status): CashCardTxn
    {
        $this->seq++;

        return CashCardTxn::create([
            'serial_no' => 'TXN'.str_pad((string) $this->seq, 8, '0', STR_PAD_LEFT),
            'txn_time' => $time,
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'salesman_id' => 9,
            'salesman_name' => '张三',
            'card_type' => 'debit',
            'card_no' => '622202123456789'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'card_last4' => '0123',
            'amount' => $amount,
            'fee' => 3,
            'status' => $status,
        ]);
    }

    private function handover(float $amount, string $status): CashHandover
    {
        $this->seq++;

        return CashHandover::create([
            'handover_no' => 'SC'.date('Ymd').str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'handover_date' => now()->toDateString(),
            'salesman_id' => 9,
            'salesman_name' => '张三',
            'account_id' => $this->account->id,
            'amount' => $amount,
            'status' => $status,
        ]);
    }

    public function test_summary_only_counts_today_and_excludes_refunded_card_txn(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // 当日：收入 300 / 支出 100
        $this->flow($this->account->id, $today, CashFlow::DIRECTION_INCOME, 300);
        $this->flow($this->account->id, $today, CashFlow::DIRECTION_EXPENSE, 100);
        // 非当日：不得进当日合计
        $this->flow($this->account->id, $yesterday, CashFlow::DIRECTION_INCOME, 999);
        // 停用账户流水：不得进账户余额合计
        $this->flow($this->disabledAccount->id, $yesterday, CashFlow::DIRECTION_EXPENSE, 50);

        // 当日成功 500 + 当日已退款 -50 + 非当日成功 700
        $this->cardTxn($today.' 10:00:00', 500, CashCardTxn::STATUS_SUCCESS);
        $this->cardTxn($today.' 11:00:00', -50, CashCardTxn::STATUS_REFUND);
        $this->cardTxn($yesterday.' 10:00:00', 700, CashCardTxn::STATUS_SUCCESS);

        $this->handover(200, CashHandover::STATUS_PENDING);
        $this->handover(300, CashHandover::STATUS_CONFIRMED);

        $resp = $this->getJson('/admin/cash/dashboard/summary')->assertOk();
        $data = $resp->json('data');

        // 金额按既有测试口径断言（整数值经 JSON 序列化为 int，先转 float 再比）
        $this->assertSame(300.0, (float) $data['today_income']);
        $this->assertSame(100.0, (float) $data['today_expense']);
        $this->assertSame(200.0, (float) $data['today_net']);

        // 退款行与跨日行都剔除：只剩 500 / 1 笔
        $this->assertSame(500.0, (float) $data['today_card_amount']);
        $this->assertSame(1, $data['today_card_count']);

        // 启用账户：期初 1000+0 +（当日 300-100 + 昨日 999）；停用户的 -50 不参与
        $this->assertSame(2199.0, (float) $data['account_total']);
        $this->assertSame(2, $data['account_count']);

        $this->assertSame(1, $data['pending_handover_count']);
        $this->assertSame(200.0, (float) $data['pending_handover_amount']);
    }

    public function test_summary_trend_amounts_match_detail_sum(): void
    {
        $today = now()->toDateString();

        $this->flow($this->account->id, $today, CashFlow::DIRECTION_INCOME, 120.5);
        $this->flow($this->secondAccount->id, $today, CashFlow::DIRECTION_INCOME, 79.5);
        $this->flow($this->account->id, $today, CashFlow::DIRECTION_EXPENSE, 60.25);

        $summary = $this->getJson('/admin/cash/dashboard/summary')->assertOk()->json('data');
        $detailIncome = (float) CashFlow::query()->whereDate('flow_date', $today)
            ->where('direction', CashFlow::DIRECTION_INCOME)->sum('amount');
        $detailExpense = (float) CashFlow::query()->whereDate('flow_date', $today)
            ->where('direction', CashFlow::DIRECTION_EXPENSE)->sum('amount');

        $this->assertSame(round($detailIncome, 2), (float) $summary['today_income']);
        $this->assertSame(round($detailExpense, 2), (float) $summary['today_expense']);

        // 近 7 日合计与 summary 当日合计同源（窗口含当日）
        $trend = $this->getJson('/admin/cash/dashboard/trend?days=7')->assertOk()->json('data.list');
        $this->assertSame(round($detailIncome, 2), round(array_sum(array_column($trend, 'income')), 2));
        $this->assertSame(round($detailExpense, 2), round(array_sum(array_column($trend, 'expense')), 2));
    }

    public function test_trend_fills_missing_days_in_ascending_order(): void
    {
        $today = now()->toDateString();
        // 窗口内只造两天（窗口首日与中间一天），其余必须补 0
        $this->flow($this->account->id, now()->subDays(6)->toDateString(), CashFlow::DIRECTION_INCOME, 111);
        $this->flow($this->account->id, now()->subDays(3)->toDateString(), CashFlow::DIRECTION_EXPENSE, 222);
        // 窗口外不得出现
        $this->flow($this->account->id, now()->subDays(10)->toDateString(), CashFlow::DIRECTION_INCOME, 888);

        $data = $this->getJson('/admin/cash/dashboard/trend?days=7')->assertOk()->json('data');

        $this->assertSame(7, $data['days']);
        $this->assertCount(7, $data['list']);

        $dates = array_column($data['list'], 'date');
        $this->assertSame(now()->subDays(6)->toDateString(), $dates[0]);
        $this->assertSame($today, $dates[6]);
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'trend 日期必须升序');
        $this->assertSame($dates, array_values(array_unique($dates)), 'trend 日期不得重复');

        $byDate = array_column($data['list'], null, 'date');
        $this->assertSame(111.0, (float) $byDate[now()->subDays(6)->toDateString()]['income']);
        $this->assertSame(222.0, (float) $byDate[now()->subDays(3)->toDateString()]['expense']);
        // 无流水的日子必须补 0，不能缺行
        $this->assertSame(0.0, (float) $byDate[now()->subDays(5)->toDateString()]['income']);
        $this->assertSame(0.0, (float) $byDate[now()->subDays(5)->toDateString()]['expense']);
        $this->assertSame(0.0, (float) $byDate[$today]['income']);
    }

    public function test_trend_days_default_and_cap(): void
    {
        $this->assertCount(7, $this->getJson('/admin/cash/dashboard/trend')->assertOk()->json('data.list'));
        $this->assertCount(7, $this->getJson('/admin/cash/dashboard/trend?days=abc')->assertOk()->json('data.list'));
        $this->assertCount(31, $this->getJson('/admin/cash/dashboard/trend?days=999')->assertOk()->json('data.list'));
        $this->assertCount(3, $this->getJson('/admin/cash/dashboard/trend?days=3')->assertOk()->json('data.list'));
    }

    public function test_empty_database_returns_zeroed_snapshot(): void
    {
        CashAccount::query()->delete();

        $data = $this->getJson('/admin/cash/dashboard/summary')->assertOk()->json('data');

        foreach ([
            'today_income', 'today_expense', 'today_net', 'today_card_amount',
            'account_total', 'pending_handover_amount',
        ] as $key) {
            $this->assertSame(0.0, (float) $data[$key], $key.' 应为 0');
        }
        $this->assertSame(0, $data['today_card_count']);
        $this->assertSame(0, $data['account_count']);
        $this->assertSame(0, $data['pending_handover_count']);

        $trend = $this->getJson('/admin/cash/dashboard/trend?days=7')->assertOk()->json('data.list');
        $this->assertCount(7, $trend);
        $this->assertSame(0.0, (float) array_sum(array_column($trend, 'income')));
    }

    public function test_dashboard_requires_login(): void
    {
        $this->withoutToken()->resetJwtState();
        $this->getJson('/admin/cash/dashboard/summary')->assertStatus(401);
        $this->getJson('/admin/cash/dashboard/trend')->assertStatus(401);
    }
}
