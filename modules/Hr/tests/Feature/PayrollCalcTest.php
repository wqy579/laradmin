<?php

namespace Tests\Hr\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Business\Models\Employee;
use Modules\Hr\Models\Payroll;
use Modules\Hr\Services\PayrollService;
use Modules\Performance\Models\CommissionOrder;
use Tests\TestCase;

/**
 * F4 验收：应发净额公式 + 工资条明细可展开。
 *
 *  net_amount = 基本工资 + 提成 + 绩效 − 扣项（PayrollService::calcNet 单一出口）
 *  - 提成列取自 perf 提成单「实发 actual_amount」求和，且只取 audited/paid
 *  - pending 未审核、rejected 已驳回的提成单不得进工资
 *  - 提成为 perf 快照，不可手改；基本/绩效/扣项可改并重算净额
 *  - 工资条（抽屉）必须返回公式明细 + 当期提成单明细 + 付款信息
 *  - 合计行与空库：出 0 不出 null/NaN
 */
class PayrollCalcTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-03';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '薪酬专员']);
    }

    public function test_calc_net_is_the_single_formula_outlet(): void
    {
        $this->assertSame(8123.45, PayrollService::calcNet(5000, 3000.5, 500, 377.05));
        $this->assertSame(0.3, PayrollService::calcNet(0.1, 0.2, 0, 0));
        $this->assertSame(-2000.0, PayrollService::calcNet(3000, 0, 0, 5000));
        $this->assertSame(0.0, PayrollService::calcNet(0, 0, 0, 0));
    }

    public function test_generate_sums_only_audited_and_paid_commissions(): void
    {
        $salesman = $this->makeAdmin(['real_name' => '业务员甲']);
        $this->employee($salesman->id, '业务员甲', 5000);

        $this->order($salesman->id, '业务员甲', self::PERIOD, 'audited', 1200.50, 100, 1100.50);
        $this->order($salesman->id, '业务员甲', self::PERIOD, 'paid', 800, 0, 780.25);
        $pending = $this->order($salesman->id, '业务员甲', self::PERIOD, 'pending', 9999, 0, 9999);
        $rejected = $this->order($salesman->id, '业务员甲', self::PERIOD, 'rejected', 8888, 0, 8888);
        // 另一期间的单据不得混入本期
        $this->order($salesman->id, '业务员甲', '2026-02', 'audited', 7777, 0, 7777);

        $resp = $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD]);
        $resp->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 0);

        $payroll = $this->payrollOf($salesman->id, self::PERIOD);

        $this->assertSame(5000.0, (float) $payroll->base_amount);
        // 1100.50 + 780.25，pending/rejected 完全排除
        $this->assertSame(1880.75, (float) $payroll->commission_amount);
        $this->assertSame(6880.75, (float) $payroll->net_amount);
        $this->assertSame('draft', $payroll->status);
        $this->assertSame('业务员甲', $payroll->employee_name);

        // perf 侧只读：生成工资不得回写提成单
        $this->assertSame('pending', CommissionOrder::find($pending->id)->status);
        $this->assertSame('rejected', CommissionOrder::find($rejected->id)->status);
    }

    public function test_generate_is_idempotent_per_period_and_employee(): void
    {
        $salesman = $this->makeAdmin(['real_name' => '业务员乙']);
        $this->employee($salesman->id, '业务员乙', 4000);
        $this->order($salesman->id, '业务员乙', self::PERIOD, 'audited', 1000, 0, 950);

        $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD])->assertOk();
        $second = $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD]);
        $second->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, Payroll::where('period', self::PERIOD)->where('employee_id', $salesman->id)->count());
    }

    public function test_generate_without_entry_commissions_creates_nothing(): void
    {
        $salesman = $this->makeAdmin(['real_name' => '业务员丙']);
        $this->employee($salesman->id, '业务员丙', 3000);
        $this->order($salesman->id, '业务员丙', self::PERIOD, 'pending', 5000, 0, 5000);

        $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.items', []);

        $this->assertSame(0, Payroll::count());
    }

    public function test_generate_rejects_malformed_period(): void
    {
        $this->postJson('/admin/hr/payrolls/generate', ['period' => '2026/03'])->assertStatus(422);
        $this->postJson('/admin/hr/payrolls/generate', [])->assertStatus(422);
    }

    public function test_missing_base_salary_falls_back_to_zero_not_nan(): void
    {
        $salesman = $this->makeAdmin(['real_name' => '无档案业务员']);
        $this->order($salesman->id, '无档案业务员', self::PERIOD, 'audited', 600, 0, 600);

        $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD])->assertOk();

        $payroll = $this->payrollOf($salesman->id, self::PERIOD);
        $this->assertSame(0.0, (float) $payroll->base_amount);
        $this->assertSame(600.0, (float) $payroll->net_amount);
    }

    public function test_editing_draft_recomputes_net_and_keeps_commission_frozen(): void
    {
        $payroll = $this->seedDraft();

        $resp = $this->putJson("/admin/hr/payrolls/{$payroll->id}", [
            'base_amount' => 5000,
            'performance_amount' => 800,
            'deduction_amount' => 300.55,
            // 提成为 perf 快照，传了也必须被忽略
            'commission_amount' => 999999,
            'remark' => '含全勤与考核',
        ]);
        $resp->assertOk();
        $this->assertSame(7299.45, (float) $resp->json('data.net_amount'));

        $payroll->refresh();
        $this->assertSame(1800.0, (float) $payroll->commission_amount);
        $this->assertSame(
            PayrollService::calcNet(5000, 1800, 800, 300.55),
            (float) $payroll->net_amount
        );
        $this->assertSame('含全勤与考核', $payroll->remark);
    }

    public function test_negative_deduction_is_rejected(): void
    {
        $payroll = $this->seedDraft();

        $this->putJson("/admin/hr/payrolls/{$payroll->id}", ['deduction_amount' => -100])
            ->assertStatus(422);
        $this->putJson("/admin/hr/payrolls/{$payroll->id}", ['performance_amount' => -1])
            ->assertStatus(422);
    }

    public function test_detail_exposes_formula_and_commission_order_breakdown(): void
    {
        $salesman = $this->makeAdmin(['real_name' => '工资条业务员']);
        $this->employee($salesman->id, '工资条业务员', 5000);
        $this->order($salesman->id, '工资条业务员', self::PERIOD, 'audited', 1200, 50, 1150, 'SO-20260301');
        $this->order($salesman->id, '工资条业务员', self::PERIOD, 'audited', 900, 0, 650, 'SO-20260315');

        $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD])->assertOk();
        $payroll = $this->payrollOf($salesman->id, self::PERIOD);
        $this->putJson("/admin/hr/payrolls/{$payroll->id}", [
            'performance_amount' => 500,
            'deduction_amount' => 150,
        ])->assertOk();

        $resp = $this->getJson("/admin/hr/payrolls/{$payroll->id}");
        $resp->assertOk();

        $formula = $resp->json('data.formula');
        $this->assertSame(
            ['base_amount', 'commission_amount', 'performance_amount', 'deduction_amount', 'net_amount'],
            array_keys($formula)
        );
        $this->assertSame(5000.0, (float) $formula['base_amount']);
        $this->assertSame(1800.0, (float) $formula['commission_amount']);
        $this->assertSame(500.0, (float) $formula['performance_amount']);
        $this->assertSame(150.0, (float) $formula['deduction_amount']);
        $this->assertSame(7150.0, (float) $formula['net_amount']);
        // 展开明细的加总必须与净额自洽
        $this->assertSame(
            (float) $formula['net_amount'],
            round((float) $formula['base_amount'] + (float) $formula['commission_amount'] + (float) $formula['performance_amount'] - (float) $formula['deduction_amount'], 2)
        );

        $orders = $resp->json('data.commission_orders');
        $this->assertCount(2, $orders);
        $this->assertSame(['bill_no', 'period', 'status', 'commission_amount', 'deduction', 'actual_amount'], array_keys($orders[0]));
        $this->assertSame(1800.0, round(array_sum(array_column($orders, 'actual_amount')), 2));

        // 未发放前不应有付款信息
        $this->assertNull($resp->json('data.pay'));
    }

    public function test_list_stat_matches_rows_and_is_zero_safe(): void
    {
        $resp = $this->getJson('/admin/hr/payrolls');
        $resp->assertOk()->assertJsonPath('data.total', 0);
        $this->assertSame(
            ['count', 'base_amount', 'commission_amount', 'performance_amount', 'deduction_amount', 'net_amount'],
            array_keys($resp->json('data.stat'))
        );
        foreach ($resp->json('data.stat') as $k => $v) {
            $this->assertIsNumeric($v, "stat.$k 不是数字");
        }

        $payroll = $this->seedDraft();
        $this->putJson("/admin/hr/payrolls/{$payroll->id}", [
            'performance_amount' => 200,
            'deduction_amount' => 20,
        ])->assertOk();

        $resp = $this->getJson('/admin/hr/payrolls?period='.self::PERIOD);
        $stat = $resp->json('data.stat');
        $this->assertSame(1, $stat['count']);
        $this->assertSame(1000.0, (float) $stat['base_amount']);
        $this->assertSame(1800.0, (float) $stat['commission_amount']);
        $this->assertSame(200.0, (float) $stat['performance_amount']);
        $this->assertSame(20.0, (float) $stat['deduction_amount']);
        $this->assertSame(2980.0, (float) $stat['net_amount']);
        $this->assertSame(
            (float) $stat['net_amount'],
            round(array_sum(array_column($resp->json('data.list'), 'net_amount')), 2)
        );
    }

    public function test_options_expose_employees_by_perf_employee_id(): void
    {
        $salesman = $this->makeAdmin(['real_name' => '下拉业务员']);
        $this->employee($salesman->id, '下拉业务员', 6000);
        // 未关联系统用户的档案不能出现在下拉（与 perf employee_id 口径不一致）
        Employee::create(['code' => 'E-NOUSER-'.strtolower(bin2hex(random_bytes(4))), 'name' => '无账号员工', 'user_id' => null]);

        $resp = $this->getJson('/admin/hr/payrolls/options');
        $resp->assertOk();

        $ids = array_column($resp->json('data.employees'), 'id');
        $this->assertContains($salesman->id, $ids);
        $row = collect($resp->json('data.employees'))->firstWhere('id', $salesman->id);
        $this->assertSame('下拉业务员', $row['name']);
        $this->assertSame(6000.0, (float) $row['base_salary']);
    }

    // ---------- helpers ----------

    private function seedDraft(): Payroll
    {
        $salesman = $this->makeAdmin(['real_name' => '草稿业务员']);
        $this->employee($salesman->id, '草稿业务员', 1000);
        $this->order($salesman->id, '草稿业务员', self::PERIOD, 'audited', 1850, 50, 1800);

        $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD])->assertOk();

        return $this->payrollOf($salesman->id, self::PERIOD);
    }

    private function payrollOf(int $employeeId, string $period): Payroll
    {
        return Payroll::where('period', $period)->where('employee_id', $employeeId)->firstOrFail();
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
        return CommissionOrder::create([
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
    }

    private function tag(): string
    {
        return strtolower(bin2hex(random_bytes(5)));
    }
}
