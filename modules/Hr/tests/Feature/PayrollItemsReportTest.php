<?php

namespace Tests\Hr\Feature;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Business\Models\Employee;
use Modules\Hr\Models\Payroll;
use Modules\Hr\Services\PayrollService;
use Modules\Performance\Models\CommissionOrder;
use Tests\TestCase;

/**
 * QA2-C01：工资项目扩展 + 工资报表 + Excel 导入。
 *
 *  - 4 个新工资项（工龄/加班/调整/报销）纳入净额公式，存量单据默认 0 不改口径
 *  - 调整工资可为负，其余项目负数拒绝
 *  - 工资报表按 部门 × 期间 汇总，合计与逐行之和自洽
 *  - 导入只覆盖手工项（提成是 perf 只读快照），坏行只报原因不牵连好行
 */
class PayrollItemsReportTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-04';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '薪酬专员']);
    }

    public function test_calc_net_adds_new_items_and_defaults_keep_old_formula(): void
    {
        // 四参调用（旧口径）结果不变：新增项默认 0
        $this->assertSame(7500.0, PayrollService::calcNet(5000, 2000, 500, 0));
        $this->assertSame(
            round(5000 + 300 + 2000 + 500 + 400 - 150 + (-200) + 120, 2),
            PayrollService::calcNet(5000, 2000, 500, 150, 300, 400, -200, 120)
        );
    }

    public function test_generated_payroll_carries_zero_defaults_for_new_items(): void
    {
        $payroll = $this->seedDraft();

        $this->assertSame(0.0, (float) $payroll->seniority_amount);
        $this->assertSame(0.0, (float) $payroll->overtime_amount);
        $this->assertSame(0.0, (float) $payroll->adjustment_amount);
        $this->assertSame(0.0, (float) $payroll->reimbursement_amount);
    }

    public function test_update_draft_applies_all_items_to_net(): void
    {
        $payroll = $this->seedDraft(); // 基本 1000 + 提成 1800 = 2800
        $before = (float) $payroll->net_amount;

        $resp = $this->putJson("/admin/hr/payrolls/{$payroll->id}", [
            'seniority_amount' => 300,
            'overtime_amount' => 200,
            'adjustment_amount' => -100,
            'reimbursement_amount' => 50,
        ])->assertOk();

        // JSON 里 3250.0 序列化成 3250，用松散比较断言数值本身
        $this->assertEqualsWithDelta($before + 450, (float) $resp->json('data.net_amount'), 0.001);

        $fresh = $payroll->fresh();
        $this->assertSame(300.0, (float) $fresh->seniority_amount);
        $this->assertSame(200.0, (float) $fresh->overtime_amount);
        $this->assertSame(-100.0, (float) $fresh->adjustment_amount);
        $this->assertSame(50.0, (float) $fresh->reimbursement_amount);
        $this->assertSame($before + 450, (float) $fresh->net_amount);
    }

    public function test_negative_amounts_rejected_except_adjustment(): void
    {
        $payroll = $this->seedDraft();

        foreach (['seniority_amount', 'overtime_amount', 'reimbursement_amount', 'deduction_amount'] as $field) {
            $this->putJson("/admin/hr/payrolls/{$payroll->id}", [$field => -1])->assertStatus(422);
        }

        // 全部被拒后单据分毫未动
        $this->assertSame(2800.0, (float) $payroll->fresh()->net_amount);
        $this->assertSame(0.0, (float) $payroll->fresh()->seniority_amount);
    }

    public function test_paid_payroll_still_rejects_any_edit(): void
    {
        $payroll = $this->seedDraft();
        $payroll->forceFill(['status' => Payroll::STATUS_PAID])->save();

        $this->putJson("/admin/hr/payrolls/{$payroll->id}", ['seniority_amount' => 300])
            ->assertStatus(422)
            ->assertJsonPath('message', '已发放工资单不能修改');
    }

    public function test_report_groups_by_department_and_period(): void
    {
        $payroll = $this->seedDraft();
        $this->putJson("/admin/hr/payrolls/{$payroll->id}", [
            'base_amount' => 4000,
            'seniority_amount' => 500,
            'deduction_amount' => 200,
        ])->assertOk();

        $resp = $this->getJson('/admin/hr/payrolls/report?period='.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('data.total.count', 1);

        $row = $resp->json('data.list.0');
        $this->assertSame('销售一部', $row['department_name']);
        $this->assertSame(self::PERIOD, $row['period']);
        $this->assertSame(1, $row['count']);
        $this->assertSame(4000.0, (float) $row['base_amount']);
        $this->assertSame(500.0, (float) $row['seniority_amount']);
        // 4000 + 提成 1800 + 500 - 200
        $this->assertSame(6100.0, (float) $row['net_amount']);
        // 报表合计与分组行之和自洽
        $this->assertSame(6100.0, (float) $resp->json('data.total.net_amount'));
        $this->assertSame(
            ['department_name', 'period', 'count', ...Payroll::AMOUNT_FIELDS],
            array_keys($row)
        );
    }

    public function test_report_is_empty_safe(): void
    {
        $resp = $this->getJson('/admin/hr/payrolls/report')->assertOk();

        $this->assertSame([], $resp->json('data.list'));
        $this->assertSame(0, $resp->json('data.total.count'));
        foreach (Payroll::AMOUNT_FIELDS as $field) {
            $this->assertSame(0.0, (float) $resp->json("data.total.{$field}"));
        }
    }

    public function test_import_updates_items_and_recalculates_net(): void
    {
        $payroll = $this->seedDraft();

        $this->postJson('/admin/hr/payrolls/import', [
            'period' => self::PERIOD,
            'file' => $this->makeUpload([
                [(string) $payroll->employee_id, '草稿业务员', 3000, 400, 600, 200, -50, 80, 130, '月度导入'],
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.error_count', 0);

        $fresh = $payroll->fresh();
        $this->assertSame(3000.0, (float) $fresh->base_amount);
        $this->assertSame(400.0, (float) $fresh->seniority_amount);
        $this->assertSame(200.0, (float) $fresh->overtime_amount);
        $this->assertSame(-50.0, (float) $fresh->adjustment_amount);
        $this->assertSame(80.0, (float) $fresh->reimbursement_amount);
        $this->assertSame(130.0, (float) $fresh->deduction_amount);
        // 3000 + 400 + 提成 1800 + 600 + 200 + (-50) + 80 - 130
        $this->assertSame(5900.0, (float) $fresh->net_amount);
        $this->assertSame('月度导入', $fresh->remark);
        // 提成列不在模板里，导入不得改写 perf 快照
        $this->assertSame(1800.0, (float) $fresh->commission_amount);
        $this->assertSame('draft', $fresh->status);
    }

    public function test_import_reports_bad_rows_without_blocking_good_ones(): void
    {
        $payroll = $this->seedDraft();

        $resp = $this->postJson('/admin/hr/payrolls/import', [
            'period' => self::PERIOD,
            'file' => $this->makeUpload([
                [(string) $payroll->employee_id, '草稿业务员', 2000, 0, 0, 0, 0, 0, 0],
                ['999999', '无此人', 100, 0, 0, 0, 0, 0, 0],
                [(string) $payroll->employee_id, '坏数字', 'abc', 0, 0, 0, 0, 0, 0],
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.error_count', 2);

        $this->assertSame(2000.0, (float) $payroll->fresh()->base_amount);
        $this->assertSame(['工号 999999'], $resp->json('data.unmatched'));
        $this->assertStringContainsString('没有工资单', $resp->json('data.errors.0'));
        $this->assertStringContainsString('基本工资必须是数字', $resp->json('data.errors.1'));
    }

    public function test_import_refuses_paid_period(): void
    {
        $payroll = $this->seedDraft();
        $payroll->forceFill(['status' => Payroll::STATUS_PAID])->save();

        $resp = $this->postJson('/admin/hr/payrolls/import', [
            'period' => self::PERIOD,
            'file' => $this->makeUpload([
                [(string) $payroll->employee_id, '草稿业务员', 2000, 0, 0, 0, 0, 0, 0],
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('data.success_count', 0)
            ->assertJsonPath('data.error_count', 1);

        $this->assertStringContainsString('已发放', $resp->json('data.errors.0'));
        $this->assertSame(1000.0, (float) $payroll->fresh()->base_amount);
    }

    public function test_import_requires_period_and_file(): void
    {
        $this->postJson('/admin/hr/payrolls/import', [])->assertStatus(422);
        $this->postJson('/admin/hr/payrolls/import', ['period' => '2026/04'])->assertStatus(422);
    }

    public function test_template_download_is_xlsx(): void
    {
        $resp = $this->get('/admin/hr/payrolls/template')->assertOk();
        $this->assertStringEndsWith('.xlsx', $resp->headers->get('content-disposition'));
    }

    // ---------- helpers ----------

    private function seedDraft(): Payroll
    {
        $salesman = $this->makeAdmin(['real_name' => '草稿业务员']);
        Employee::create([
            'code' => 'E'.$this->tag(),
            'name' => '草稿业务员',
            'user_id' => $salesman->id,
            'base_salary' => 1000,
            'role' => 'salesman',
            'is_active' => true,
        ]);
        CommissionOrder::create([
            'bill_no' => 'TX'.$this->tag(),
            'period' => self::PERIOD,
            'employee_id' => $salesman->id,
            'employee_name' => '草稿业务员',
            'department_id' => 1,
            'department_name' => '销售一部',
            'sales_amount' => 18500,
            'received_amount' => 18000,
            'base_amount' => 1850,
            'commission_amount' => 1850,
            'deduction' => 50,
            'actual_amount' => 1800,
            'status' => 'audited',
        ]);

        $this->postJson('/admin/hr/payrolls/generate', ['period' => self::PERIOD])->assertOk();

        return Payroll::where('period', self::PERIOD)->where('employee_id', $salesman->id)->firstOrFail();
    }

    /** 真实 xlsx（表头 + 数据行），列序与 PayrollService::importTemplateHead() 一致 */
    private function makeUpload(array $rows): UploadedFile
    {
        $filename = 'payroll_probe_'.uniqid().'.xlsx';
        Excel::store(new GenericExport(array_merge(
            [PayrollService::importTemplateHead()],
            $rows
        )), 'exports/'.$filename, 'public');

        return new UploadedFile(
            storage_path('app/public/exports/'.$filename),
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function tag(): string
    {
        return strtolower(bin2hex(random_bytes(5)));
    }
}
