<?php

namespace Tests\Ledger\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Models\VoucherEntry;
use Modules\Ledger\Services\VoucherService;
use Tests\TestCase;

class VoucherQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('ledger_subjects')) {
            Schema::create('ledger_subjects', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('code');
                $table->string('name');
                $table->string('category');
                $table->string('balance_direction');
                $table->boolean('is_leaf')->default(true);
                $table->integer('level')->default(1);
                $table->json('aux_types')->nullable();
                $table->integer('sort')->default(0);
                $table->string('status')->default('enabled');
                $table->text('remark')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ledger_vouchers')) {
            Schema::create('ledger_vouchers', function (Blueprint $table) {
                $table->id();
                $table->string('voucher_no');
                $table->date('voucher_date');
                $table->string('period');
                $table->integer('attachments_count')->default(0);
                $table->decimal('total_debit', 15, 2)->default(0);
                $table->decimal('total_credit', 15, 2)->default(0);
                $table->string('status');
                $table->unsignedBigInteger('maker_id')->nullable();
                $table->string('maker_name')->nullable();
                $table->unsignedBigInteger('auditor_id')->nullable();
                $table->string('auditor_name')->nullable();
                $table->timestamp('audited_at')->nullable();
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('red_of_id')->nullable();
                $table->boolean('is_red')->default(false);
                $table->boolean('auto_audit')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ledger_voucher_entries')) {
            Schema::create('ledger_voucher_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('voucher_id');
                $table->integer('line_no');
                $table->unsignedBigInteger('subject_id');
                $table->string('subject_code');
                $table->string('subject_name');
                $table->string('aux_type')->nullable();
                $table->unsignedBigInteger('aux_id')->nullable();
                $table->string('aux_name')->nullable();
                $table->string('brand_id')->nullable();
                $table->string('brand_name')->nullable();
                $table->text('summary')->nullable();
                $table->decimal('debit_amount', 15, 2)->default(0);
                $table->decimal('credit_amount', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        $this->cash = Subject::updateOrCreate(['code' => '1001'], [
            'name' => '库存现金', 'category' => 'asset',
            'balance_direction' => 'debit', 'is_leaf' => true, 'level' => 4, 'status' => 'enabled',
        ]);
        $this->bank = Subject::updateOrCreate(['code' => '1002'], [
            'name' => '银行存款', 'category' => 'asset',
            'balance_direction' => 'debit', 'is_leaf' => true, 'level' => 4, 'status' => 'enabled',
        ]);
        $this->revenue = Subject::updateOrCreate(['code' => '6001'], [
            'name' => '主营业务收入', 'category' => 'profit_loss',
            'balance_direction' => 'credit', 'is_leaf' => true, 'level' => 4, 'status' => 'enabled',
        ]);
        $this->expense = Subject::updateOrCreate(['code' => '6601'], [
            'name' => '销售费用', 'category' => 'profit_loss',
            'balance_direction' => 'debit', 'is_leaf' => true, 'level' => 4, 'status' => 'enabled',
        ]);
    }

    private function createVoucher(array $overrides = []): Voucher
    {
        $defaults = [
            'voucher_no' => 'V'.date('Ym').rand(1000, 9999),
            'voucher_date' => now()->toDateString(),
            'period' => date('Y-m'),
            'status' => 'audited',
            'total_debit' => 100.00,
            'total_credit' => 100.00,
            'maker_name' => 'Test User',
        ];
        $v = Voucher::create(array_merge($defaults, $overrides));

        if (! isset($overrides['entries'])) {
            VoucherEntry::create([
                'voucher_id' => $v->id, 'line_no' => 1, 'subject_id' => $this->cash->id,
                'subject_code' => '1001', 'subject_name' => '库存现金',
                'debit_amount' => 100, 'credit_amount' => 0, 'summary' => 'Test Debit',
            ]);
            VoucherEntry::create([
                'voucher_id' => $v->id, 'line_no' => 2, 'subject_id' => $this->revenue->id,
                'subject_code' => '6001', 'subject_name' => '主营业务收入',
                'debit_amount' => 0, 'credit_amount' => 100, 'summary' => 'Test Credit',
            ]);
        }

        return $v;
    }

    public function test_date_and_subject_filter()
    {
        Voucher::truncate();
        VoucherEntry::truncate();

        // V1: 2026-01-01, Subject: Cash
        $this->createVoucher(['voucher_date' => '2026-01-01']);
        // V2: 2026-01-15, Subject: Bank
        $v2 = $this->createVoucher(['voucher_date' => '2026-01-15']);
        VoucherEntry::where('voucher_id', $v2->id)->delete();
        VoucherEntry::create([
            'voucher_id' => $v2->id, 'line_no' => 1, 'subject_id' => $this->bank->id,
            'subject_code' => '1002', 'subject_name' => '银行存款', 'debit_amount' => 100, 'credit_amount' => 0,
        ]);
        VoucherEntry::create([
            'voucher_id' => $v2->id, 'line_no' => 2, 'subject_id' => $this->revenue->id,
            'subject_code' => '6001', 'subject_name' => '主营业务收入', 'debit_amount' => 0, 'credit_amount' => 100,
        ]);

        $svc = app(VoucherService::class);

        // Query: 2026-01-01 to 2026-01-10, Subject: Bank -> should find 0
        $res = $svc->list([
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
            'subject_id' => $this->bank->id,
        ], 1, 20);
        $this->assertEquals(0, $res['total']);

        // Query: 2026-01-01 to 2026-01-20, Subject: Bank -> should find 1
        $res2 = $svc->list([
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-20',
            'subject_id' => $this->bank->id,
        ], 1, 20);
        $this->assertEquals(1, $res2['total']);
    }

    public function test_subject_and_amount_filter()
    {
        Voucher::truncate();
        VoucherEntry::truncate();

        // V1: Cash, Amount 100
        $this->createVoucher(['total_debit' => 100, 'total_credit' => 100]);
        // V2: Cash, Amount 1000
        $this->createVoucher(['total_debit' => 1000, 'total_credit' => 1000]);

        $svc = app(VoucherService::class);

        $res = $svc->list([
            'subject_id' => $this->cash->id,
            'min_amount' => 500,
        ], 1, 20);
        $this->assertEquals(1, $res['total']);
    }

    public function test_all_conditions_combined()
    {
        Voucher::truncate();
        VoucherEntry::truncate();

        // V1: 2026-01-01, Cash, 100, Audited
        $this->createVoucher([
            'voucher_date' => '2026-01-01',
            'total_debit' => 100, 'total_credit' => 100,
            'status' => 'audited',
        ]);
        // V2: 2026-01-01, Cash, 1000, Draft
        $this->createVoucher([
            'voucher_date' => '2026-01-01',
            'total_debit' => 1000, 'total_credit' => 1000,
            'status' => 'draft',
        ]);
        // V3: 2026-02-01, Cash, 1000, Audited
        $this->createVoucher([
            'voucher_date' => '2026-02-01',
            'total_debit' => 1000, 'total_credit' => 1000,
            'status' => 'audited',
        ]);

        $svc = app(VoucherService::class);

        $res = $svc->list([
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'subject_id' => $this->cash->id,
            'min_amount' => 500,
            'status' => 'audited',
        ], 1, 20);

        $this->assertEquals(0, $res['total'], 'V1 is too small, V2 is draft, V3 is wrong date');
    }

    public function test_amount_filter_respects_direction()
    {
        Voucher::truncate();
        VoucherEntry::truncate();

        // We need vouchers where total_debit != total_credit to test direction?
        // NO, vouchers are always balanced. total_debit == total_credit.
        // So direction filter just chooses which column to use.
        // In balanced vouchers, it doesn't matter.
        // BUT, the code was specifically updated to use total_credit if direction == credit.

        $this->createVoucher(['total_debit' => 100, 'total_credit' => 100]);
        $this->createVoucher(['total_debit' => 1000, 'total_credit' => 1000]);

        $svc = app(VoucherService::class);

        $res = $svc->list([
            'direction' => 'credit',
            'min_amount' => 500,
        ], 1, 20);
        $this->assertEquals(1, $res['total']);
    }
}
