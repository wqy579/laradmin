<?php

namespace Tests\Ledger\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Ledger\Services\CashFlowService;
use Tests\TestCase;

class CashFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashFlowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CashFlowService::class);
        
        // 模拟管理员登录
        $admin = User::create([
            'username' => 'admin',
            'password' => 'password',
            'real_name' => '管理员',
        ]);
        $this->actingAs($admin, 'admin');
    }

    public function test_record_receive_positive_amount(): void
    {
        $flowNo = $this->service->recordReceive([
            'amount' => 100.0,
            'flow_date' => '2026-10-22',
            'payment_method' => '现金',
            'related_id' => 1,
            'related_type' => 'TestModel',
            'remark' => '测试收款',
        ]);

        $this->assertNotEquals('EXISTING', $flowNo);
        $row = DB::table('cash_flows')->where('flow_no', $flowNo)->first();
        $this->assertNotNull($row);
        $this->assertEquals('receive', $row->flow_type);
        $this->assertEquals(100.0, (float)$row->amount);
        $this->assertEquals('现金', $row->payment_method);
        $this->assertEquals(1, $row->related_id);
    }

    public function test_record_pay_negative_amount(): void
    {
        $flowNo = $this->service->recordPay([
            'amount' => 50.0, // 传入正数，服务应转为负数
            'flow_date' => '2026-10-22',
            'payment_method' => '银行卡',
            'related_id' => 2,
            'related_type' => 'TestModel',
            'remark' => '测试付款',
        ]);

        $row = DB::table('cash_flows')->where('flow_no', $flowNo)->first();
        $this->assertEquals(-50.0, (float)$row->amount);
        $this->assertEquals('pay', $row->flow_type);
    }

    public function test_idempotency(): void
    {
        $params = [
            'amount' => 100.0,
            'related_id' => 10,
            'related_type' => 'Order',
            'flow_type' => 'receive',
        ];

        $no1 = $this->service->record($params);
        $no2 = $this->service->record($params);

        $this->assertNotEquals('EXISTING', $no1);
        $this->assertEquals('EXISTING', $no2);
        $this->assertEquals(1, DB::table('cash_flows')->where('related_id', 10)->where('related_type', 'Order')->count());
    }

    public function test_journal_rolling_balance(): void
    {
        // 1. 收入 100
        $this->service->recordReceive([
            'amount' => 100.0,
            'flow_date' => '2026-10-01',
            'related_id' => 1,
            'related_type' => 'T1',
        ]);
        // 2. 支出 30
        $this->service->recordPay([
            'amount' => 30.0,
            'flow_date' => '2026-10-02',
            'related_id' => 2,
            'related_type' => 'T2',
        ]);
        // 3. 收入 50
        $this->service->recordReceive([
            'amount' => 50.0,
            'flow_date' => '2026-10-03',
            'related_id' => 3,
            'related_type' => 'T3',
        ]);

        $journal = $this->service->getJournal(['start_date' => '2026-10-01']);

        $this->assertEquals(0.0, $journal['opening_balance']);
        $this->assertCount(3, $journal['rows']);
        $this->assertEquals(100.0, $journal['rows'][0]['balance']);
        $this->assertEquals(70.0, $journal['rows'][1]['balance']);
        $this->assertEquals(120.0, $journal['rows'][2]['balance']);
        $this->assertEquals(120.0, $journal['closing_balance']);
    }
}
