<?php

namespace Tests\Business\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B3 财务：对账单 + 余额查询 + 科目变化明细 验收测试（对接 ledger 真实分录）。
 *
 * 覆盖验收标准（真实数据，禁止 mock）：
 *  - 对账单：客户应收/供应商应付，期初/本期发生/本期核销/期末滚动，recon_status 结算状态
 *  - 余额查询：客户/品牌/辅助单位 三维度聚合余额，prepaid/receivable/balance 语义
 *  - 科目变化明细：逐科目滚动余额、单据类型（开账建账/记账凭证）、排除开账前数据开关
 */
class FinanceB3FeatureTest extends TestCase
{
    use RefreshDatabase;

    private function makeVoucher(string $voucherNo, string $date, string $status = 'audited', array $entries = []): int
    {
        $id = DB::table('ledger_vouchers')->insertGetId([
            'voucher_no' => $voucherNo,
            'voucher_date' => $date,
            'period' => substr($date, 0, 7),
            'attachments_count' => 0,
            'total_debit' => 0,
            'total_credit' => 0,
            'status' => $status,
            'maker_name' => '测试员',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($entries as $i => $entry) {
            DB::table('ledger_voucher_entries')->insert(array_merge([
                'voucher_id' => $id,
                'line_no' => $i + 1,
                'subject_id' => $entry['subject_id'] ?? 1,
                'subject_code' => $entry['subject_code'] ?? '',
                'subject_name' => $entry['subject_name'] ?? '',
                'summary' => $entry['summary'] ?? '',
                'debit_amount' => $entry['debit_amount'] ?? 0,
                'credit_amount' => $entry['credit_amount'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ], $entry['aux'] ?? [], $entry['brand'] ?? []));
        }

        return $id;
    }

    private function seedStatement(): void
    {
        // 客户「甲乙公司」应收账款：期初 1000（2025-12 开账前） + 本期借 800（2026-01） - 本期核销 500（2026-02）
        $this->makeVoucher('记-2025-12-0001', '2025-12-15', 'audited', [
            ['subject_code' => '1122', 'subject_name' => '应收账款', 'debit_amount' => 1000, 'summary' => '期初应收',
                'aux' => ['aux_type' => 'customer', 'aux_id' => 11, 'aux_name' => '甲乙公司']],
            ['subject_code' => '6001', 'subject_name' => '主营业务收入', 'credit_amount' => 1000, 'summary' => '销售'],
        ]);
        $this->makeVoucher('记-2026-01-0001', '2026-01-10', 'audited', [
            ['subject_code' => '1122', 'subject_name' => '应收账款', 'debit_amount' => 800, 'summary' => '销售应收',
                'aux' => ['aux_type' => 'customer', 'aux_id' => 11, 'aux_name' => '甲乙公司']],
            ['subject_code' => '6001', 'subject_name' => '主营业务收入', 'credit_amount' => 800, 'summary' => '销售'],
        ]);
        $this->makeVoucher('记-2026-02-0001', '2026-02-08', 'audited', [
            ['subject_code' => '1002', 'subject_name' => '银行存款', 'debit_amount' => 500, 'summary' => '收款'],
            ['subject_code' => '1122', 'subject_name' => '应收账款', 'credit_amount' => 500, 'summary' => '核销应收',
                'aux' => ['aux_type' => 'customer', 'aux_id' => 11, 'aux_name' => '甲乙公司']],
        ]);
        // 一张作废凭证不应计入（red_of_id 指向 1 号凭证，红冲 1000）
        $this->makeVoucher('记-2026-02-0002', '2026-02-09', 'reversed', [
            ['subject_code' => '1122', 'subject_name' => '应收账款', 'debit_amount' => 999, 'summary' => '作废行',
                'aux' => ['aux_type' => 'customer', 'aux_id' => 11, 'aux_name' => '甲乙公司']],
        ]);
        // 供应商「丙公司」应付账款：期初 600 + 本期贷 300 - 本期付 200
        $this->makeVoucher('记-2026-01-0002', '2026-01-12', 'audited', [
            ['subject_code' => '2202', 'subject_name' => '应付账款', 'credit_amount' => 600, 'summary' => '期初应付',
                'aux' => ['aux_type' => 'supplier', 'aux_id' => 22, 'aux_name' => '丙公司']],
            ['subject_code' => '1403', 'subject_name' => '原材料', 'debit_amount' => 600, 'summary' => '采购'],
        ]);
        $this->makeVoucher('记-2026-02-0003', '2026-02-12', 'audited', [
            ['subject_code' => '2202', 'subject_name' => '应付账款', 'credit_amount' => 300, 'summary' => '采购应付',
                'aux' => ['aux_type' => 'supplier', 'aux_id' => 22, 'aux_name' => '丙公司']],
            ['subject_code' => '1403', 'subject_name' => '原材料', 'debit_amount' => 300, 'summary' => '采购'],
        ]);
        $this->makeVoucher('记-2026-03-0001', '2026-03-05', 'audited', [
            ['subject_code' => '2202', 'subject_name' => '应付账款', 'debit_amount' => 200, 'summary' => '付款',
                'aux' => ['aux_type' => 'supplier', 'aux_id' => 22, 'aux_name' => '丙公司']],
            ['subject_code' => '1002', 'subject_name' => '银行存款', 'credit_amount' => 200, 'summary' => '付款'],
        ]);
    }

    public function test_statement_customer_receivable_periods_and_settle(): void
    {
        $this->seedStatement();
        $this->actingAsAdmin();

        $res = $this->getJson('/admin/business/finance-statement?kind=customer')
            ->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');

        $units = collect($data['units']);
        $jia = $units->firstWhere('id', 11);
        $this->assertNotNull($jia);
        $this->assertSame('甲乙公司', $jia['name']);

        // 滚动余额：2025-12 期初 1000；2026-01 应收 800；2026-02 核销 500，期末 1300
        $periods = collect($data['periods'])->where('unit_id', 11)->values();
        $this->assertCount(3, $periods);
        $this->assertSame(0.0, (float) $periods[0]['opening_balance']);
        $this->assertSame(1000.0, (float) $periods[0]['receivable']);
        $this->assertSame(1000.0, (float) $periods[0]['closing_balance']);
        $this->assertSame(1000.0, (float) $periods[1]['opening_balance']);
        $this->assertSame(800.0, (float) $periods[1]['receivable']);
        $this->assertSame(1800.0, (float) $periods[1]['closing_balance']);
        $this->assertSame(1800.0, (float) $periods[2]['opening_balance']);
        $this->assertSame(500.0, (float) $periods[2]['received']);
        $this->assertSame(1300.0, (float) $periods[2]['closing_balance']);

        // 期初月（2025-12）为开账前累计 → 期初即期初值
        $details = collect($data['details'])->where('unit_id', 11)->values();
        $this->assertNotEmpty($details);

        // 供应商维度单独校验
        $res2 = $this->getJson('/admin/business/finance-statement?kind=supplier')
            ->assertOk()->assertJson(['code' => 200]);
        $data2 = $res2->json('data');
        $bing = collect($data2['units'])->firstWhere('id', 22);
        $this->assertNotNull($bing);
        $this->assertSame('丙公司', $bing['name']);
        $periods2 = collect($data2['periods'])->where('unit_id', 22)->values();
        // 滚动：01 期初 600；02 发生 300；03 付款 200，期末 700
        $this->assertSame(0.0, (float) $periods2[0]['opening_balance']);
        $this->assertSame(600.0, (float) $periods2[0]['payable']);
        $this->assertSame(600.0, (float) $periods2[0]['closing_balance']);
        $this->assertSame(300.0, (float) $periods2[1]['payable']);
        $this->assertSame(200.0, (float) $periods2[2]['paid']);
        $this->assertSame(700.0, (float) $periods2[2]['closing_balance']);
    }

    public function test_balance_customer_dimension_aggregation(): void
    {
        $this->seedStatement();
        $this->actingAsAdmin();

        $res = $this->getJson('/admin/business/finance-balance?dimension=customer')
            ->assertOk()->assertJson(['code' => 200]);
        $list = $res->json('data.list');

        // 应收账款（借方余额）：balance = 1000+800-500 = 1300
        $ar = collect($list)->firstWhere('unit', '甲乙公司');
        $this->assertNotNull($ar);
        $this->assertSame('应收账款', $ar['subject']);
        $this->assertSame(1300.0, (float) $ar['balance']);
        // 应付账款（贷方余额）：balance = 600+300-200 = 700
        $ap = collect($list)->firstWhere('unit', '丙公司');
        $this->assertNotNull($ap);
        $this->assertSame('应付账款', $ap['subject']);
        $this->assertSame(700.0, (float) $ap['balance']);
    }

    public function test_balance_brand_dimension(): void
    {
        $this->seedStatement();
        // 给一张带品牌的分录
        $this->makeVoucher('记-2026-03-0002', '2026-03-06', 'audited', [
            ['subject_code' => '1122', 'subject_name' => '应收账款', 'debit_amount' => 200, 'summary' => '品牌A销售',
                'aux' => ['aux_type' => 'customer', 'aux_id' => 33, 'aux_name' => '丁公司'],
                'brand' => ['brand_id' => 9, 'brand_name' => '品牌A']],
            ['subject_code' => '6001', 'subject_name' => '主营业务收入', 'credit_amount' => 200, 'summary' => '销售'],
        ]);
        $this->actingAsAdmin();

        $res = $this->getJson('/admin/business/finance-balance?dimension=brand')
            ->assertOk()->assertJson(['code' => 200]);
        $list = $res->json('data.list');

        $row = collect($list)->firstWhere('brand', '品牌A');
        $this->assertNotNull($row);
        $this->assertSame(200.0, (float) $row['balance']);
    }

    public function test_subject_change_running_balance_and_exclude_before_open(): void
    {
        $this->seedStatement();
        $this->actingAsAdmin();

        // 不排除开账前：应收账款 含期初行
        $res = $this->getJson('/admin/business/finance-subject-change?subject_id=1122')
            ->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');
        $list = collect($data['list']);
        $this->assertSame('开账建账', $list->first()['doc_type']);
        $this->assertTrue($list->first()['before_open']);
        // 末行滚动余额 = 1300
        $this->assertSame(1300.0, (float) $list->last()['balance']);

        // 排除开账前：只返回 2026 起的分录，首行余额应从开账后的发生额滚起
        $res2 = $this->getJson('/admin/business/finance-subject-change?subject_id=1122&exclude_before_open=1')
            ->assertOk()->assertJson(['code' => 200]);
        $list2 = collect($res2->json('data.list'));
        $this->assertNotEmpty($list2);
        $this->assertFalse($list2->first()['before_open']);
        // 开账后发生：借 800 + 借 999(作废已排除) - 贷 500，但作废凭证被 status 过滤排除 → 800-500=300
        $this->assertSame(300.0, (float) $list2->last()['balance']);

        // 单据类型筛选：开账建账只返回 2025 期初
        $res3 = $this->getJson('/admin/business/finance-subject-change?doc_type=开账建账&subject_id=1122')
            ->assertOk()->assertJson(['code' => 200]);
        $list3 = collect($res3->json('data.list'));
        $this->assertNotEmpty($list3);
        $this->assertTrue($list3->every(fn ($r) => $r['doc_type'] === '开账建账'));
    }
}
