<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 出纳资金 · 账户收支流水表（D-29 现金账户收支查询）
 *
 * 注意：与 Order 模块的 cash_flows（收付款资金流水）无关，
 * 本表面向「资金账户」维度：每笔收入/支出挂在一个 cash_account 上，
 * 余额由查询接口按（账户，日期，单据号，id）顺序逐行累计返回。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_flow', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->comment('资金账户ID(cash_account.id)');
            $table->string('flow_no', 50)->comment('单据号，如 SC202600001');
            $table->date('flow_date')->comment('业务日期');
            $table->string('direction', 10)->comment('收支方向: income/expense');
            $table->string('summary', 255)->nullable()->comment('摘要');
            $table->decimal('amount', 14, 2)->comment('金额（恒为正，方向看 direction）');
            $table->string('related_type', 50)->nullable()->comment('关联单据类型');
            $table->unsignedBigInteger('related_id')->nullable()->comment('关联单据ID');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人(auth_user.id)');
            $table->timestamps();

            $table->index(['account_id', 'flow_date']);
            $table->index('direction');
            $table->unique(['account_id', 'flow_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flow');
    }
};
