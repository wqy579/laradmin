<?php

namespace Tests\Ledger\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Models\VoucherEntry;
use Tests\TestCase;

class VoucherExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('auth_permission')) {
            Schema::create('auth_permission', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('module');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('auth_role_permission')) {
            Schema::create('auth_role_permission', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->timestamps();
                $table->primary(['role_id', 'permission_id']);
            });
        }

        if (! Schema::hasTable('auth_user')) {
            Schema::create('auth_user', function (Blueprint $table) {
                $table->id();
                $table->string('username')->unique();
                $table->string('password');
                $table->string('real_name')->nullable();
                $table->integer('status')->default(1);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('auth_role')) {
            Schema::create('auth_role', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->integer('sort')->default(0);
                $table->integer('status')->default(1);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('auth_user_role')) {
            Schema::create('auth_user_role', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('role_id');
                $table->timestamps();
                $table->primary(['user_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('ledger_subjects')) {
            Schema::create('ledger_subjects', function (Blueprint $table) {
                $table->id();
                $table->string('code');
                $table->string('name');
                $table->string('category');
                $table->string('balance_direction');
                $table->boolean('is_leaf')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ledger_vouchers')) {
            Schema::create('ledger_vouchers', function (Blueprint $table) {
                $table->id();
                $table->string('voucher_no');
                $table->date('voucher_date');
                $table->string('period');
                $table->decimal('total_debit', 15, 2)->default(0);
                $table->decimal('total_credit', 15, 2)->default(0);
                $table->string('status');
                $table->string('maker_name')->nullable();
                $table->string('auditor_name')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ledger_voucher_entries')) {
            Schema::create('ledger_voucher_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('voucher_id');
                $table->unsignedBigInteger('subject_id');
                $table->decimal('debit_amount', 15, 2)->default(0);
                $table->decimal('credit_amount', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        $this->cash = Subject::updateOrCreate(['code' => '1001'], [
            'name' => '库存现金', 'category' => 'asset', 'balance_direction' => 'debit',
        ]);
    }

    private function createVoucher(array $overrides = []): Voucher
    {
        $defaults = [
            'voucher_no' => 'V'.rand(1000, 9999),
            'voucher_date' => now()->toDateString(),
            'period' => date('Y-m'),
            'status' => 'audited',
            'total_debit' => 100.00,
            'total_credit' => 100.00,
        ];
        $v = Voucher::create(array_merge($defaults, $overrides));
        VoucherEntry::create([
            'voucher_id' => $v->id, 'subject_id' => $this->cash->id, 'debit_amount' => 100, 'credit_amount' => 0,
        ]);

        return $v;
    }

    public function test_export_ignores_amount_filters()
    {
        Voucher::truncate();
        VoucherEntry::truncate();

        // Small voucher
        $this->createVoucher(['total_debit' => 100, 'total_credit' => 100]);
        // Large voucher
        $this->createVoucher(['total_debit' => 1000, 'total_credit' => 1000]);

        // Authenticate as admin using the TestCase helper
        $this->actingAsAdmin();

        // Export with min_amount = 500. Should only contain 1 voucher.
        $response = $this->get('/admin/ledger/vouchers/export?min_amount=500');

        $response->assertStatus(200);
        $content = $response->getContent();

        $lines = explode("\n", trim($content));
        $this->assertEquals(2, count($lines), 'Export should filter by amount but currently ignores it. Got '.count($lines).' lines.');
    }
}
