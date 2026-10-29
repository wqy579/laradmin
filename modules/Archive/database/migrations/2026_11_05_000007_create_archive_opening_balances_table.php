<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 开账期初余额暂存表（F6 第二批 · 第 3 步）。
 *
 * 字段与 ledger_opening_balances 一一对应（期初借/贷、本年累计借/贷），试算口径
 * 也照抄 Ledger：叶子科目 opening_debit+year_debit 合计 vs opening_credit+year_credit 合计。
 * 同样先暂存、锁定才回写 Ledger 的期初表，避免草稿把总账期初搅乱。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archive_opening_balances')) {
            Schema::create('archive_opening_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('wizard_id')->comment('向导ID');
                $table->unsignedBigInteger('subject_id')->comment('科目ID（ledger_subjects）');
                $table->string('period', 7)->comment('期初期间，如 2026-01');
                $table->decimal('opening_debit', 14, 2)->default(0)->comment('期初借方');
                $table->decimal('opening_credit', 14, 2)->default(0)->comment('期初贷方');
                $table->decimal('year_debit', 14, 2)->default(0)->comment('本年累计借方');
                $table->decimal('year_credit', 14, 2)->default(0)->comment('本年累计贷方');
                $table->timestamps();

                $table->unique(['wizard_id', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_opening_balances');
    }
};
