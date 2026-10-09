<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段二表结构：车销退货单 + 借货 + 还货 + 换货 + 借货余额。
 *
 * 状态机（均 draft→approved/cancelled，approved 不可逆）：
 *   van_return_orders:   车销退货（客户现场退货回车上库存）
 *   van_borrow_orders:   借货（扣车上库存+增客户借货余额）
 *   van_return_borrow_orders: 还货（增车上库存+减借货余额）
 *   van_exchange_orders: 换货（换入扣车上仓+换出增车上仓+差价结算）
 *
 * 借货余额独立表 van_customer_borrow_balances（customer_id+product_id unique），
 * 与 customers.balance（欠款）解耦，按商品维度计余额。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 车销退货单主表（VXT）
        if (! Schema::hasTable('van_return_orders')) {
            Schema::create('van_return_orders', function (Blueprint $table) {
                $table->id();
                $table->string('return_no', 30)->unique()->comment('退货单号 VXT+Ymd+6位');
                $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员(auth_user.id)');
                $table->string('salesman_name', 50)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->unsignedBigInteger('vehicle_warehouse_id')->nullable()->comment('车上仓ID');
                $table->date('return_date');
                $table->string('return_reason', 50)->nullable()->comment('质量问题/临期/破损/多送/其他');
                $table->integer('total_qty')->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('refund_method', 20)->default('cash')->comment('现金退回/冲抵应收/挂账');
                $table->decimal('refund_amount', 14, 2)->default(0);
                $table->decimal('receivable_offset', 14, 2)->default(0)->comment('冲抵应收金额');
                $table->string('status', 20)->default('draft')->comment('draft/approved/cancelled');
                $table->unsignedBigInteger('visit_log_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('creator_name', 50)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index('return_no');
                $table->index('customer_id');
                $table->index('vehicle_warehouse_id');
                $table->index('status');
            });
        }

        // 车销退货单明细
        if (! Schema::hasTable('van_return_order_items')) {
            Schema::create('van_return_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id');
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200)->nullable();
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->integer('return_qty')->default(0);
                $table->decimal('unit_price', 10, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->unsignedBigInteger('source_sale_order_id')->nullable()->comment('来源销售单ID');
                $table->text('remark')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->index('order_id');
                $table->index('product_id');
            });
        }

        // 借货单主表（VJT）
        if (! Schema::hasTable('van_borrow_orders')) {
            Schema::create('van_borrow_orders', function (Blueprint $table) {
                $table->id();
                $table->string('borrow_no', 30)->unique()->comment('借货单号 VJT+Ymd+6位');
                $table->unsignedBigInteger('salesman_id')->nullable();
                $table->string('salesman_name', 50)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->unsignedBigInteger('vehicle_warehouse_id')->nullable();
                $table->date('borrow_date');
                $table->date('due_date')->nullable()->comment('应还日期');
                $table->integer('total_qty')->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('status', 20)->default('draft')->comment('draft/approved/cancelled');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('creator_name', 50)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index('borrow_no');
                $table->index('customer_id');
                $table->index('status');
            });
        }

        // 借货单明细
        if (! Schema::hasTable('van_borrow_order_items')) {
            Schema::create('van_borrow_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id');
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200)->nullable();
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->integer('borrow_qty')->default(0);
                $table->decimal('unit_price', 10, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->text('remark')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->index('order_id');
                $table->index('product_id');
            });
        }

        // 借货余额表（customer_id + product_id unique）
        if (! Schema::hasTable('van_customer_borrow_balances')) {
            Schema::create('van_customer_borrow_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('product_id');
                $table->integer('qty')->default(0)->comment('借货数量余额');
                $table->timestamps();
                $table->unique(['customer_id', 'product_id']);
                $table->index('customer_id');
            });
        }

        // 还货单主表（VHT）
        if (! Schema::hasTable('van_return_borrow_orders')) {
            Schema::create('van_return_borrow_orders', function (Blueprint $table) {
                $table->id();
                $table->string('return_no', 30)->unique()->comment('还货单号 VHT+Ymd+6位');
                $table->unsignedBigInteger('salesman_id')->nullable();
                $table->string('salesman_name', 50)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->unsignedBigInteger('vehicle_warehouse_id')->nullable();
                $table->unsignedBigInteger('borrow_order_id')->nullable()->comment('关联借货单ID');
                $table->date('return_date');
                $table->integer('total_qty')->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('status', 20)->default('draft')->comment('draft/approved/cancelled');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('creator_name', 50)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index('return_no');
                $table->index('customer_id');
                $table->index('borrow_order_id');
                $table->index('status');
            });
        }

        // 还货单明细
        if (! Schema::hasTable('van_return_borrow_order_items')) {
            Schema::create('van_return_borrow_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id');
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200)->nullable();
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->integer('return_qty')->default(0);
                $table->decimal('unit_price', 10, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->text('remark')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->index('order_id');
                $table->index('product_id');
            });
        }

        // 换货单主表（VHD）
        if (! Schema::hasTable('van_exchange_orders')) {
            Schema::create('van_exchange_orders', function (Blueprint $table) {
                $table->id();
                $table->string('exchange_no', 30)->unique()->comment('换货单号 VHD+Ymd+6位');
                $table->unsignedBigInteger('salesman_id')->nullable();
                $table->string('salesman_name', 50)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->unsignedBigInteger('vehicle_warehouse_id')->nullable();
                $table->date('exchange_date');
                $table->decimal('diff_amount', 14, 2)->default(0)->comment('差价(换入-换出,正数客户补款)');
                $table->string('settle_method', 20)->default('cash')->comment('现金/冲抵应收/挂账');
                $table->string('status', 20)->default('draft')->comment('draft/approved/cancelled');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('creator_name', 50)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index('exchange_no');
                $table->index('customer_id');
                $table->index('status');
            });
        }

        // 换货单明细（换出+换入同一行）
        if (! Schema::hasTable('van_exchange_order_items')) {
            Schema::create('van_exchange_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id_out')->comment('换出商品(客户退回)');
                $table->string('product_name_out', 200)->nullable();
                $table->string('spec_out', 100)->nullable();
                $table->integer('qty')->default(0);
                $table->decimal('unit_price_out', 10, 2)->default(0);
                $table->decimal('amount_out', 14, 2)->default(0);
                $table->unsignedBigInteger('product_id_in')->comment('换入商品(给客户)');
                $table->string('product_name_in', 200)->nullable();
                $table->string('spec_in', 100)->nullable();
                $table->decimal('unit_price_in', 10, 2)->default(0);
                $table->decimal('amount_in', 14, 2)->default(0);
                $table->decimal('diff_amount', 14, 2)->default(0)->comment('行差价');
                $table->text('remark')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->index('order_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('van_exchange_order_items');
        Schema::dropIfExists('van_exchange_orders');
        Schema::dropIfExists('van_return_borrow_order_items');
        Schema::dropIfExists('van_return_borrow_orders');
        Schema::dropIfExists('van_customer_borrow_balances');
        Schema::dropIfExists('van_borrow_order_items');
        Schema::dropIfExists('van_borrow_orders');
        Schema::dropIfExists('van_return_order_items');
        Schema::dropIfExists('van_return_orders');
    }
};
