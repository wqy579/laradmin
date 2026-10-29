<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 出纳资金 · 刷卡收款记录表（P0-10）
 *
 * card_no 存完整卡号，列表输出一律脱敏（中间 8 位 ****）。
 * 仅成功且当日（txn_time 为今天）的记录允许退款；退款后
 * status=refund、金额与手续费取反，refunded_by/refunded_at 记录操作痕迹。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_card_txn', function (Blueprint $table) {
            $table->id();
            $table->string('serial_no', 50)->unique()->comment('刷卡流水号，如 SX20260001');
            $table->dateTime('txn_time')->comment('刷卡日期时间');
            $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
            $table->string('customer_name', 120)->nullable()->comment('客户名称快照');
            $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员ID');
            $table->string('salesman_name', 60)->nullable()->comment('业务员名称快照');
            $table->string('card_type', 20)->default('debit')->comment('卡类型: debit/credit');
            $table->string('card_no', 32)->comment('完整卡号（输出脱敏）');
            $table->string('card_last4', 4)->comment('卡号后四位（查询辅助）');
            $table->decimal('amount', 14, 2)->comment('金额（正收负退）');
            $table->decimal('fee', 14, 2)->default(0)->comment('手续费');
            $table->string('status', 20)->default('success')->comment('success/fail/refund');
            $table->string('rel_no', 50)->nullable()->comment('关联收款单号');
            $table->unsignedBigInteger('refunded_by')->nullable()->comment('退款操作人(auth_user.id)');
            $table->timestamp('refunded_at')->nullable()->comment('退款时间');
            $table->timestamps();

            $table->index('txn_time');
            $table->index('status');
            $table->index('card_last4');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_card_txn');
    }
};
