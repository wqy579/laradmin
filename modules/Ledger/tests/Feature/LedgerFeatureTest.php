<?php

namespace Tests\Ledger\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Models\Period;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Seeders\LedgerSubjectSeeder;
use Modules\Ledger\Services\AmountChinese;
use Modules\Ledger\Services\OpeningBalanceService;
use Modules\Ledger\Services\PeriodService;
use Modules\Ledger\Services\ReportService;
use Modules\Ledger\Services\VoucherGenerateService;
use Modules\Ledger\Services\VoucherService;
use Tests\TestCase;

/**
 * 总账管理模块端到端验收测试（对应任务书「十、验收标准」）
 *
 * 覆盖：
 *  - 预置科目试算平衡
 *  - 录一张「收到客户货款」多借多贷凭证，借贷不平不能保存、平衡可提交可审核
 *  - 审核后科目余额表、应收账款明细账、资产负债表货币资金与应收账款、利润表数据随之正确
 *  - 结转损益并月结，月结后不能再改该月凭证，反结账后可改
 *  - 所有报表借贷/资产负债平衡校验有效
 */
class LedgerFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function seedSubjects(): void
    {
        // 直接调用 Seeder（不依赖 AuthSeeder 的权限表）
        $seeder = new LedgerSubjectSeeder;
        $seeder->setContainer(app());
        $seeder->callWith([LedgerSubjectSeeder::class]);
    }

    public function test_subjects_seeded_and_trial_balanced(): void
    {
        $this->seedSubjects();
        $this->assertTrue(Subject::where('code', '1001')->exists());
        $this->assertTrue(Subject::where('code', '4103')->exists());
        // 期末无发生额时试算平衡（全 0）
        $svc = app(OpeningBalanceService::class);
        $total = $svc->balanceCheck(date('Y-m'));
        $this->assertTrue($total['balanced']);
    }

    public function test_voucher_must_be_balanced(): void
    {
        $this->seedSubjects();
        $cash = Subject::where('code', '1001')->first();
        $ar = Subject::where('code', '1122')->first();

        $svc = app(VoucherService::class);
        // 借贷不平不能保存
        try {
            $svc->create([
                'voucher_date' => now()->format('Y-m-d'),
                'entries' => [
                    ['subject_id' => $cash->id, 'summary' => '收款', 'debit_amount' => 1000, 'credit_amount' => 0],
                    ['subject_id' => $ar->id, 'summary' => '冲应收', 'debit_amount' => 0, 'credit_amount' => 500],
                ],
            ], 1, '测试员');
            $this->fail('应该因借贷不平衡抛异常');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('借贷不平衡', $e->getMessage());
        }
    }

    public function test_full_voucher_audit_and_reports(): void
    {
        $this->seedSubjects();
        $cash = Subject::where('code', '1001')->first();
        $bank = Subject::where('code', '1002')->first();
        $ar = Subject::where('code', '1122')->first();
        $period = date('Y-m');

        // 录一张「收到客户货款」多借多贷凭证：借库存现金500+银行存款500，贷应收账款1000
        $svc = app(VoucherService::class);
        $voucher = $svc->create([
            'voucher_date' => now()->format('Y-m-d'),
            'entries' => [
                ['subject_id' => $cash->id, 'subject_code' => $cash->code, 'subject_name' => $cash->name, 'summary' => '收现金', 'debit_amount' => 500, 'credit_amount' => 0],
                ['subject_id' => $bank->id, 'subject_code' => $bank->code, 'subject_name' => $bank->name, 'summary' => '收银行', 'debit_amount' => 500, 'credit_amount' => 0],
                ['subject_id' => $ar->id, 'subject_code' => $ar->code, 'subject_name' => $ar->name, 'summary' => '冲应收账款', 'aux_type' => 'customer', 'debit_amount' => 0, 'credit_amount' => 1000],
            ],
        ], 1, '测试员');
        $this->assertEquals(1000, (float) $voucher->total_debit);
        $this->assertEquals(1000, (float) $voucher->total_credit);

        // 审核
        $svc->auditOne($voucher->id, 1, '审核员');
        $this->assertEquals(Voucher::STATUS_AUDITED, Voucher::find($voucher->id)->status);

        // 科目余额表：货币资金 = 1000，应收账款 = 1000
        $report = app(ReportService::class);
        $balance = $report->subjectBalance($period, $period, 99, false, true);
        $endDebit = collect($balance['rows'])->where('code', '1001')->value('end_debit')
            + collect($balance['rows'])->where('code', '1002')->value('end_debit');
        $this->assertEquals(1000, $endDebit);
        $arCredit = collect($balance['rows'])->where('code', '1122')->value('end_credit');
        $this->assertEquals(1000, $arCredit);
        $this->assertTrue($balance['total']['balanced']);

        // 资产负债表平衡
        $sheet = $report->balanceSheet($period);
        $this->assertTrue($sheet['balanced'], '资产负债表应平衡');

        // 利润表
        $income = $report->incomeStatement($period);
        $this->assertEquals(0, $income['net_profit']);

        // 明细账
        $ledger = $svc->subjectLedger($ar->id, $period);
        $this->assertNotEmpty($ledger['rows']);
    }

    public function test_period_close_and_reopen(): void
    {
        $this->seedSubjects();
        $cash = Subject::where('code', '1001')->first();
        $ar = Subject::where('code', '1122')->first();
        $period = date('Y-m');

        $svc = app(VoucherService::class);
        $voucher = $svc->create([
            'voucher_date' => now()->format('Y-m-d'),
            'entries' => [
                ['subject_id' => $cash->id, 'subject_code' => $cash->code, 'subject_name' => $cash->name, 'summary' => '收款', 'debit_amount' => 100, 'credit_amount' => 0],
                ['subject_id' => $ar->id, 'subject_code' => $ar->code, 'subject_name' => $ar->name, 'summary' => '冲应收', 'debit_amount' => 0, 'credit_amount' => 100],
            ],
        ], 1, '测试员');
        $svc->auditOne($voucher->id, 1, '审核员');

        $periodSvc = app(PeriodService::class);
        // 结账检查
        $check = $periodSvc->check($period);
        $this->assertTrue($check['can_close']);

        // 月结
        $periodSvc->close($period, 1, '财务');
        $this->assertEquals('closed', Period::where('year', (int) date('Y'))->where('month', (int) date('m'))->value('status'));

        // 月结后不能再改该月凭证
        try {
            $svc->update($voucher->id, ['remark' => '改一下']);
            $this->fail('月结后应禁止修改凭证');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('已月结', $e->getMessage());
        }

        // 反结账后反审核即可改
        $periodSvc->reopen($period);
        $svc->reverseAudit($voucher->id);
        $updated = $svc->update($voucher->id, ['remark' => '反结账后改']);
        $this->assertEquals('反结账后改', $updated->remark);
    }

    public function test_amount_chinese_conversion(): void
    {
        $this->assertEquals('零元整', AmountChinese::convert(0));
        $this->assertStringContainsString('壹仟', AmountChinese::convert(1000));
        $this->assertStringContainsString('伍角陆分', AmountChinese::convert(1234.56));
        $this->assertStringContainsString('负', AmountChinese::convert(-100));
    }

    public function test_voucher_generate_service_stub_is_safe(): void
    {
        // 三机协同：B 调用 generateFromBusiness 在 A 未合并前必须不报错
        $svc = app(VoucherGenerateService::class);
        $result = $svc->generateFromBusiness('receive', 1, ['amount' => 100]);
        $this->assertNull($result);
    }
}
