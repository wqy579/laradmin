<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 记账凭证主表 + 分录表
 *
 * 凭证主表：有借必有贷、借贷必相等。status=draft/submitted/audited/reversed。
 * 作废（reversed）的凭证保留编号不回收，显示作废红章。
 * voucher_no 在同一会计期间内连续不断号。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_no', 40)->unique()->comment('凭证字号 记-年-月-4位流水');
            $table->date('voucher_date')->comment('凭证日期');
            $table->char('period', 7)->comment('所属期间 YYYY-MM');
            $table->unsignedInteger('attachments_count')->default(0)->comment('附单据数');
            $table->decimal('total_debit', 18, 2)->default(0)->comment('借方合计');
            $table->decimal('total_credit', 18, 2)->default(0)->comment('贷方合计');
            $table->string('status', 20)->default('draft')->comment('draft/submitted/audited/reversed');
            $table->unsignedBigInteger('maker_id')->nullable()->comment('制单人');
            $table->string('maker_name', 60)->nullable();
            $table->unsignedBigInteger('auditor_id')->nullable()->comment('审核人');
            $table->string('auditor_name', 60)->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index('period');
            $table->index('status');
            $table->index('voucher_date');
        });

        Schema::create('ledger_voucher_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('voucher_id')->index();
            $table->unsignedInteger('line_no')->default(1)->comment('行号');
            $table->unsignedBigInteger('subject_id')->comment('末级科目');
            $table->string('subject_code', 40)->nullable()->comment('冗余科目编码便于查询');
            $table->string('subject_name', 80)->nullable()->comment('冗余科目名称');
            $table->string('aux_type', 30)->nullable()->comment('辅助核算类型 customer/supplier/employee/department/project');
            $table->unsignedBigInteger('aux_id')->nullable()->comment('辅助核算对象 id');
            $table->string('aux_name', 120)->nullable()->comment('冗余辅助核算对象名称');
            $table->string('summary', 200)->nullable()->comment('摘要');
            $table->decimal('debit_amount', 18, 2)->default(0)->comment('借方金额');
            $table->decimal('credit_amount', 18, 2)->default(0)->comment('贷方金额');
            $table->timestamps();

            $table->index('subject_id');
            $table->index(['voucher_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_voucher_entries');
        Schema::dropIfExists('ledger_vouchers');
    }
};
