<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 积分发放单明细（D 域 · 积分发放 D5）。
 *
 * 会员关联定死 member_id（modules/Member members 表），不用 mock 的 customerId。
 * settled_at 是「已处理标记」：审核事务内对每条明细写 member_consume_logs +
 * 累加 members.points 后置为该明细的入账时间，重复审核时非空即跳过（幂等守卫）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_integral_grant_item', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('grant_id')->comment('主表 cash_integral_grant.id');
            $table->unsignedBigInteger('member_id')->comment('会员 id(members.id)');
            $table->integer('points')->comment('本次发放积分数（正整数）');
            $table->string('remark', 255)->nullable()->comment('摘要');
            $table->timestamp('settled_at')->nullable()->comment('审核入账时间；非空=已写入会员流水，幂等标记');
            $table->timestamps();

            $table->index(['grant_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_integral_grant_item');
    }
};
