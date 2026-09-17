<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('receives')) {
            Schema::create('receives', function (Blueprint $table) {
                $table->id();
                $table->string('receive_no', 50)->unique()->comment('收款单号');
                $table->tinyInteger('receive_type')->default(1)->comment('类型:1-销售收款 2-其他收款');
                $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
                $table->unsignedBigInteger('sales_order_id')->nullable()->comment('销售订单ID');
                $table->decimal('amount', 10, 2)->comment('收款金额');
                $table->date('receive_date')->comment('收款日期');
                $table->string('payment_method', 50)->default('现金')->comment('支付方式');
                $table->unsignedBigInteger('handler_id')->nullable()->comment('经手人ID');
                $table->text('remark')->nullable()->comment('备注');
                $table->tinyInteger('status')->default(0)->comment('状态:0-草稿 1-已审核');
                $table->timestamps();

                $table->index('receive_no');
                $table->index('customer_id');
                $table->index('sales_order_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('pays')) {
            Schema::create('pays', function (Blueprint $table) {
                $table->id();
                $table->string('pay_no', 50)->unique()->comment('付款单号');
                $table->tinyInteger('pay_type')->default(1)->comment('类型:1-采购付款 2-其他付款');
                $table->unsignedBigInteger('supplier_id')->nullable()->comment('供应商ID');
                $table->unsignedBigInteger('purchase_order_id')->nullable()->comment('采购订单ID');
                $table->decimal('amount', 10, 2)->comment('付款金额');
                $table->date('pay_date')->comment('付款日期');
                $table->string('payment_method', 50)->default('现金')->comment('支付方式');
                $table->unsignedBigInteger('handler_id')->nullable()->comment('经手人ID');
                $table->text('remark')->nullable()->comment('备注');
                $table->tinyInteger('status')->default(0)->comment('状态:0-草稿 1-已审核');
                $table->timestamps();

                $table->index('pay_no');
                $table->index('supplier_id');
                $table->index('purchase_order_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->string('expense_no', 50)->unique()->comment('费用单号');
                $table->string('expense_type', 50)->comment('费用类型');
                $table->decimal('amount', 10, 2)->comment('费用金额');
                $table->date('expense_date')->comment('费用日期');
                $table->unsignedBigInteger('handler_id')->nullable()->comment('经手人ID');
                $table->unsignedBigInteger('department_id')->nullable()->comment('部门ID');
                $table->text('remark')->nullable()->comment('备注');
                $table->tinyInteger('status')->default(0)->comment('状态:0-草稿 1-已审核');
                $table->timestamps();

                $table->index('expense_no');
                $table->index('expense_type');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('pays');
        Schema::dropIfExists('receives');
    }
};
