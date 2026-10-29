<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 积分规则表（D 域 · 积分规则 D5）。
 *
 * 多行规则列表形态（一条规则一行），对齐 frontend 原 mock 的规则列表；
 * modules/Member 的 point_rules 是 key-value 配置形态，二者互不替代。
 * ratio = 1 元消费可获多少积分（1:ratio 口径中的换算系数）。
 * D6 核销预留字段（deduct_ratio/deduct_threshold/deduct_limit）本批不写不读。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_integral_rule', function (Blueprint $table) {
            $table->id();
            $table->string('rule_name', 50)->comment('规则名称，唯一');
            $table->decimal('ratio', 12, 4)->default(1)->comment('积分比例：1 元 = ratio 积分');
            $table->date('effective_date')->comment('生效日期');
            $table->tinyInteger('status')->default(1)->comment('状态：0 停用 1 启用');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人(auth_user.id)');
            $table->timestamps();

            $table->unique('rule_name');
            $table->index(['status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_integral_rule');
    }
};
