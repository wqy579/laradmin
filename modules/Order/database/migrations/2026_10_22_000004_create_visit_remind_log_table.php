<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T5.4 业务员拜访提醒：待提醒/已提醒台账。
 *
 * 逾期不落列：status=0 且 remind_date<today 即逾期，由查询时计算，
 * 避免跨天后存量数据的逾期标记与 remind_date 打架。
 * visit_log_id 可空：允许「按计划新建提醒」，尚未产生拜访单也能挂一条提醒。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_remind_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_log_id')->nullable()->comment('拜访记录ID，可为空=按计划新建提醒');
            $table->unsignedBigInteger('employee_id')->comment('业务员ID');
            $table->unsignedBigInteger('customer_id')->comment('客户ID');
            $table->date('remind_date')->comment('应提醒日期');
            $table->string('remind_type', 20)->default('sms')->comment('提醒方式，默认短信');
            $table->tinyInteger('status')->default(0)->comment('状态：0待提醒 1已提醒');
            $table->timestamp('sent_at')->nullable()->comment('提醒发送时间');
            $table->unsignedBigInteger('sent_by')->nullable()->comment('提醒操作人ID');
            $table->string('remark', 500)->nullable()->comment('备注');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人ID');
            $table->timestamps();

            $table->index('visit_log_id');
            $table->index('employee_id');
            $table->index('customer_id');
            // KPI 与列表的主查询路径：按状态+日期过滤/统计
            $table->index(['status', 'remind_date'], 'idx_status_remind_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_remind_log');
    }
};
