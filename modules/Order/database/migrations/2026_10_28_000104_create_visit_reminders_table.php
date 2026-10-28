<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T5.4 业务员拜访提醒 —— visit_reminders 表。
 *
 * 提醒发送后状态变为「已提醒」（status=sent），全程留痕（order_operation_logs）。
 * 状态机：pending（待提醒）→ sent（已提醒）；pending 可作废为 cancelled。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('remind_no', 30)->unique()->comment('提醒编号');
            $table->foreignId('employee_id')->constrained('auth_user')->restrictOnDelete()->comment('业务员');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete()->comment('拜访客户');
            $table->date('visit_date')->comment('计划拜访日期');
            $table->string('content', 500)->nullable()->comment('提醒内容');
            $table->string('status', 20)->default('pending')->comment('pending=待提醒 sent=已提醒');
            $table->timestamp('sent_at')->nullable()->comment('发送时间');
            $table->foreignId('sent_by')->nullable()->constrained('auth_user')->nullOnDelete()->comment('发送人');
            $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'visit_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_reminders');
    }
};
