<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 借还货管理 · 借货单主表 / 明细表 / 客户借货余额表
 *
 * 与车销借还货（VanSales）区分：本模块处理「仓库」维度的借货业务，
 * 单号前缀 JH，状态机 未还/部分还/已还清/已转销售/已取消。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrow_orders', function (Blueprint $table) {
            $table->id();
            $table->string('borrow_no', 40)->unique()->comment('借货单号 JH+yyyymmdd+4位');
            $table->unsignedBigInteger('customer_id')->comment('客户');
            $table->string('customer_name', 120)->nullable();
            $table->string('contact', 60)->nullable()->comment('联系人');
            $table->string('contact_phone', 40)->nullable()->comment('联系电话');
            $table->unsignedBigInteger('warehouse_id')->comment('仓库');
            $table->string('warehouse_name', 120)->nullable();
            $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员');
            $table->string('salesman_name', 60)->nullable();
            $table->date('borrow_date')->nullable()->comment('借货日期');
            $table->date('due_date')->nullable()->comment('应还日期');
            $table->string('borrow_reason', 40)->nullable()->comment('借货原因');
            $table->unsignedInteger('total_kinds')->default(0)->comment('商品种类');
            $table->integer('total_qty')->default(0)->comment('借货总数');
            $table->decimal('total_amount', 18, 2)->default(0)->comment('借货金额');
            $table->integer('returned_qty')->default(0)->comment('已还数量');
            $table->decimal('returned_amount', 18, 2)->default(0)->comment('已还金额');
            $table->string('status', 20)->default('draft')->comment('draft/unreturned/partial/cleared/converted/cancelled');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('creator_name', 60)->nullable();
            $table->text('remark')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('warehouse_id');
            $table->index('salesman_id');
            $table->index('borrow_date');
        });

        Schema::create('borrow_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_code', 60)->nullable();
            $table->string('product_name', 160)->nullable();
            $table->string('spec', 60)->nullable();
            $table->string('unit', 20)->nullable();
            $table->integer('borrow_qty')->default(0)->comment('借货数量');
            $table->integer('returned_qty')->default(0)->comment('已还数量');
            $table->decimal('unit_price', 18, 2)->default(0)->comment('单价');
            $table->decimal('amount', 18, 2)->default(0)->comment('金额');
            $table->text('remark')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });

        Schema::create('customer_borrow_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('qty')->default(0)->comment('借货未还数量');
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_borrow_balances');
        Schema::dropIfExists('borrow_order_items');
        Schema::dropIfExists('borrow_orders');
    }
};
