<?php

namespace Tests\Ledger\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Period;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Seeders\LedgerSubjectSeeder;
use Modules\Ledger\Services\OpeningBalanceService;
use Modules\Ledger\Services\PeriodService;
use Modules\Ledger\Services\ReportService;
use Modules\Ledger\Services\VoucherGenerateService;
use Modules\Ledger\Services\VoucherService;
use Tests\Ledger\Feature\Concerns\CreatesBusinessBills;
use Tests\TestCase;

/**
 * QA-05「业务单据 → 总账凭证」链路验收测试。
 *
 * 每类单据一个用例，断言四件事（任务书验收标准）：
 *  1. 凭证确实生成，且借贷科目与口径一致；
 *  2. 分录条数 >= 2、借贷合计相等；
 *  3. 幂等——重复生成不新增行（审核自动 + 命令行补跑 + 人工重复点三条路都只留一张）；
 *  4. 派生单/未审核单/零金额单不能出凭证（防止同一笔业务记两遍）。
 */
class BillVoucherGenerateTest extends TestCase
{
    use CreatesBusinessBills, RefreshDatabase;

    private function seedSubjects(): void
    {
        $seeder = new LedgerSubjectSeeder;
        $seeder->setContainer(app());
        $seeder->callWith([LedgerSubjectSeeder::class]);
    }

    // ===== 销售出库：收入凭证 + 成本凭证 =====

    public function test_delivery_generates_income_and_cost_vouchers(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer('连凯商贸');
        $product = $this->billProduct(3.00);
        // 售价 12000、成本 200×3.00=600
        $bill = $this->billDelivery($customer, 12000, [[$product, 200]]);

        $first = app(VoucherGenerateService::class)->generateFromBusiness('delivery', $bill);

        $rows = $this->sourceVouchers('delivery', $bill);
        $this->assertCount(2, $rows, '销售出库应产出收入 + 成本两张凭证');
        $this->assertSame(1, (int) $rows[0]['source_seq']);
        $this->assertSame(2, (int) $rows[1]['source_seq']);
        $this->assertSame($rows[0]['voucher_no'], $first->voucher_no, '返回值固定是第 1 张');

        // 收入凭证：借 1122 应收账款（挂客户辅助核算）/ 贷 6001 主营业务收入
        $income = $this->entriesOf((int) $rows[0]['id']);
        $this->assertCount(2, $income);
        $this->assertEquals(12000, $this->amountOf($income, '1122')['debit']);
        $this->assertEquals(12000, $this->amountOf($income, '6001')['credit']);
        $ar = collect($income)->firstWhere('code', '1122');
        $this->assertSame('customer', $ar['aux_type']);
        $this->assertSame($customer, (int) $ar['aux_id']);
        $this->assertSame('连凯商贸', $ar['aux_name']);

        // 成本凭证：借 5401 主营业务成本 / 贷 1405 库存商品
        $cost = $this->entriesOf((int) $rows[1]['id']);
        $this->assertEquals(600, $this->amountOf($cost, '5401')['debit']);
        $this->assertEquals(600, $this->amountOf($cost, '1405')['credit']);

        foreach ($rows as $row) {
            $this->assertEquals((float) $row['total_debit'], (float) $row['total_credit'], '每张凭证借贷必须相等');
            $this->assertSame(Voucher::STATUS_DRAFT, $row['status'], '自动凭证先落草稿，由会计复核后审核入账');
        }
    }

    public function test_delivery_skips_cost_voucher_when_cost_is_zero(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $product = $this->billProduct(0);
        $bill = $this->billDelivery($customer, 500, [[$product, 10]]);

        app(VoucherGenerateService::class)->generateFromBusiness('delivery', $bill);

        $rows = $this->sourceVouchers('delivery', $bill);
        $this->assertCount(1, $rows, '成本为 0 时不应生成空的成本凭证');
        $this->assertSame(1, (int) $rows[0]['source_seq']);
    }

