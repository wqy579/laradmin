<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 客户协议（含陈列协议）。
 *
 * 三态：active 生效中 / expired 已到期 / terminated 已终止。
 * 已到期不落库依赖人工维护——由服务层按 end_date 与今天比对惰性刷新，
 * 保证查询页与统计页的状态与日期始终一致。
 * 兑现率 = 已兑现 agreed_amount / 协议额 amount，服务层计算，不落库存比率。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agr_agreements')) {
            Schema::create('agr_agreements', function (Blueprint $table) {
                $table->id();
                $table->string('agreement_no', 50)->unique()->comment('协议编号 XY+日期+序号');
                $table->string('title', 100)->comment('协议名称');
                $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
                $table->string('customer_name', 100)->comment('客户名称快照');
                $table->string('type', 20)->default('display')->comment('类型 display陈列/general一般');
                $table->date('start_date')->comment('生效日期');
                $table->date('end_date')->comment('到期日期');
                $table->decimal('amount', 14, 2)->default(0)->comment('协议额');
                $table->decimal('redeemed_amount', 14, 2)->default(0)->comment('已兑现额');
                $table->string('status', 20)->default('active')->comment('active生效中/expired已到期/terminated已终止');
                $table->date('terminated_date')->nullable()->comment('终止日期');
                $table->string('maker_name', 50)->nullable()->comment('制单人');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('customer_id');
                $table->index('status');
                $table->index(['start_date', 'end_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agr_agreements');
    }
};
