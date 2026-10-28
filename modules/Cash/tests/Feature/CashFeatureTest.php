<?php

namespace Tests\Cash\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashCardTxn;
use Modules\Cash\Models\CashFlow;
use Tests\TestCase;

/**
 * D1 验收：现金账户收支 + 刷卡记录
 *
 *  - 收支方向拆分正确、余额逐行累计（含日期筛选基线连续、跨分页连续）
 *  - 卡号中段脱敏（列表与导出都不吐完整卡号）
 *  - 退款仅「成功且当日」，非成功 / 非当日一律 422
 */
class CashFeatureTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();

        $this->account = CashAccount::create([
            'name' => '现金主账户',
            'type' => CashAccount::TYPE_CASH,
            'account_no' => '6222020200112233445',
            'opening_balance' => 1000,
            'status' => CashAccount::STATUS_ENABLED,
            'sort' => 1,
        ]);
    }

    private function flow(string $date, string $direction, float $amount, ?string $no = null): CashFlow
    {
        static $seq = 0;
        $seq++;

        return CashFlow::create([
            'account_id' => $this->account->id,
            'flow_no' => $no ?? sprintf('LS%06d', $seq),
            'flow_date' => $date,
            'direction' => $direction,
            'summary' => '测试流水',
            'amount' => $amount,
        ]);
    }

    private function cardTxn(array $overrides = []): CashCardTxn
    {
        static $seq = 0;
        $seq++;

        return CashCardTxn::create(array_merge([
            'serial_no' => 'TXN'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
            'txn_time' => now(),
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'salesman_id' => 9,
            'salesman_name' => '张三',
            'card_type' => 'debit',
            'card_no' => '6222021234567890123',
            'card_last4' => '0123',
            'amount' => 500,
            'fee' => 3,
            'status' => CashCardTxn::STATUS_SUCCESS,
        ], $overrides));
    }

    public function test_mask_card_no_keeps_head_and_tail_only(): void
    {
        $this->assertSame('6222********0123', CashCardTxn::maskCardNo('6222021234567890123'));
        $this->assertSame('6222********2234', CashCardTxn::maskCardNo('62220200001111222234'));
        // 短卡号：前4 + 剩余全遮蔽，绝不透出中段
        $this->assertSame('6222******', CashCardTxn::maskCardNo('6222020200'));
        $this->assertSame('****', CashCardTxn::maskCardNo('6202'));
        $this->assertSame('', CashCardTxn::maskCardNo(null));
    }

    public function test_flows_split_direction_and_accumulate_balance(): void
    {
        $this->flow('2026-10-02', CashFlow::DIRECTION_INCOME, 300);
        $this->flow('2026-10-03', CashFlow::DIRECTION_EXPENSE, 100);
        $this->flow('2026-10-04', CashFlow::DIRECTION_INCOME, 50);

        $r = $this->getJson("/admin/cash/accounts/{$this->account->id}/flows")->assertOk();
        $data = $r->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertSame(1000.0, (float) $data['base_balance']);
        $this->assertSame(350.0, (float) $data['income_total']);
        $this->assertSame(100.0, (float) $data['expense_total']);

        $balances = array_map('floatval', array_column($data['list'], 'balance'));
        $this->assertSame([1300.0, 1200.0, 1250.0], $balances);

        $first = $data['list'][0];
        $this->assertSame(300.0, (float) $first['income']);
        $this->assertSame(0.0, (float) $first['expense']);
        $this->assertSame('income', $first['direction']);
    }

    public function test_flows_date_filter_keeps_balance_continuous(): void
    {
        $this->flow('2026-09-20', CashFlow::DIRECTION_INCOME, 800);
        $this->flow('2026-09-25', CashFlow::DIRECTION_EXPENSE, 200);
        $this->flow('2026-10-02', CashFlow::DIRECTION_INCOME, 50);

        $data = $this->getJson("/admin/cash/accounts/{$this->account->id}/flows?start_date=2026-10-01&end_date=2026-10-31")
            ->assertOk()->json('data');

        $this->assertSame(1, $data['total']);
        // 基线 = 期初 1000 + (800 - 200) = 1600，窗口首行余额与真实账户连续
        $this->assertSame(1600.0, (float) $data['base_balance']);
        $this->assertSame(1650.0, (float) $data['list'][0]['balance']);
    }

    public function test_flows_running_balance_continuous_across_pages(): void
    {
        foreach (range(1, 5) as $i) {
            $this->flow('2026-10-0'.$i, CashFlow::DIRECTION_INCOME, 100);
        }
        $this->flow('2026-10-06', CashFlow::DIRECTION_EXPENSE, 30);

        $page1 = $this->getJson("/admin/cash/accounts/{$this->account->id}/flows?page=1&page_size=3")
            ->assertOk()->json('data');
        $page2 = $this->getJson("/admin/cash/accounts/{$this->account->id}/flows?page=2&page_size=3")
            ->assertOk()->json('data');

        $this->assertSame([1100.0, 1200.0, 1300.0], array_map('floatval', array_column($page1['list'], 'balance')));
        // 第二页基线要接着第一页末尾继续累计
        $this->assertSame([1400.0, 1500.0, 1470.0], array_map('floatval', array_column($page2['list'], 'balance')));
    }

    public function test_flows_direction_filter(): void
    {
        $this->flow('2026-10-02', CashFlow::DIRECTION_INCOME, 300);
        $this->flow('2026-10-03', CashFlow::DIRECTION_EXPENSE, 100);

        $data = $this->getJson("/admin/cash/accounts/{$this->account->id}/flows?direction=expense")
            ->assertOk()->json('data');

        $this->assertSame(1, $data['total']);
        $this->assertSame('expense', $data['list'][0]['direction']);
    }

    public function test_options_only_list_enabled_accounts(): void
    {
        CashAccount::create([
            'name' => '停用账户', 'type' => CashAccount::TYPE_BANK,
            'opening_balance' => 0, 'status' => CashAccount::STATUS_DISABLED, 'sort' => 2,
        ]);

        $list = $this->getJson('/admin/cash/accounts/options')->assertOk()->json('data.list');

        $this->assertCount(1, $list);
        $this->assertSame('现金主账户', $list[0]['name']);
        $this->assertSame('现金', $list[0]['type_label']);
    }

    public function test_card_list_masks_card_no_and_returns_stat(): void
    {
        $this->cardTxn();
        $this->cardTxn(['status' => CashCardTxn::STATUS_FAIL, 'amount' => 200, 'fee' => 0]);
        $this->cardTxn(['status' => CashCardTxn::STATUS_REFUND, 'amount' => -80, 'fee' => -1]);

        $data = $this->getJson('/admin/cash/card-txns')->assertOk()->json('data');

        $this->assertSame(3, $data['total']);
        foreach ($data['list'] as $row) {
            $this->assertStringNotContainsString('123456789', $row['card_no_masked']);
            $this->assertMatchesRegularExpression('/^\d{4}\*{8}\d{4}$/', $row['card_no_masked']);
        }
        // 统计条：总笔数、成功金额、失败笔数、手续费合计
        $this->assertSame(3, $data['stat']['count']);
        $this->assertSame(500.0, (float) $data['stat']['success_amount']);
        $this->assertSame(1, $data['stat']['fail_count']);
        $this->assertSame(4.0, (float) $data['stat']['fee_sum']);
    }

    public function test_refund_allows_success_same_day(): void
    {
        $txn = $this->cardTxn();

        $r = $this->postJson("/admin/cash/card-txns/{$txn->id}/refund")->assertOk();

        $this->assertSame('退款成功', $r->json('message'));
        $this->assertSame(CashCardTxn::STATUS_REFUND, $txn->fresh()->status);
        $this->assertSame(-500.0, (float) $txn->fresh()->amount);
        $this->assertSame(-3.0, (float) $txn->fresh()->fee);
        $this->assertNotNull($txn->fresh()->refunded_at);
    }

    public function test_refund_rejects_non_same_day(): void
    {
        $txn = $this->cardTxn(['txn_time' => now()->subDay()]);

        $r = $this->postJson("/admin/cash/card-txns/{$txn->id}/refund")->assertStatus(422);

        $this->assertSame('仅当日成功的流水可退款', $r->json('message'));
        $this->assertSame(CashCardTxn::STATUS_SUCCESS, $txn->fresh()->status);
    }

    public function test_refund_rejects_non_success_status(): void
    {
        $failed = $this->cardTxn(['status' => CashCardTxn::STATUS_FAIL]);
        $this->postJson("/admin/cash/card-txns/{$failed->id}/refund")
            ->assertStatus(422)
            ->assertJsonPath('message', '该流水当前状态不可退款');

        $refunded = $this->cardTxn(['status' => CashCardTxn::STATUS_REFUND]);
        $this->postJson("/admin/cash/card-txns/{$refunded->id}/refund")
            ->assertStatus(422);
    }

    public function test_card_export_never_leaks_full_card_no(): void
    {
        $this->cardTxn();

        $csv = $this->getJson('/admin/cash/card-txns/export')->assertOk()->getContent();

        $this->assertStringContainsString('6222********0123', $csv);
        $this->assertStringNotContainsString('6222021234567890123', $csv);
    }

    public function test_flow_export_has_totals_row(): void
    {
        $this->flow('2026-10-02', CashFlow::DIRECTION_INCOME, 300);
        $this->flow('2026-10-03', CashFlow::DIRECTION_EXPENSE, 100);

        $csv = $this->getJson("/admin/cash/accounts/{$this->account->id}/export")->assertOk()->getContent();

        $this->assertStringContainsString('收入,支出,余额', $csv);
        $this->assertStringContainsString('合计（2 条）', $csv);
        $this->assertStringContainsString('300.00', $csv);
    }
}