    public function test_delivery_rejects_unaudited_and_zero_amount_bills(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $svc = app(VoucherGenerateService::class);

        // status=0 草稿：审核是业务事实，未审核绝不能出凭证
        $draft = $this->billDelivery($customer, 1000, [], 0);
        try {
            $svc->generateFromBusiness('delivery', $draft);
            $this->fail('未审核单据应抛 DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('单据未审核', $e->getMessage());
        }

        // 金额 0：整单不产出凭证，抛业务异常让调用方记警告，而不是静默留一张空凭证
        $zero = $this->billDelivery($customer, 0, []);
        try {
            $svc->generateFromBusiness('delivery', $zero);
            $this->fail('金额为零应抛 DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('不满足生成凭证的条件', $e->getMessage());
        }

        $this->assertSame(0, DB::table('ledger_vouchers')->count());
    }

    // ===== 收款 =====

    public function test_receive_generates_voucher_by_payment_method(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer('收款客户');
        $svc = app(VoucherGenerateService::class);

        // 现金收款：借 1001 库存现金 / 贷 1122 应收账款-客户
        $cash = $this->billReceive($customer, 3000, '现金');
        $svc->generateFromBusiness('receive', $cash);
        $cashEntries = $this->entriesOf((int) $this->sourceVouchers('receive', $cash)[0]['id']);
        $this->assertEquals(3000, $this->amountOf($cashEntries, '1001')['debit']);
        $this->assertEquals(3000, $this->amountOf($cashEntries, '1122')['credit']);
        $this->assertSame('customer', collect($cashEntries)->firstWhere('code', '1122')['aux_type']);

        // 银行转账：借方换成 1002 银行存款
        $bank = $this->billReceive($customer, 2000, '银行转账');
        $svc->generateFromBusiness('receive', $bank);
        $bankEntries = $this->entriesOf((int) $this->sourceVouchers('receive', $bank)[0]['id']);
        $this->assertEquals(2000, $this->amountOf($bankEntries, '1002')['debit']);
        $this->assertEquals(0, $this->amountOf($bankEntries, '1001')['debit']);
    }

    public function test_regenerate_returns_same_voucher_without_new_rows(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $bill = $this->billReceive($customer, 800, '现金');
        $svc = app(VoucherGenerateService::class);

        $first = $svc->generateFromBusiness('receive', $bill);
        // 幂等：重复审核 / 命令行补跑 / 人工重复点，都只该拿回同一张
        $again = $svc->generateFromBusiness('receive', $bill);

        $this->assertSame($first->id, $again->id);
        $this->assertCount(1, $this->sourceVouchers('receive', $bill));
        $this->assertGreaterThanOrEqual(2, DB::table('ledger_voucher_entries')->where('voucher_id', $first->id)->count());

        // 半成品状态（seq=1 已生成、seq=2 缺失）重跑也不能新增 seq=1 的重复行
        $product = $this->billProduct(3.00);
        $delivery = $this->billDelivery($customer, 12000, [[$product, 200]]);
        $svc->generateFromBusiness('delivery', $delivery);
        $onlyFirst = $this->sourceVouchers('delivery', $delivery);
        DB::table('ledger_vouchers')->where('id', $onlyFirst[1]['id'])->delete();
        $this->assertCount(1, $this->sourceVouchers('delivery', $delivery));

        $svc->generateFromBusiness('delivery', $delivery);
        $after = $this->sourceVouchers('delivery', $delivery);
        $this->assertCount(2, $after, '缺失的 seq=2 应被补齐');
        $this->assertSame($onlyFirst[0]['voucher_no'], $after[0]['voucher_no'], '已存在的 seq=1 不得重建');
    }

    // ===== 付款 =====

    public function test_pay_debits_payable_and_credits_bank(): void
    {
        $this->seedSubjects();
        $supplier = $this->billSupplier('恒达供应');
        $bill = $this->billPay($supplier, 4500, '银行转账');

        app(VoucherGenerateService::class)->generateFromBusiness('pay', $bill);

        $entries = $this->entriesOf((int) $this->sourceVouchers('pay', $bill)[0]['id']);
        $this->assertEquals(4500, $this->amountOf($entries, '2202')['debit']);
        $this->assertEquals(4500, $this->amountOf($entries, '1002')['credit']);
        $ap = collect($entries)->firstWhere('code', '2202');
        $this->assertSame('supplier', $ap['aux_type']);
        $this->assertSame('恒达供应', $ap['aux_name']);
    }

    // ===== 费用 =====

    public function test_expense_maps_subject_by_expense_type(): void
    {
        $this->seedSubjects();
        $svc = app(VoucherGenerateService::class);

        // 类型关键词 → 科目：财务类须先于兜底判定，销售类走 6601，未匹配兜底 6602
        $cases = [
            '差旅费' => '6602',
            '业务招待费' => '6601',
            '运输费' => '6601',
            '银行手续费' => '6603',
            '借款利息' => '6603',
            '未知类型' => '6602',
        ];
        foreach ($cases as $type => $code) {
            $bill = $this->billExpense($type, 100);
            $svc->generateFromBusiness('expense', $bill);
            $entries = $this->entriesOf((int) $this->sourceVouchers('expense', $bill)[0]['id']);
            $this->assertEquals(100, $this->amountOf($entries, $code)['debit'], "费用类型「{$type}」应记入 {$code}");
        }
    }

    public function test_expense_credits_cash_by_default_and_bank_when_context_overrides(): void
    {
        $this->seedSubjects();
        $svc = app(VoucherGenerateService::class);

        // expenses 没有支付方式列，默认按现金出账（口径缺口已在生成器类注释里标注）
        $default = $this->billExpense('办公费', 260);
        $svc->generateFromBusiness('expense', $default);
        $entries = $this->entriesOf((int) $this->sourceVouchers('expense', $default)[0]['id']);
        $this->assertEquals(260, $this->amountOf($entries, '1001')['credit']);

        $override = $this->billExpense('办公费', 260);
        $svc->generateFromBusiness('expense', $override, ['payment_method' => '银行转账']);
        $entries2 = $this->entriesOf((int) $this->sourceVouchers('expense', $override)[0]['id']);
        $this->assertEquals(260, $this->amountOf($entries2, '1002')['credit']);
    }

    public function test_expense_excludes_bills_derived_from_stock_adjust(): void
    {
        $this->seedSubjects();
        $svc = app(VoucherGenerateService::class);

        // 库存调整审核时会顺手塞一张已审核的损耗费用单，凭证已由 stock_adjust 口径出过
        $derived = $this->billExpense('库存损耗', 999, 1, '库存调整损耗-TZ20261031000001');

        try {
            $svc->generateFromBusiness('expense', $derived);
            $this->fail('派生单据应被排除，避免同一笔损耗记两遍');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('派生', $e->getMessage());
        }

        $coverage = $svc->coverage(['expense']);
        $this->assertSame(0, $coverage['audited'], '派生单也不该被算成「漏生成」');
        $this->assertSame(0, $coverage['missing']);
    }

    // ===== 库存调整 =====

    public function test_stock_adjust_generates_loss_and_gain_vouchers(): void
    {
        $this->seedSubjects();
        $product = $this->billProduct(10);
        // 一条盘亏（-2×10=20）、一条盘盈（+1×10=10）
        $bill = $this->billStockAdjust([[$product, -2, 10], [$product, 1, 10]]);

        app(VoucherGenerateService::class)->generateFromBusiness('stock_adjust', $bill);

        $rows = $this->sourceVouchers('stock_adjust', $bill);
        $this->assertCount(2, $rows);

        // seq1 盘亏：借 6711 营业外支出 / 贷 1405 库存商品
        $loss = $this->entriesOf((int) $rows[0]['id']);
        $this->assertEquals(20, $this->amountOf($loss, '6711')['debit']);
        $this->assertEquals(20, $this->amountOf($loss, '1405')['credit']);

        // seq2 盘盈：借贷方向相反
        $gain = $this->entriesOf((int) $rows[1]['id']);
        $this->assertEquals(10, $this->amountOf($gain, '1405')['debit']);
        $this->assertEquals(10, $this->amountOf($gain, '6711')['credit']);
    }

    public function test_stock_adjust_with_zero_cost_is_not_pending(): void
    {
        $this->seedSubjects();
        $product = $this->billProduct(0);
        $bill = $this->billStockAdjust([[$product, -2, 0]]);
        $svc = app(VoucherGenerateService::class);

        // 明细成本全为 0：既不该进「待补生成」清单（否则结账检查③永远报漏），也不能出空凭证
        $this->assertSame(0, $svc->coverage(['stock_adjust'])['audited']);

        // 显式调用也走 billEligible 闸门，报「不满足条件」而不是「无需生成凭证」
        // （后者是 buildPayloads 里的兜底，只有在绕过闸门时才会出现）
        try {
            $svc->generateFromBusiness('stock_adjust', $bill);
            $this->fail('成本为零应抛 DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('不满足生成凭证的条件', $e->getMessage());
        }

        $this->assertSame(0, DB::table('ledger_vouchers')->count());
    }

    public function test_stock_adjust_draft_is_not_considered_audited(): void
    {
        $this->seedSubjects();
        $product = $this->billProduct(10);
        $bill = $this->billStockAdjust([[$product, -1, 10]], 'pending');

        $this->assertSame(0, app(VoucherGenerateService::class)->coverage(['stock_adjust'])['audited']);
    }

    // ===== 结账检查③ =====

    public function test_period_check_reports_missing_business_vouchers_without_blocking(): void
    {
        $this->seedSubjects();
        $period = date('Y-m');
        $svc = app(VoucherGenerateService::class);
        $item = collect(app(PeriodService::class)->check($period)['checks'])->firstWhere('key', 'business_voucher');

        $this->assertNotNull($item, '结账检查须包含 business_voucher 项');
        $this->assertTrue($item['passed']);
        $this->assertFalse($item['blocking'], '③ 是提示性黄色警告，不能阻止结账');

        $customer = $this->billCustomer();
        $this->billReceive($customer, 1000, '现金');
        $this->billExpense('办公费', 200);

        $item = collect(app(PeriodService::class)->check($period)['checks'])->firstWhere('key', 'business_voucher');
        $this->assertFalse($item['passed']);
        $this->assertSame(2, $item['audited_count']);
        $this->assertSame(2, $item['missing_count']);
        $this->assertSame(1, $item['type_detail']['receive']['missing']);
        $this->assertStringContainsString('ledger:generate-vouchers', $item['detail']);

        // 批量补生成后漏生成张数归零
        $result = $svc->generateBatch(Voucher::SOURCE_TYPES);
        $this->assertSame(2, $result['generated']);
        $this->assertSame(0, $result['failed']);

        $check = app(PeriodService::class)->check($period);
        $item = collect($check['checks'])->firstWhere('key', 'business_voucher');
        $this->assertTrue($item['passed']);
        $this->assertSame(0, $item['missing_count']);
        // 补生成的是草稿，①「本期凭证全部审核」此时应反过来拦住结账：
        // ③ 归零不代表账可以结，两个检查各管各的
        $first = collect($check['checks'])->firstWhere('key', 'all_audited');
        $this->assertFalse($first['passed'], '自动凭证是草稿，① 应报未审核');
        $this->assertTrue($first['blocking']);
        $this->assertFalse($check['can_close']);
    }

    public function test_draft_business_vouchers_block_close_and_audited_ones_allow_it(): void
    {
        $this->seedSubjects();
        $period = date('Y-m');
        $customer = $this->billCustomer();
        $bill = $this->billReceive($customer, 1000, '现金');
        app(VoucherGenerateService::class)->generateFromBusiness('receive', $bill);

        $periodSvc = app(PeriodService::class);

        // ① 是 blocking：草稿凭证未审核不能结账
        try {
            $periodSvc->close($period, 1, '财务');
            $this->fail('存在未审核凭证时不能结账');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('结账检查未通过', $e->getMessage());
        }

        // 审核后即可结账，检查报告里 ③ 也应为通过
        app(VoucherService::class)->auditOne(
            (int) $this->sourceVouchers('receive', $bill)[0]['id'],
            1,
            '审核员'
        );
        $closed = $periodSvc->close($period, 1, '财务');
        $this->assertSame(Period::STATUS_CLOSED, $closed->status);

        $report = $closed->check_report; // Period 已 cast 成 array
        $item = collect($report['checks'])->firstWhere('key', 'business_voucher');
        $this->assertTrue($item['passed']);
        $this->assertSame(0, $item['missing_count']);
    }

    // ===== 端到端：生成 → 审核 → 结转 → 报表平衡 =====

    public function test_business_bills_flow_through_to_balanced_reports(): void
    {
        $this->seedSubjects();
        $svc = app(VoucherGenerateService::class);
        $period = date('Y-m');
        // 报表期初只认「年初之前」的期初行（ReportService::openingBalanceBefore 用 period < yearStart），
        // 所以期初要录在去年 12 月；录在本年任意月份都会被整体忽略，报表退化成全 0 的假平衡。
        $openingPeriod = date('Y-m', strtotime(substr($period, 0, 4).'-01 -1 day'));

        // 期初：借 1001 现金 20000 + 借 1405 库存商品 10000 = 贷 4001 实收资本 30000
        // 期初本身必须平衡，否则报表平不平跟本链路无关，测不出东西
        $opening = app(OpeningBalanceService::class);
        $opening->save($openingPeriod, [
            ['subject_id' => $this->subjectIdByCode('1001'), 'opening_debit' => 20000, 'opening_credit' => 0],
            ['subject_id' => $this->subjectIdByCode('1405'), 'opening_debit' => 10000, 'opening_credit' => 0],
            ['subject_id' => $this->subjectIdByCode('4001'), 'opening_debit' => 0, 'opening_credit' => 30000],
        ]);

        $customer = $this->billCustomer('连凯商贸');

        // 本月销售出库：收入 12000、成本 200×35=7000
        $product = $this->billProduct(35);
        $delivery = $this->billDelivery($customer, 12000, [[$product, 200]]);

        // 库存调整盘亏 500（借 6711 / 贷 1405），让营业外支出也进利润表
        $adjust = $this->billStockAdjust([[$product, -5, 100]]);

        foreach ([['delivery', $delivery], ['stock_adjust', $adjust]] as [$type, $id]) {
            $svc->generateFromBusiness($type, $id);
        }

        // 全部生成的凭证逐张审核入账（自动凭证一律先草稿，报表只认已审核）
        $voucherSvc = app(VoucherService::class);
        $generated = DB::table('ledger_vouchers')->whereNotNull('source_type')->orderBy('id')->pluck('id')->all();
        // 本月出库收入/成本 2 张 + 盘亏 1 张
        $this->assertCount(3, $generated);
        foreach ($generated as $id) {
            $voucherSvc->auditOne((int) $id, 1, '审核员');
        }

        // 先审结转前的利润表：证明业务单据真进了损益（结转审核后损益科目会被对銷归零，
        // 那时再取利润表就全是 0，测不出本链路）
        $income = app(ReportService::class)->incomeStatement($period);
        $rowOf = fn (string $code) => $this->rowOf($income, $code);
        $this->assertEquals(12000, $rowOf('operating_revenue'), '本月营业收入');
        $this->assertEquals(7000, $rowOf('operating_cost'));
        $this->assertEquals(500, $rowOf('non_operating_expense'));
        $this->assertEquals(4500, $rowOf('total_profit'));
        $this->assertEquals(4500, $income['net_profit']);
        $this->assertEquals(4500, $income['year_net_profit']);

        // 结转损益（生成的也是草稿，同样要审核才进报表）
        $carry = app(PeriodService::class)->carryForward($period, 1, '财务');
        $this->assertSame(Voucher::STATUS_DRAFT, $carry->status);
        $this->assertEquals(12000, (float) $carry->total_debit, '结转凭证借方 = 本期收入发生额');
        $voucherSvc->auditOne($carry->id, 1, '审核员');

        $sheet = app(ReportService::class)->balanceSheet($period);
        $this->assertTrue($sheet['balanced'], '资产负债表必须平衡，diff='.(string) $sheet['diff']);
        $this->assertEquals(0.0, (float) $sheet['diff']);
        $this->assertNotEmpty($sheet['left']);
        $this->assertNotEmpty($sheet['right']);

        $endOf = fn (array $rows, string $code) => (float) collect($rows)->firstWhere('code', $code)['end'];
        // 货币资金 = 期初 20000（出库挂应收、盘亏动存货，都不动现金）
        $this->assertEquals(20000, $endOf($sheet['left'], 'monetary'));
        // 应收账款 = 出库 12000
        $this->assertEquals(12000, $endOf($sheet['left'], 'accounts_receivable'));
        // 存货：期初 10000 - 出库成本 7000 - 盘亏 500
        $this->assertEquals(2500, $endOf($sheet['left'], 'inventory'));
        // 资产总计 20000 + 12000 + 2500 = 34500 = 权益 30000（实收资本）+ 4500（结转后的本年利润）
        $this->assertEquals(34500, $endOf($sheet['left'], 'total_assets'));
        $this->assertEquals(30000, $endOf($sheet['right'], 'paid_in_capital'));
        $this->assertEquals(4500, $endOf($sheet['right'], 'undistributed_profit'), '结转损益要落进 4103 本年利润');
        $this->assertEquals(34500, $endOf($sheet['right'], 'total_liab_equity'));
        $this->assertEquals(
            $endOf($sheet['left'], 'total_assets'),
            $endOf($sheet['right'], 'total_liab_equity'),
            '资产总计 = 负债 + 所有者权益总计'
        );

        // 结转凭证审核后损益科目发生额被对銷：利润表归零、报表出利润，是月结后的正确形态
        $carried = app(ReportService::class)->incomeStatement($period);
        $this->assertEquals(0.0, $this->rowOf($carried, 'operating_revenue'), '结转审核后利润表应归零（否则利润算了两遍）');
        $this->assertEquals(0.0, $carried['net_profit']);
    }

    /** 利润表按科目行编码取「本月数」 */
    private function rowOf(array $income, string $code): float
    {
        return (float) collect($income['rows'])->firstWhere('code', $code)['month'];
    }

    public function test_voucher_period_follows_bill_date_not_today(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $bill = $this->billReceive($customer, 1500, '现金');
        $lastMonth = date('Y-m', strtotime(date('Y-m-01').' -1 month'));
        // sqlite 的 date 列带时分秒，写纯日期串即可，生成器按 strtotime 原样解析
        DB::table('receives')->where('id', $bill)->update(['receive_date' => $lastMonth.'-20']);

        app(VoucherGenerateService::class)->generateFromBusiness('receive', $bill);

        // 上月单据必须落进上月期间，否则上月结账后本月报表会凭空多出一笔
        $this->assertSame($lastMonth, $this->sourceVouchers('receive', $bill)[0]['period']);
    }

    // ===== 接口 =====

    public function test_endpoint_generates_vouchers_and_is_idempotent(): void
    {
        $this->seedSubjects();
        $this->actingAsAdmin();
        $customer = $this->billCustomer('接口客户');
        $product = $this->billProduct(3.00);
        $bill = $this->billDelivery($customer, 12000, [[$product, 200]]);

        $this->postJson('/admin/ledger/vouchers/generate-from-business', [
            'bill_type' => 'delivery',
            'bill_id' => $bill,
        ])->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.vouchers.0.source_seq', 1)
            ->assertJsonPath('data.vouchers.1.source_seq', 2)
            ->assertJsonPath('data.voucher.total_debit', 12000);

        // 重复调用：只返回已生成的，不长出重复凭证
        $this->postJson('/admin/ledger/vouchers/generate-from-business', [
            'bill_type' => 'delivery',
            'bill_id' => $bill,
        ])->assertOk();
        $this->assertCount(2, $this->sourceVouchers('delivery', $bill));
    }

    public function test_endpoint_validates_bill_type_and_missing_bill(): void
    {
        $this->seedSubjects();
        $this->actingAsAdmin();

        $this->postJson('/admin/ledger/vouchers/generate-from-business', [
            'bill_type' => 'deposit',
            'bill_id' => 1,
        ])->assertStatus(422);

        $this->postJson('/admin/ledger/vouchers/generate-from-business', [
            'bill_type' => 'receive',
            'bill_id' => 999999,
        ])->assertStatus(422)->assertJsonPath('code', 422)->assertJsonPath('message', '未找到来源单据：receive#999999');
    }

    public function test_closed_period_blocks_generation_with_domain_error(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $bill = $this->billReceive($customer, 1000, '现金');

        // 直接封期间（不走 close()，close 会先被未审核凭证拦住）；
        // 本期期间行可能已由期初/前序逻辑建好，这里按 (year,month) 唯一键 upsert 状态
        Period::query()->updateOrCreate(
            ['year' => (int) date('Y'), 'month' => (int) date('m')],
            [
                'start_date' => date('Y-m-01'),
                'end_date' => date('Y-m-t'),
                'status' => Period::STATUS_CLOSED,
            ]
        );

        try {
            app(VoucherGenerateService::class)->generateFromBusiness('receive', $bill);
            $this->fail('已月结期间应禁止生成凭证');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('已月结', $e->getMessage());
        }
        $this->assertSame(0, DB::table('ledger_vouchers')->count());
    }

    public function test_generation_marks_maker_from_context(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $bill = $this->billReceive($customer, 100, '现金');

        app(VoucherGenerateService::class)->generateFromBusiness('receive', $bill, [
            'maker_id' => 7,
            'maker_name' => '张会计',
        ]);

        $row = $this->sourceVouchers('receive', $bill)[0];
        $this->assertSame('张会计', $row['maker_name'], '命令行/审核人等制单人来源要能落到凭证上');
    }
}
