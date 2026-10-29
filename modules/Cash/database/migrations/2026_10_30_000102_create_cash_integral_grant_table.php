<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 积分发放单主表（D 域 · 积分发放 D5）。
 *
 * 单据编号 JF + Ymd + 6 位流水；状态机 0 草稿 → 1 待审核 → 2 已审核，
 * 3 已作废（草稿/待审核可作废，已审核不可逆）。
 * audited_by / audited_at 记录审核动作，member 积分实际写入发生在审核事务内。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_integral_grant', function (Blueprint $table) {
            $table->id();
            $table->string('grant_no', 32)->comment('发放单号 JF-yyyymm-####，按月连续不跳号（D7 编号正式化）');
            $table->date('grant_date')->comment('发放日期');
            $table->string('grant_type', 20)->default('daily')->comment('类型：daily 日常 / activity 活动 / compensate 补偿 / other 其他');
            $table->unsignedBigInteger('total_points')->default(0)->comment('积分合计 = 明细之和');
            $table->tinyInteger('status')->default(0)->comment('状态：0 草稿 1 待审核 2 已审核 3 已作废');
            $table->string('remark', 255)->nullable()->comment('事由/备注');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('制单人(auth_user.id)');
            $table->unsignedBigInteger('audited_by')->nullable()->comment('审核人(auth_user.id)');
            $table->timestamp('audited_at')->nullable()->comment('审核时间');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人(auth_user.id)');
            $table->timestamps();

            $table->unique('grant_no');
            $table->index(['status', 'grant_date']);
            $table->index('grant_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_integral_grant');
    }
};
