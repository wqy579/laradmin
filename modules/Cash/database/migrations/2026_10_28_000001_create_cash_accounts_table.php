<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 出纳资金 · 资金账户表
 *
 * 现金 / 银行账号等资金账户，opening_balance 为逐行累计余额的起点
 * （首行余额 = opening_balance + 收入 − 支出，后续行递推）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_account', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->comment('账户名称，如 现金/工商银行');
            $table->string('type', 20)->default('cash')->comment('账户类型: cash/bank');
            $table->string('account_no', 40)->nullable()->comment('银行账号（现金账户为空）');
            $table->decimal('opening_balance', 14, 2)->default(0)->comment('期初余额');
            $table->string('status', 20)->default('enabled')->comment('enabled/disabled');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_account');
    }
};
