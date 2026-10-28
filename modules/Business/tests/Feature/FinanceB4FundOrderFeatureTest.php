<?php

namespace Tests\Business\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Seeders\LedgerSubjectSeeder;
use Tests\TestCase;

/**
 * B4 资金单族（6 类）验收测试。
 *
 * 覆盖验收标准：
 *  - 每单保存写 cash_flows 资金流水 + fin_fund_orders 留痕
 *  - 余额不足阻断（调减超过往来当前余额时 422）
 *  - 调账同步应收应付（审核通过经 VoucherService 记凭证并回写 voucher_id）
 *  - 借款还款登记：未结清 / 部分偿还 / 已结清 状态推进与逾期标记
 */
class FinanceB4FundOrderFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/business/finance-fund-orders';

    private function seedSubjects(): void
    {
        $seeder = new LedgerSubjectSeeder;
        $seeder->setContainer(app());
        $seeder->callWith([LedgerSubjectSeeder::class]);
    }

    /** 为客户 11 造一笔已审应收账款分录，作为「往来当前余额」基线 */
    private function seedCustomerReceivable(int $auxId, float $amount): void
    {
        $subjectId = (int) DB::table('ledger_subjects')->where('code', '1122')->value('id');
        $expenseId = (int) DB::table('ledger_subjects')->where('code', '6602')->value('id');

        $voucherId = DB::table('ledger_vouchers')->insertGetId([
            'voucher_no' => '记-TEST-'.$auxId.'-'.uniqid(),
            'voucher_date' => now()->toDateString(),
            'period' => now()->toDateString(),
            'attachments_count' => 0,
            'total_debit' => $amount,
            'total_credit' => $amount,
            'status' => 'audited',
            'maker_name' => '测试员',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ledger_voucher_entries')->insert([
            'voucher_id' => $voucherId, 'line_no' => 1,
            'subject_id' => $subjectId, 'subject_code' => '1122', 'subject_name' => '应收账款',
            'aux_type' => 'customer', 'aux_id' => $auxId, 'aux_name' => '甲乙公司',
            'summary' => '销售挂账', 'debit_amount' => $amount, 'credit_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ledger_voucher_entries')->insert([
            'voucher_id' => $voucherId, 'line_no' => 2,
            'subject_id' => $expenseId, 'subject_code' => '6602', 'subject_name' => '管理费用',
            'summary' => '对方科目', 'debit_amount' => 0, 'credit_amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_iou_create_writes_doc_but_no_cash_flow_and_confirm_flows(): void
    {
        $this->seedSubjects();
        $this->actingAsAdmin();

        $res = $this->postJson(self::BASE.'/iou', [
            'partner_type' => 'customer',
            'partner_id' => 11,
            'partner_name' => '甲乙公司',
            'amount' => 1200.50,
            'doc_date' => '2026-10-01',
            'due_date' => '2026-11-01',
            'signature_url' => '/uploads/sign/x.png',
            'abstract' => '客户暂欠货款',
        ])->assertOk()->assertJson(['code' => 200]);

        $id = $res->json('data.id');
        $this->assertSame('pending', $res->json('data.status'));
        $this->assertStringStartsWith('IOU', $res->json('data.no'));
        // 欠条仅留痕，不写资金流水
        $this->assertSame(0, DB::table('cash_flows')->where('related_type', 'FinFundOrder')->where('related_id', $id)->count());

        $this->postJson(self::BASE."/iou/{$id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_fund_io_create_writes_cash_flow_immediately(): void
    {
        $this->seedSubjects();
        $this->actingAsAdmin();

        $res = $this->postJson(self::BASE.'/fund_io', [
            'amount' => 5000,
            'direction' => 'increase',
            'account' => '基本户',
            'payment_method' => '银行',
            'doc_date' => '2026-10-05',
            'abstract' => '股东投入',
        ])->assertOk();

        $id = $res->json('data.id');
        $this->assertSame('confirmed', $res->json('data.status'));

        $flow = DB::table('cash_flows')->where('related_type', 'FinFundOrder')->where('related_id', $id)->first();
        $this->assertNotNull($flow, '资金增减建单即写 cash_flows');
        $this->assertSame(5000.0, (float) $flow->amount);
        $this->assertSame('receive', $flow->flow_type);
        $this->assertSame($flow->flow_no, $res->json('data.cash_flow_no'));
    }

    public function test_adjust_order_requires_reason(): void
    {
        $this->seedSubjects();
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/customer_balance', [
            'partner_type' => 'customer',
            'partner_id' => 11,
            'partner_name' => '甲乙公司',
            'amount' => -100,
        ])->assertStatus(422);
    }

    public function test_balance_reduction_beyond_current_balance_is_blocked(): void
    {
        $this->seedSubjects();
        $this->seedCustomerReceivable(11, 800.00);
        $this->actingAsAdmin();

        $id = $this->postJson(self::BASE.'/customer_balance', [
            'partner_type' => 'customer',
            'partner_id' => 11,
            'partner_name' => '甲乙公司',
            'amount' => -1500,
            'reason' => '坏账核销',
            'doc_date' => '2026-10-10',
        ])->assertOk()->json('data.id');

        // 三段余额快照：期初=当前=800，调整后=800-1500
        $this->assertSame(800.0, (float) $this->getJson(self::BASE."/customer_balance/{$id}")->json('data.opening_balance'));
        $this->assertSame(-700.0, (float) $this->getJson(self::BASE."/customer_balance/{$id}")->json('data.adjusted_balance'));

        $this->postJson(self::BASE."/customer_balance/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', '往来余额不足：当前余额 800，本次调减 1500');

        $this->assertSame('pending', DB::table('fin_fund_orders')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('fin_fund_orders')->where('id', $id)->value('voucher_id'));
    }

    public function test_approve_syncs_ar_via_voucher_and_cash_flow(): void
    {
        $this->seedSubjects();
        $this->seedCustomerReceivable(11, 2000.00);
        $this->actingAsAdmin();

        $id = $this->postJson(self::BASE.'/customer_balance', [
            'partner_type' => 'customer',
            'partner_id' => 11,
            'partner_name' => '甲乙公司',
            'amount' => -500,
            'reason' => '销售折让',
            'doc_date' => '2026-10-10',
        ])->assertOk()->json('data.id');

        $res = $this->postJson(self::BASE."/customer_balance/{$id}/approve")->assertOk();
        $this->assertSame('approved', $res->json('data.status'));

        $voucherId = $res->json('data.voucher_id');
        $this->assertNotNull($voucherId, '审核通过须同步应收应付凭证');
        $this->assertSame(500.0, (float) DB::table('ledger_vouchers')->where('id', $voucherId)->value('total_debit'));

        $entry = DB::table('ledger_voucher_entries')
            ->where('voucher_id', $voucherId)->where('subject_code', '1122')->first();
        $this->assertNotNull($entry);
        $this->assertSame(500.0, (float) $entry->credit_amount, '调减应收应记贷方');

        $this->assertSame(1, DB::table('cash_flows')->where('related_type', 'FinFundOrder')->where('related_id', $id)->count());
    }

    public function test_arap_adjust_items_drive_voucher_entries(): void
    {
        $this->seedSubjects();
        $this->seedCustomerReceivable(11, 3000.00);
        $this->actingAsAdmin();

        $id = $this->postJson(self::BASE.'/arap_adjust', [
            'partner_type' => 'customer',
            'partner_id' => 11,
            'partner_name' => '甲乙公司',
            'amount' => 0,
            'reason' => '重分类调整',
            'doc_date' => '2026-10-12',
            'items' => [
                ['subject' => '应收账款', 'subject_code' => '1122', 'direction' => '贷', 'amount' => 300, 'abstract' => '转出'],
                ['subject' => '管理费用', 'subject_code' => '6602', 'direction' => '借', 'amount' => 300, 'abstract' => '计入费用'],
            ],
        ])->assertOk()->json('data.id');

        $res = $this->postJson(self::BASE."/arap_adjust/{$id}/approve")->assertOk();
        $this->assertNotNull($res->json('data.voucher_id'));
        $this->assertSame(300.0, (float) DB::table('ledger_vouchers')->where('id', $res->json('data.voucher_id'))->value('total_debit'));
    }

    public function test_repay_progresses_unsettled_partial_settled(): void
    {
        $this->seedSubjects();
        $this->actingAsAdmin();

        $id = $this->postJson(self::BASE.'/borrow_repay', [
            'partner_type' => 'employee',
            'partner_id' => 7,
            'partner_name' => '张伟',
            'amount' => 1000,
            'doc_date' => '2026-10-01',
            'due_date' => now()->addDays(30)->toDateString(),
        ])->assertOk()->json('data.id');

        $this->assertSame(1000.0, (float) $this->getJson(self::BASE."/borrow_repay/{$id}")->json('data.unpaid'));

        $partial = $this->postJson(self::BASE."/borrow_repay/{$id}/repay", ['amount' => 400])->assertOk();
        $this->assertSame('partial', $partial->json('data.status'));
        $this->assertSame(600.0, (float) $partial->json('data.unpaid'));
        $this->assertSame(400.0, (float) $partial->json('data.paid'));

        $final = $this->postJson(self::BASE."/borrow_repay/{$id}/repay", ['amount' => 600])->assertOk();
        $this->assertSame('settled', $final->json('data.status'));
        $this->assertSame(0.0, (float) $final->json('data.unpaid'));

        // 还款超额拦截
        $this->postJson(self::BASE."/borrow_repay/{$id}/repay", ['amount' => 1])->assertStatus(422);
    }

    public function test_overdue_borrow_is_flagged(): void
    {
        $this->seedSubjects();
        $this->actingAsAdmin();

        $id = $this->postJson(self::BASE.'/borrow_repay', [
            'partner_type' => 'employee',
            'partner_id' => 8,
            'partner_name' => '李娜',
            'amount' => 800,
            'doc_date' => '2026-08-01',
            'due_date' => now()->subDays(5)->toDateString(),
        ])->assertOk()->json('data.id');

        $this->assertTrue($this->getJson(self::BASE."/borrow_repay/{$id}")->json('data.is_overdue'));
    }

    public function test_reject_requires_reason_and_locks_doc(): void
    {
        $this->seedSubjects();
        $this->seedCustomerReceivable(11, 900.00);
        $this->actingAsAdmin();

        $id = $this->postJson(self::BASE.'/customer_balance', [
            'partner_type' => 'customer',
            'partner_id' => 11,
            'partner_name' => '甲乙公司',
            'amount' => -100,
            'reason' => '待核',
        ])->assertOk()->json('data.id');

        $this->postJson(self::BASE."/customer_balance/{$id}/reject", [])->assertStatus(422);

        $this->postJson(self::BASE."/customer_balance/{$id}/reject", ['reason' => '依据不足'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', '依据不足');

        $this->postJson(self::BASE."/customer_balance/{$id}/approve")->assertStatus(422);
    }

    public function test_unknown_type_rejected_and_list_filters(): void
    {
        $this->seedSubjects();
        $this->actingAsAdmin();

        $this->getJson(self::BASE.'/nonsense')->assertStatus(422);

        $this->postJson(self::BASE.'/iou', [
            'partner_type' => 'customer', 'partner_id' => 11, 'partner_name' => '甲乙公司',
            'amount' => 100, 'doc_date' => '2026-10-01', 'due_date' => '2026-11-01',
            'signature_url' => '/s.png',
        ])->assertOk();

        $list = $this->getJson(self::BASE.'/iou?status=pending&page=1&page_size=10')
            ->assertOk()->json('data');
        $this->assertSame(1, $list['total']);
        $this->assertCount(1, $list['list']);

        // 类型隔离：iou 单不出现在供应商余额调整列表
        $this->assertSame(0, $this->getJson(self::BASE.'/supplier_balance')->assertOk()->json('data.total'));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->seedSubjects();

        $this->getJson(self::BASE.'/iou')->assertStatus(401);
    }
}
