<?php

namespace Tests\Ledger\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Seeders\LedgerSubjectSeeder;
use Tests\Ledger\Feature\Concerns\CreatesBusinessBills;
use Tests\TestCase;

/**
 * QA-05 补跑命令 `php artisan ledger:generate-vouchers`。
 *
 * 命令是「审核时自动生成失败」与历史存量单据的唯一兜底入口，
 * 必须保证：--dry-run 绝不写库、真实跑只补漏不长重复、非法 --type 明确报错退出
 * （静默忽略会让运维以为跑了其实没跑）。
 */
class GenerateVouchersCommandTest extends TestCase
{
    use CreatesBusinessBills, RefreshDatabase;

    private function seedSubjects(): void
    {
        $seeder = new LedgerSubjectSeeder;
        $seeder->setContainer(app());
        $seeder->callWith([LedgerSubjectSeeder::class]);
    }

    public function test_dry_run_lists_pending_bills_without_writing(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $this->billReceive($customer, 1000, '现金');
        $this->billExpense('办公费', 200);

        $this->artisan('ledger:generate-vouchers', ['--dry-run' => true])
            ->expectsOutputToContain('已审核单据 2 张，其中未生成凭证 2 张')
            ->expectsOutputToContain('[dry-run] 待生成 2 张，未写库')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('ledger_vouchers')->count(), 'dry-run 绝不能写库');
    }

    public function test_real_run_generates_draft_vouchers_once(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $bill = $this->billReceive($customer, 1000, '现金');

        $this->artisan('ledger:generate-vouchers')
            ->expectsOutputToContain('完成：生成 1 张，跳过 0 张，失败 0 张')
            ->assertSuccessful();

        $rows = $this->sourceVouchers('receive', $bill);
        $this->assertCount(1, $rows);
        $this->assertSame('draft', $rows[0]['status'], '批量生成必须先落草稿，由会计复核后审核入账');
        $this->assertSame('系统自动', $rows[0]['maker_name'], '命令行没有登录态，制单人兜底为系统自动');

        // 再跑一次：幂等，不新增
        $this->artisan('ledger:generate-vouchers')
            ->expectsOutputToContain('完成：生成 0 张，跳过 0 张，失败 0 张')
            ->assertSuccessful();
        $this->assertCount(1, $this->sourceVouchers('receive', $bill));
    }

    public function test_type_option_limits_scope(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $receive = $this->billReceive($customer, 1000, '现金');
        $expense = $this->billExpense('办公费', 200);

        $this->artisan('ledger:generate-vouchers', ['--type' => 'receive'])
            ->assertSuccessful();

        $this->assertCount(1, $this->sourceVouchers('receive', $receive));
        $this->assertCount(0, $this->sourceVouchers('expense', $expense), '--type 之外的类型不能被动到');
    }

    public function test_unknown_type_fails_loudly(): void
    {
        $this->seedSubjects();

        $this->artisan('ledger:generate-vouchers', ['--type' => 'deposit'])
            ->expectsOutputToContain('不支持的单据类型：deposit')
            ->assertFailed();

        $this->assertSame(0, DB::table('ledger_vouchers')->count());
    }

    public function test_failing_bill_reports_error_and_exits_failed(): void
    {
        $this->seedSubjects();
        $customer = $this->billCustomer();
        $this->billReceive($customer, 1000, '现金');

        // 科目缺失（未初始化账套）：命令要报出是哪张单据失败，并以非 0 退出，
        // 否则定时任务脚本会以为补跑成功
        DB::table('ledger_subjects')->where('code', '1122')->delete();

        $this->artisan('ledger:generate-vouchers')
            ->expectsOutputToContain('未找到末级科目：1122')
            ->expectsOutputToContain('失败 1 张')
            ->assertFailed();

        $this->assertSame(0, DB::table('ledger_vouchers')->count());
    }
}
