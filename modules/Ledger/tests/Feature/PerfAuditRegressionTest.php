<?php

namespace Tests\Ledger\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Seeders\LedgerSubjectSeeder;
use Modules\Ledger\Services\ReportService;
use Modules\Ledger\Services\VoucherService;
use Tests\TestCase;

/**
 * 性能与健壮性审计回归（PERF-AUDIT-A）
 *
 * 覆盖本次修复：
 *  - N+1：凭证列表/详情/明细账的对方科目与红冲单号批量预取（VoucherService）
 *  - N+1：资产负债表/利润表按科目编码批量取余额方向（ReportService）
 *  - 索引：ledger_vouchers.maker_id/auditor_id、ledger_voucher_entries.aux_id、
 *          ledger_periods.closed_by、ledger_voucher_templates.creator_id
 */
class PerfAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $seeder = new LedgerSubjectSeeder;
        $seeder->setContainer(app());
        $seeder->callWith([LedgerSubjectSeeder::class]);

        // 开启查询日志用于 N+1 断言（每次比较前清空）
        DB::enableQueryLog();
    }

    /** 造一张已审核凭证：借 cash 100，贷 capital 4001（资产负债表科目，保证报表平衡） */
    private function makeVoucher(string $period = '2026-10'): Voucher
    {
        $cash = Subject::where('code', '1001')->firstOrFail();
        $capital = Subject::where('code', '4001')->firstOrFail();

        $svc = app(VoucherService::class);
        $voucher = $svc->create([
            'voucher_date' => $period.'-15',
            'entries' => [
                ['subject_id' => $cash->id, 'subject_code' => $cash->code, 'subject_name' => $cash->name, 'summary' => '收款', 'debit_amount' => 100, 'credit_amount' => 0],
                ['subject_id' => $capital->id, 'subject_code' => $capital->code, 'subject_name' => $capital->name, 'summary' => '投入', 'debit_amount' => 0, 'credit_amount' => 100],
            ],
        ], 1, '制单员');
        $svc->auditOne($voucher->id, 1, '审核员');

        return $voucher;
    }

    /** N+1 回归：明细账对方科目批量取回，只应产生固定条数查询（不受分录数影响） */
    public function test_subject_ledger_batch_preloads_opposite_subjects(): void
    {
        // 造 3 张凭证，共 6 条分录
        foreach (['2026-10-05', '2026-10-10', '2026-10-20'] as $date) {
            $cash = Subject::where('code', '1001')->firstOrFail();
            $revenue = Subject::where('code', '6001')->firstOrFail();
            app(VoucherService::class)->create([
                'voucher_date' => $date,
                'entries' => [
                    ['subject_id' => $cash->id, 'subject_code' => $cash->code, 'subject_name' => $cash->name, 'summary' => '收款', 'debit_amount' => 100, 'credit_amount' => 0],
                    ['subject_id' => $revenue->id, 'subject_code' => $revenue->code, 'subject_name' => $revenue->name, 'summary' => '收入', 'debit_amount' => 0, 'credit_amount' => 100],
                ],
            ], 1, '制单员')->update(['status' => Voucher::STATUS_AUDITED]);
        }

        $cash = Subject::where('code', '1001')->firstOrFail();

        $before = $this->queryCount();
        $ledger = app(VoucherService::class)->subjectLedger($cash->id, '2026-10');
        $queries = $this->queryCount() - $before;

        // 期初查询 + 凭证分录(with voucher) + 对方科目两条 whereIn
        // 断言上限：无论多少张凭证，对方科目都只应产生 2 条批量查询。
        $this->assertLessThanOrEqual(6, $queries, "明细账查询数应恒定（当前 {$queries}，疑似 N+1）");
        $this->assertNotEmpty($ledger['rows']);
        // 每张借方凭证的「对方科目」都应正确预取为「主营业务收入」；
        // 排除期初/本期合计（voucher_no 为 null）两行
        $debitRows = array_values(array_filter($ledger['rows'], fn ($r) => $r['debit'] > 0 && $r['voucher_no'] !== null));
        $this->assertCount(3, $debitRows);
        foreach ($debitRows as $r) {
            $this->assertSame('主营业务收入', $r['opposite_subject']);
        }
    }

    /** N+1 回归：列表/详情红冲单号在已预载 reversal 时不触发逐条查库 */
    public function test_list_and_detail_preload_reversal_voucher_no(): void
    {
        $original = $this->makeVoucher('2026-10');
        $cash = Subject::where('code', '1001')->firstOrFail();
        $revenue = Subject::where('code', '6001')->firstOrFail();

        // 生成红冲单
        $svc = app(VoucherService::class);
        $red = $svc->create([
            'voucher_date' => '2026-10-20',
            'entries' => [
                ['subject_id' => $cash->id, 'subject_code' => $cash->code, 'subject_name' => $cash->name, 'summary' => '红冲', 'debit_amount' => -100, 'credit_amount' => 0],
                ['subject_id' => $revenue->id, 'subject_code' => $revenue->code, 'subject_name' => $revenue->name, 'summary' => '红冲', 'debit_amount' => 0, 'credit_amount' => -100],
            ],
        ], 1, '制单员');
        $red->red_of_id = $original->id;
        $red->is_red = true;
        $red->save();

        // 红冲单需与原单处于同一期间：确保 create 用的期间与原单一致
        $this->assertSame('2026-10', $red->period);

        $before = $this->queryCount();
        $result = $svc->list(['include_red' => true], 1, 20);
        $queries = $this->queryCount() - $before;

        $this->assertGreaterThanOrEqual(1, $result['total']);
        // 列表：Voucher 分页 + entries + reversal 三条固定查询；不随行数增长
        $this->assertLessThanOrEqual(8, $queries, "列表查询数应恒定（当前 {$queries}，疑似 N+1）");

        // 详情：预载 reversal 后 red_of_voucher_no 直接读内存，不追加查询
        $beforeDetail = $this->queryCount();
        $detail = $svc->detail($red->id);
        $detailQueries = $this->queryCount() - $beforeDetail;
        $this->assertSame($original->voucher_no, $detail['red_of_voucher_no']);
        $this->assertLessThanOrEqual(4, $detailQueries, "详情不应逐条回查红冲单号（当前 {$detailQueries}）");
    }

    /** N+1 回归：资产负债表/利润表批量取科目方向，单次调用查询数恒定 */
    public function test_reports_batch_fetch_subject_direction(): void
    {
        $this->makeVoucher('2026-10');

        $report = app(ReportService::class);
        $before = $this->queryCount();
        $sheet = $report->balanceSheet('2026-10');
        $bsQueries = $this->queryCount() - $before;
        $this->assertTrue($sheet['balanced']);
        $this->assertLessThanOrEqual(12, $bsQueries, "资产负债表查询数应恒定（当前 {$bsQueries}，疑似按科目逐条查库）");

        $before = $this->queryCount();
        $income = $report->incomeStatement('2026-10');
        $isQueries = $this->queryCount() - $before;
        $this->assertLessThanOrEqual(10, $isQueries, "利润表查询数应恒定（当前 {$isQueries}，疑似按科目逐条查库）");
        $this->assertEquals(0, $income['net_profit']);
    }

    /** 索引迁移：5 个高频筛选/外键列均已建索引 */
    public function test_perf_indexes_are_applied(): void
    {
        $this->assertIndex('ledger_vouchers', ['maker_id']);
        $this->assertIndex('ledger_vouchers', ['auditor_id']);
        $this->assertIndex('ledger_voucher_entries', ['aux_id']);
        $this->assertIndex('ledger_periods', ['closed_by']);
        $this->assertIndex('ledger_voucher_templates', ['creator_id']);
    }

    private function assertIndex(string $table, array $columns): void
    {
        $this->assertTrue(
            Schema::hasIndex($table, $columns),
            "表 {$table} 应存在索引 ".implode(',', $columns)
        );
    }

    private function queryCount(): int
    {
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $count;
    }
}
