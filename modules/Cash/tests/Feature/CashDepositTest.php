<?php

namespace Tests\Cash\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashDeposit;
use Tests\TestCase;

/**
 * P0-8 验收：存取款转账单（现金收支录入）
 *
 *  - 存款：单据落库 + 1 条 income 流水（amount−fee），账户余额按流水口径增加
 *  - 取款：余额不足服务端 422 拒单（不是只拦前端），文案对齐页面 hint
 *  - 互转：转出 1 条 expense + 转入 1 条 income 双流水
 *  - 删除：仅当日可删，事务内删单据 + 删关联流水
 */
class CashDepositTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $cash;

    private CashAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();

        $this->cash = CashAccount::create([
            'name' => '库存现金',
            'type' => CashAccount::TYPE_CASH,
            'account_no' => 'CASH-001',
            'opening_balance' => 1000,
            'status' => CashAccount::STATUS_ENABLED,
            'sort' => 1,
        ]);
        $this->bank = CashAccount::create([
            'name' => '银行存款',
            'type' => CashAccount::TYPE_BANK,
            'account_no' => '6222020000000000',
            'opening_balance' => 500,
            'status' => CashAccount::STATUS_ENABLED,
            'sort' => 2,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'doc_no' => 'CQ'.date('Ymd').'0001',
            'biz_date' => date('Y-m-d'),
            'type' => 'deposit',
            'account_id' => $this->cash->id,
            'to_account_id' => null,
            'counterpart' => '测试客户',
            'amount' => 200,
            'fee' => 0,
            'handler' => '张三',
            'department' => '财务部',
            'remark' => '销售回款存入',
            'memo' => '',
        ], $overrides);
    }

    public function test_store_deposit_persists_doc_and_one_income_flow(): void
    {
        $resp = $this->postJson('/admin/cash/deposits', $this->payload([
            'amount' => 1000,
            'fee' => 10,
        ]))->assertOk();

        $id = $resp->json('data.id');
        $doc = CashDeposit::findOrFail($id);
        $this->assertSame('CQ'.date('Ymd').'0001', $doc->doc_no);
        $this->assertSame('deposit', $doc->type);
        $this->assertEquals(1000.00, (float) $doc->amount);
        $this->assertEquals(10.00, (float) $doc->fee);
        $this->assertSame('confirmed', $doc->status);
        $this->assertSame('测试客户', $doc->counterpart);

        $flows = DB::table('cash_flow')->where('related_type', 'cash_deposit')->where('related_id', $id)->get();
        $this->assertCount(1, $flows);
        $flow = $flows[0];
        $this->assertSame('income', $flow->direction);
        $this->assertEquals(990.00, (float) $flow->amount);
        $this->assertSame($this->cash->id, (int) $flow->account_id);
        $this->assertSame($doc->doc_no, $flow->flow_no);
        $this->assertStringStartsWith(date('Y-m-d'), (string) $flow->flow_date);

        $options = $this->getJson('/admin/cash/deposits/options')->assertOk();
        $cashRow = collect($options->json('data.list'))->firstWhere('id', $this->cash->id);
        $this->assertEquals(1990.0, $cashRow['balance']);
    }

    public function test_store_withdraw_rejected_when_balance_insufficient(): void
    {
        // 期初 1000，取 1200（+手续费 10）> 1000：必须服务端拦截
        $resp = $this->postJson('/admin/cash/deposits', $this->payload([
            'type' => 'withdraw',
            'amount' => 1200,
            'fee' => 10,
        ]))->assertStatus(422);

        $this->assertSame('账户余额不足', $resp->json('message'));
        $this->assertSame(0, CashDeposit::query()->count());
        $this->assertSame(0, DB::table('cash_flow')->where('related_type', 'cash_deposit')->count());

        // 余额充足时放行：amount+fee 全额记 expense
        $ok = $this->postJson('/admin/cash/deposits', $this->payload([
            'type' => 'withdraw',
            'amount' => 600,
            'fee' => 10,
        ]))->assertOk();
        $flows = DB::table('cash_flow')->where('related_type', 'cash_deposit')->where('related_id', $ok->json('data.id'))->get();
        $this->assertSame('expense', $flows[0]->direction);
        $this->assertEquals(610.00, (float) $flows[0]->amount);
    }

    public function test_store_transfer_writes_expense_and_income_double_flows(): void
    {
        $resp = $this->postJson('/admin/cash/deposits', $this->payload([
            'type' => 'transfer',
            'account_id' => $this->cash->id,
            'to_account_id' => $this->bank->id,
            'amount' => 400,
            'fee' => 5,
        ]))->assertOk();

        $id = $resp->json('data.id');
        $flows = DB::table('cash_flow')->where('related_type', 'cash_deposit')->where('related_id', $id)->get();
        $this->assertCount(2, $flows);

        $out = $flows->firstWhere('account_id', $this->cash->id);
        $in = $flows->firstWhere('account_id', $this->bank->id);
        $this->assertSame('expense', $out->direction);
        $this->assertEquals(405.00, (float) $out->amount);
        $this->assertSame('income', $in->direction);
        $this->assertEquals(395.00, (float) $in->amount);

        // 互转同账户必须拒绝
        $this->postJson('/admin/cash/deposits', $this->payload([
            'type' => 'transfer',
            'account_id' => $this->cash->id,
            'to_account_id' => $this->cash->id,
            'amount' => 100,
        ]))->assertStatus(422);
    }

    public function test_gen_no_returns_sequential_cq_prefix(): void
    {
        $first = $this->getJson('/admin/cash/deposits/gen-no')->assertOk()->json('data.no');
        $this->assertSame('CQ'.date('Ymd').'0001', $first);

        $this->postJson('/admin/cash/deposits', $this->payload())->assertOk();

        $second = $this->getJson('/admin/cash/deposits/gen-no')->assertOk()->json('data.no');
        $this->assertSame('CQ'.date('Ymd').'0002', $second);

        // 重复单据号服务端拒绝
        $this->postJson('/admin/cash/deposits', $this->payload())->assertStatus(422);
    }

    public function test_destroy_only_today_and_removes_flows(): void
    {
        $today = $this->postJson('/admin/cash/deposits', $this->payload(['amount' => 300]))->assertOk()->json('data.id');

        $this->deleteJson("/admin/cash/deposits/{$today}")->assertOk();
        $this->assertSame(0, CashDeposit::query()->count());
        $this->assertSame(0, DB::table('cash_flow')->where('related_type', 'cash_deposit')->count());

        // 非当日单不可删
        $old = $this->postJson('/admin/cash/deposits', $this->payload([
            'doc_no' => 'CQ202601010001',
            'biz_date' => '2026-01-01',
        ]))->assertOk()->json('data.id');
        $resp = $this->deleteJson("/admin/cash/deposits/{$old}")->assertStatus(422);
        $this->assertSame('仅当日单据可删除', $resp->json('message'));
        $this->assertSame(1, CashDeposit::query()->count());
    }

    public function test_index_filters_and_orders_desc(): void
    {
        $this->postJson('/admin/cash/deposits', $this->payload(['amount' => 100]))->assertOk();
        $this->postJson('/admin/cash/deposits', $this->payload([
            'doc_no' => 'CQ'.date('Ymd').'0002',
            'type' => 'withdraw',
            'amount' => 50,
        ]))->assertOk();
        $this->postJson('/admin/cash/deposits', $this->payload([
            'doc_no' => 'CQ202601010001',
            'biz_date' => '2026-01-01',
        ]))->assertOk();

        $all = $this->getJson('/admin/cash/deposits')->assertOk()->json('data');
        $this->assertSame(3, $all['total']);
        $this->assertSame('CQ'.date('Ymd').'0002', $all['list'][0]['no']);

        $byType = $this->getJson('/admin/cash/deposits?type=withdraw')->assertOk()->json('data');
        $this->assertSame(1, $byType['total']);

        $byDate = $this->getJson('/admin/cash/deposits?start_date=2026-01-01&end_date=2026-01-01')->assertOk()->json('data');
        $this->assertSame(1, $byDate['total']);

        $byAccount = $this->getJson('/admin/cash/deposits?account_id='.$this->bank->id)->assertOk()->json('data');
        $this->assertSame(0, $byAccount['total']);
    }
}
