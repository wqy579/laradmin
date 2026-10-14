<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 会计期间表
 *
 * status=open/closed。结账后该期间锁定，凭证不可增删改，期初余额与科目余额固化。
 * 反结账仅允许从最后一个已结账期间逆向操作。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open')->comment('open/closed');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('closed_by_name', 60)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('voucher_count')->default(0);
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->json('check_report')->nullable()->comment('结账检查结果');
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_periods');
    }
};
