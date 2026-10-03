<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_flows', function (Blueprint $table) {
            $table->id();
            $table->string('flow_no', 50)->unique()->comment('流水编号');
            $table->string('flow_type', 20)->comment('类型: receive/pay/expense/red_flush');
            $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
            $table->unsignedBigInteger('supplier_id')->nullable()->comment('供应商ID');
            $table->unsignedBigInteger('related_id')->nullable()->comment('关联单据ID');
            $table->string('related_type', 50)->nullable()->comment('关联单据类型');
            $table->date('flow_date')->comment('流水日期');
            $table->decimal('amount', 14, 2)->comment('金额（正为收入，负为支出）');
            $table->string('payment_method', 50)->default('现金')->comment('支付方式');
            $table->text('remark')->nullable()->comment('备注');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人');
            $table->timestamps();

            $table->index('flow_type');
            $table->index('customer_id');
            $table->index('flow_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flows');
    }
};
