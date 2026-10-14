<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 期初余额表
 *
 * 开账时录入各末级科目的初始余额。subject_id+period 唯一，
 * 已月结期间禁止修改（由 LedgerPeriodService 校验）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subject_id')->comment('末级科目');
            $table->char('period', 7)->comment('会计期间 YYYY-MM');
            $table->decimal('opening_debit', 18, 2)->default(0)->comment('年初余额借方');
            $table->decimal('opening_credit', 18, 2)->default(0)->comment('年初余额贷方');
            $table->decimal('year_debit', 18, 2)->default(0)->comment('本期累计借方（年中开账）');
            $table->decimal('year_credit', 18, 2)->default(0)->comment('本期累计贷方（年中开账）');
            $table->timestamps();

            $table->unique(['subject_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_opening_balances');
    }
};
