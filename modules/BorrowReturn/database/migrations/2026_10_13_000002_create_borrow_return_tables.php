<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 借还货管理 · 还货单主表 / 明细表
 *
 * 还货单关联借货单（仅未还/部分还可还），审核通过后完好数量回库、破损数量生成报损单。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrow_return_orders', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 40)->unique()->comment('还货单号 HH+yyyymmdd+4位');
            $table->unsignedBigInteger('borrow_order_id')->nullable()->comment('关联借货单');
            $table->unsignedBigInteger('customer_id');
            $table->string('customer_name', 120)->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('warehouse_name', 120)->nullable();
            $table->unsignedBigInteger('salesman_id')->nullable();
            $table->string('salesman_name', 60)->nullable();
            $table->date('return_date')->nullable()->comment('还货日期');
            $table->string('return_reason', 40)->nullable()->comment('还货原因');
            $table->unsignedInteger('total_kinds')->default(0);
            $table->integer('total_qty')->default(0)->comment('还货总数(完好+破损)');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->integer('good_qty')->default(0)->comment('完好数量');
            $table->integer('bad_qty')->default(0)->comment('破损数量');
            $table->string('status', 20)->default('pending')->comment('pending/approved/cancelled');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('creator_name', 60)->nullable();
            $table->text('remark')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['borrow_order_id', 'status']);
            $table->index('customer_id');
            $table->index('warehouse_id');
            $table->index('salesman_id');
            $table->index('return_date');
        });

        Schema::create('borrow_return_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('borrow_order_item_id')->nullable()->comment('来源借货明细');
            $table->unsignedBigInteger('product_id');
            $table->string('product_code', 60)->nullable();
            $table->string('product_name', 160)->nullable();
            $table->string('spec', 60)->nullable();
            $table->string('unit', 20)->nullable();
            $table->integer('unreturned_qty')->default(0)->comment('快照未还数量');
            $table->integer('return_qty')->default(0)->comment('本次还货(完好+破损)');
            $table->integer('good_qty')->default(0)->comment('完好数量');
            $table->integer('bad_qty')->default(0)->comment('破损数量');
            $table->text('bad_reason')->nullable()->comment('破损说明');
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('remark')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrow_return_order_items');
        Schema::dropIfExists('borrow_return_orders');
    }
};
