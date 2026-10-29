<?php

namespace Tests\Hr\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Business\Models\Employee;
use Modules\Hr\Models\CommissionFlow;
use Modules\Hr\Models\Payroll;
use Modules\Order\Models\Pay;
use Modules\Performance\Models\CommissionOrder;
use Tests\TestCase;

/**
 * F4 验收 4：发放后生成付款单且状态锁定 + 提成流水与 perf 一致。
 *
 *  - 发放走既有付款单通道（Order 域 pays）+ 资金流水 cash_flows（支出记负）
 *  - paid 后改/删一律拒绝，重复发放幂等（不得再开第二张付款单）
 *  - 发放时把当期 perf 提成单逐单快照成 hr_commission_flows，
 *    实发合计必须与 perf 域 audited/paid 口径一致（reconcile matched=true）
 */
class PayrollPayTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-03';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '薪酬专员']);
    }

    public function test_pay_creates_payment_order_and_negative_cash_flow(): void
    {
        $payroll = $this->seedDraft(5000, 1800, 500, 300);

        $resp = $this->postJson("/admin/hr/payrolls/{$payroll->id}/pay", [
            'payment_method' => '银行转账',
            'paid_date' => '2026-04-05',
        ]);
        $resp->assertOk()->assertJsonPath('data.status', 'paid');

        $payroll->refresh();
        $this->assertSame('paid', $payroll->status);
        $this->assertSame(7000.0, (float) $payroll->net_amount);

        // 付款单：走 Order 域既有通道，金额等于应发净额
        $this->assertNotNull($payroll->pay_id);
        $pay = Pay::query()->findOrFail($payroll->pay_id);
        $this->assertSame(7000.0, (float) $pay->amount);
        $this->assertSame(2, (int) $pay->pay_type);
        $this->assertSame($payroll->pay_no, $pay->pay_no);
        $this->assertSame('2026-04-05', $pay->pay_date->toDateString());

        // 资金流水：工资是支出，必须记负数且回链工资单
        $flow = DB::table('cash_flows')->where('related_type', 'HrPayroll')
            ->where('related_id', $payroll->id)->first();
        $this->assertNotNull($flow, '发放未写资金流水');
        $this->assertSame(-7000.0, (float) $flow->amount);
        $this->assertSame('pay', $flow->flow_type);
        $this->assertSame($payroll->cash_flow_no, $flow->flow_no);
    }

    public function test_paid_payroll_is_locked(): void
    {
        $payroll = $this->seedDraft();
        $this->postJson("/admin/hr/payrolls/{$payroll->id}/pay")->assertOk();

        $this->putJson("/admin/hr/payrolls/{$payroll->id}", ['performance_amount' => 9999])
            ->assertStatus(422);
        $this->deleteJson("/admin/hr/payrolls/{$payroll->id}")->assertStatus(422);

        $payroll->refresh();
        $this->assertSame(500.0, (float) $payroll->performance_amount);
        $this->assertSame('paid', $payroll->status);
    }

    public function test_repeat_pay_is_idempotent(): void
    {
        $payroll = $this->seedDraft();
        $this->postJson("/admin/hr/payrolls/{$payroll->id}/pay")->assertOk();

        $payNo = $payroll->refresh()->pay_no;
        $resp = $this->postJson("/admin/hr/payrolls/{$payroll->id}/pay");
        $resp->assertOk()->assertJsonPath('data.pay_no', $payNo)->assertJsonPath('data.status', 'paid');

        $this->assertSame(1, Pay::query()->count(), '重复发放开了第二张付款单');
        $this->assertSame(1, DB::table('cash_flows')->where('related_type', 'HrPayroll')->count());
    }

    public function test_negative_net_cannot_be_paid(): void
    {
        $payroll = $this->seedDraft(1000, 0, 0, 5000);

        $this->postJson("/admin/hr/payrolls/{$payroll->id}/pay")->assertStatus(422);

        $this->assertSame('draft', $payroll->refresh()->status);
        $this->assertSame(0, Pay::query()->count());
    }

    public function test_pay_snapshots_commission_flows_matching_perf(): void
    {
        $salesman = $this->makeAdmin(['real_name' => '流水业务员']);
        $this->employee($salesman->id, '流水业务员', 5000);
        $this->order($salesman->id, '流水业务员', self::PERIOD, 'audited', 1200, 50, 1150, 'SO-20260301');
        $this->order($salesman->id, '流水业务员', self::PERIOD, 'audited', 700, 0, 650, 'SO-20260315');
        // pending 不入工资、也不进流水
        $this->order($salesman->id, '流水业务员', self::PERIOD, 'pending', 9999, 0, 9999, 'SO-PENDING');

        $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD])->assertOk();
        $payroll = Payroll::where('period', self::PERIOD)->where('employee_id', $salesman->id)->firstOrFail();
        $this->postJson("/admin/hr/payrolls/{$payroll->id}/pay")->assertOk();

        $flows = CommissionFlow::where('payroll_id', $payroll->id)->get();
        $this->assertCount(2, $flows);
        $this->assertSame(1800.0, round((float) $flows->sum('actual_amount'), 2));
        // 逐单金额照抄 perf，源单据号取自提成明细
        $this->assertEqualsCanonicalizing(
            ['SO-20260301', 'SO-20260315'],
            $flows->pluck('source_no')->all()
        );
        $this->assertSame($payroll->payroll_no, $flows->first()->payroll_no);

        // 与 perf 对账：实发合计必须一致
        $resp = $this->getJson('/admin/hr/commission-flows/reconcile?period='.self::PERIOD.'&employee_id='.$salesman->id);
        $resp->assertOk()->assertJsonPath('data.matched', true);
        $this->assertSame(
            (float) $resp->json('data.perf.actual_amount'),
            (float) $resp->json('data.local.actual_amount')
        );

        // 重复发放不得追加流水
        $this->postJson("/admin/hr/payrolls/{$payroll->id}/pay")->assertOk();
        $this->assertSame(2, CommissionFlow::where('payroll_id', $payroll->id)->count());
    }

    // ---------- helpers ----------

    private function seedDraft(float $base = 5000, float $commission = 1800, float $performance = 500, float $deduction = 300): Payroll
    {
        $salesman = $this->makeAdmin(['real_name' => '发放业务员']);
        $this->employee($salesman->id, '发放业务员', $base);
        $this->order($salesman->id, '发放业务员', self::PERIOD, 'audited', $commission + 50, 50, $commission);

        $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD])->assertOk();
        $payroll = Payroll::where('period', self::PERIOD)->where('employee_id', $salesman->id)->firstOrFail();

        $this->putJson("/admin/hr/payrolls/{$payroll->id}", [
            'performance_amount' => $performance,
            'deduction_amount' => $deduction,
        ])->assertOk();

        return $payroll->refresh();
    }

    private function employee(int $userId, string $name, float $baseSalary): Employee
    {
        return Employee::create([
            'code' => 'E'.$this->tag(),
            'name' => $name,
            'user_id' => $userId,
            'base_salary' => $baseSalary,
            'role' => 'salesman',
            'is_active' => true,
        ]);
    }

    private function order(int $employeeId, string $name, string $period, string $status, float $commission, float $deduction, float $actual, ?string $sourceNo = null): CommissionOrder
    {
        $order = CommissionOrder::create([
            'bill_no' => 'TX'.$this->tag(),
            'period' => $period,
            'employee_id' => $employeeId,
            'employee_name' => $name,
            'department_id' => 1,
            'department_name' => '销售一部',
            'sales_amount' => $commission * 10,
            'received_amount' => $commission * 9,
            'base_amount' => $commission,
            'commission_amount' => $commission,
            'deduction' => $deduction,
            'actual_amount' => $actual,
            'status' => $status,
        ]);

        if ($sourceNo !== null) {
            $table = Schema::hasTable('perf_commission_items')
                ? 'perf_commission_items'
                : 'perf_commission_order_items';
            $fk = Schema::hasColumn($table, 'order_id') ? 'order_id' : 'commission_order_id';

            DB::table($table)->insert([
                $fk => $order->id,
                'source_type' => 'order',
                'source_no' => $sourceNo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $order;
    }

    private function tag(): string
    {
        return strtolower(bin2hex(random_bytes(5)));
    }
}
