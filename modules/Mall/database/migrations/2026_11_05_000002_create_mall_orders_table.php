<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5 分销商城 —— 商城订单主表（mall_orders）。
 *
 * 下单即生成订货申报单（sales_orders 一条 + sales_order_items 明细），
 * 本表保存商城侧的订单快照与申报单关联，保证「商城订单 → 申报单」可回溯。
 *
 * 状态机：pending（待接单）→ accepted（已接单，申报单已确认）/
 *          rejected（已拒绝）/ cancelled（已取消）。
 * 接单动作即确认申报单（status 置 pending/approved，对齐下单即申报的语义）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mall_orders')) {
            Schema::create('mall_orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_no', 30)->unique()->comment('商城订单号 MALL+Ymd+6位');
                $table->unsignedBigInteger('customer_id')->comment('分销商客户ID(customers.id)');
                $table->string('customer_name', 100)->nullable()->comment('客户名快照');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('提货/配送仓库ID');
                $table->date('order_date')->comment('下单日期');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('订单总金额');
                $table->integer('total_qty')->default(0)->comment('订单总数量(折算小单位)');
                $table->decimal('paid_amount', 14, 2)->default(0)->comment('已付金额');
                $table->string('status', 20)->default('pending')->comment('pending待接单 accepted已接单 rejected已拒绝 cancelled已取消');
                $table->string('receiver_name', 50)->nullable()->comment('收货人');
                $table->string('receiver_phone', 30)->nullable()->comment('收货电话');
                $table->string('receiver_address', 255)->nullable()->comment('收货地址');
                $table->unsignedBigInteger('sales_order_id')->nullable()->comment('生成的订货申报单ID(sales_orders.id)');
                $table->string('sales_order_no', 30)->nullable()->comment('申报单号快照');
                $table->unsignedBigInteger('created_by')->nullable()->comment('下单操作人(auth_user.id)');
                $table->string('creator_name', 50)->nullable()->comment('下单人姓名快照');
                $table->unsignedBigInteger('accepted_by')->nullable()->comment('接单操作人');
                $table->timestamp('accepted_at')->nullable()->comment('接单时间');
                $table->text('accept_remark')->nullable()->comment('接单/拒绝备注');
                $table->timestamps();

                $table->index('customer_id');
                $table->index('status');
                $table->index('order_date');
                $table->index('sales_order_id');
            });
        }

        if (! Schema::hasTable('mall_order_items')) {
            Schema::create('mall_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->comment('商城订单ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID(products.id)');
                $table->string('product_code', 50)->nullable()->comment('商品编码快照');
                $table->string('product_name', 200)->nullable()->comment('商品名称快照');
                $table->string('spec', 100)->nullable()->comment('规格快照');
                $table->string('unit_large', 20)->nullable()->comment('大单位名快照');
                $table->string('unit_medium', 20)->nullable()->comment('中单位名快照');
                $table->string('unit_small', 20)->nullable()->comment('小单位名快照');
                $table->integer('unit_conversion')->default(0)->comment('大→小倍数快照');
                $table->integer('unit_conversion_medium')->default(0)->comment('中→小倍数快照');
                $table->integer('qty_large')->default(0)->comment('大单位数量');
                $table->integer('qty_medium')->default(0)->comment('中单位数量');
                $table->integer('qty_small')->default(0)->comment('小单位数量');
                $table->decimal('price_large', 10, 2)->default(0)->comment('大单位单价');
                $table->decimal('price_medium', 10, 2)->default(0)->comment('中单位单价');
                $table->decimal('price_small', 10, 2)->default(0)->comment('小单位单价');
                $table->integer('quantity')->default(0)->comment('折算小单位总量');
                $table->decimal('amount', 14, 2)->default(0)->comment('金额=三档数量×单价之和');
                $table->timestamps();

                $table->index('order_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mall_order_items');
        Schema::dropIfExists('mall_orders');
    }
};
