<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 积分核销单主表（D 域 · 积分核销 D6）。
 *
 * 单据编号 HX + Ymd + 6 位流水；状态机 0 草稿 → 1 待审核 → 2 已审核，
 * 3 已作废（草稿/待审核可作废，已审核不可逆，冲销走反向单），与 cash_integral_grant 同义。
 *
 * 一张单只核销一个会员，故不设明细表（与核销业务形态一致）。
 * settled_at 是幂等标记：非空即表示已在审核事务内扣减过会员积分，重复审核直接跳过。
 * points_before / points_after 为审核时的余额快照，扣减后回写，便于对账。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_integral_verify', function (Blueprint $table) {
            $table->id();
            $table->string('verify_no', 32)->comment('核销单号 HX+Ymd+6位流水');
            $table->date('verify_date')->comment('核销日期');
            $table->unsignedBigInteger('member_id')->comment('会员ID(members.id)');
            $table->integer('points')->comment('本次核销积分（正整数）');
            $table->integer('points_before')->nullable()->comment('核销前积分余额快照（审核时回写）');
            $table->integer('points_after')->nullable()->comment('核销后积分余额快照（审核时回写）');
            $table->string('verify_type', 20)->default('consume')->comment('类型：consume 消费抵扣 / exchange 兑换 / adjust 调整 / other 其他');
            $table->string('related_order_no', 32)->nullable()->comment('关联单号（如消费订单号）');
            $table->tinyInteger('status')->default(0)->comment('状态：0 草稿 1 待审核 2 已审核 3 已作废');
            $table->timestamp('settled_at')->nullable()->comment('积分扣减完成时间，非空=已处理（幂等标记）');
            $table->string('remark', 255)->nullable()->comment('核销事由/备注');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('制单人(auth_user.id)');
            $table->unsignedBigInteger('audited_by')->nullable()->comment('审核人(auth_user.id)');
            $table->timestamp('audited_at')->nullable()->comment('审核时间');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人(auth_user.id)');
            $table->timestamps();

            $table->unique('verify_no');
            $table->index(['status', 'verify_date']);
            $table->index(['member_id', 'verify_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_integral_verify');
    }
};
