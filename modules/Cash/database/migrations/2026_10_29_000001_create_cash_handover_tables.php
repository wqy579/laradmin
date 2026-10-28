<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收入上交款（D2）：头表 cash_handover + 明细表 cash_handover_item
 *
 * 一张上交款单把业务员手里多个销售订单的收款一次性上交入指定资金账户：
 * 明细「本次上交」合计必须等于头金额（服务端强校验），
 * 确认后按头金额生成一条 income 方向 cash_flow，并回写各订单收款状态。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_handover', function (Blueprint $table) {
            $table->id();
            $table->string('handover_no', 30)->comment('上交款单号，如 SC202610290001');
            $table->date('handover_date')->comment('上交日期');
            $table->unsignedBigInteger('salesman_id')->nullable()->comment('上交人/业务员ID');
            $table->string('salesman_name', 60)->nullable()->comment('上交人/业务员姓名（冗余）');
            $table->unsignedBigInteger('account_id')->comment('收款账户ID(cash_account.id)');
            $table->decimal('amount', 14, 2)->comment('上交金额（= 明细本次上交合计）');
            $table->string('status', 20)->default('pending')->comment('状态: pending待确认/confirmed已确认');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->unsignedBigInteger('confirmed_by')->nullable()->comment('确认人(auth_user.id)');
            $table->timestamp('confirmed_at')->nullable()->comment('确认时间');
            $table->timestamps();

            $table->unique('handover_no');
            $table->index(['handover_date', 'status']);
            $table->index('status');
        });

        Schema::create('cash_handover_item', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('handover_id')->comment('上交款单ID(cash_handover.id)');
            $table->unsignedBigInteger('sales_order_id')->nullable()->comment('销售订单ID(sales_orders.id)');
            $table->string('order_no', 30)->nullable()->comment('订单号（冗余）');
            $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
            $table->string('customer_name', 120)->nullable()->comment('客户名称（冗余）');
            $table->date('order_date')->nullable()->comment('订单日期');
            $table->decimal('order_amount', 14, 2)->default(0)->comment('订单金额');
            $table->decimal('handover_amount', 14, 2)->default(0)->comment('本次上交金额');
            $table->unsignedInteger('sort')->default(0)->comment('排序');
            $table->timestamps();

            $table->index('handover_id');
            $table->index('sales_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_handover_item');
        Schema::dropIfExists('cash_handover');
    }
};
