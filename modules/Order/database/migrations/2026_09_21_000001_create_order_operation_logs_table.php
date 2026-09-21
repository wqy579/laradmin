<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单操作日志表：对齐旧系统 laravel/MpController::logOperation 写入的 order_operation_logs。
 *
 * 新系统已有请求级 system_log（LogRequestMiddleware），但那是 HTTP 访问日志，
 * 无法按单据回溯「创建→配货→送达→收款」的业务流转历史。本表补业务级单据操作流水，
 * 字段与旧系统一致，order_type 预留多单据类型（sales_order / purchase_order / return_order）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_operation_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->comment('订单ID');
            $table->string('order_no', 64)->comment('订单号');
            $table->string('order_type', 30)->default('sales_order')->comment('单据类型');
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户ID');
            $table->string('user_name', 100)->nullable()->comment('用户名');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人ID');
            $table->string('operator_name', 100)->nullable()->comment('操作人姓名');
            $table->string('action', 50)->comment('操作动作');
            $table->string('action_label', 50)->nullable()->comment('动作标签');
            $table->string('detail', 500)->nullable()->comment('操作详情');
            $table->string('remark', 500)->nullable()->comment('备注');
            $table->string('from_status', 20)->nullable()->comment('原状态');
            $table->string('to_status', 20)->nullable()->comment('目标状态');
            $table->timestamps();

            $table->index(['order_id', 'order_type']);
            $table->index('order_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_operation_logs');
    }
};
