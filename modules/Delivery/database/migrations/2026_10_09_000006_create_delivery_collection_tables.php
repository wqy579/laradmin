<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 配送收款表。对已送达配送任务收款，支持部分收款。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_collection')) {
            Schema::create('delivery_collection', function (Blueprint $table) {
                $table->id();
                $table->string('collection_no', 30)->unique()->comment('收款单号 CR+Ymd+6位');
                $table->unsignedBigInteger('task_id')->comment('配送任务ID');
                $table->string('task_no', 30)->comment('任务单号快照');
                $table->unsignedBigInteger('sales_order_id')->nullable();
                $table->string('order_no', 30)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->unsignedBigInteger('delivery_person_id')->nullable()->comment('配送员(auth_user.id)');
                $table->string('delivery_person_name', 50)->nullable();
                $table->unsignedBigInteger('employee_id')->nullable()->comment('员工档案ID(冗余)');
                $table->decimal('receivable_amount', 14, 2)->default(0)->comment('应收金额');
                $table->decimal('received_amount', 14, 2)->default(0)->comment('本次收款金额');
                $table->string('payment_method', 20)->comment('现金/微信/支付宝/银行卡/挂账');
                $table->date('collect_date')->comment('收款日期');
                $table->string('status', 20)->default('pending')->comment('pending待收款/partial部分收款/paid已收款');
                $table->text('remark')->nullable();
                $table->timestamps();

                $table->index('collection_no');
                $table->index('task_id');
                $table->index('delivery_person_id');
                $table->index('customer_id');
                $table->index('status');
                $table->index('collect_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_collection');
    }
};
