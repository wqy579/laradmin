<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 换货管理 · 换货单主表 / 明细表
 *
 * 客户用 A 商品换 B 商品，差价多退少补。单号前缀 HH。
 * 明细每行同时记录换出（客户退回）与换入（给客户）商品及单价。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_orders', function (Blueprint $table) {
            $table->id();
            $table->string('exchange_no', 40)->unique()->comment('换货单号 HH+yyyymmdd+4位');
            $table->unsignedBigInteger('customer_id');
            $table->string('customer_name', 120)->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('warehouse_name', 120)->nullable();
            $table->unsignedBigInteger('salesman_id')->nullable();
            $table->string('salesman_name', 60)->nullable();
            $table->date('exchange_date')->nullable()->comment('换货日期');
            $table->string('exchange_reason', 40)->comment('换货原因');
            $table->unsignedInteger('total_kinds_out')->default(0)->comment('换出种类');
            $table->unsignedInteger('total_kinds_in')->default(0)->comment('换入种类');
            $table->integer('total_qty_out')->default(0);
            $table->integer('total_qty_in')->default(0);
            $table->decimal('amount_out', 18, 2)->default(0)->comment('换出金额');
            $table->decimal('amount_in', 18, 2)->default(0)->comment('换入金额');
            $table->decimal('diff_amount', 18, 2)->default(0)->comment('差价(换入-换出)');
            $table->string('payment_method', 20)->nullable()->comment('收款方式: cash/wechat/alipay/bank/credit');
            $table->string('refund_method', 20)->nullable()->comment('退款方式: cash_return/offset');
            $table->string('status', 20)->default('draft')->comment('draft/pending/approved/cancelled');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('creator_name', 60)->nullable();
            $table->text('remark')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('warehouse_id');
            $table->index('salesman_id');
            $table->index('exchange_date');
        });

        Schema::create('exchange_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id_out');
            $table->string('product_name_out', 160)->nullable();
            $table->string('spec_out', 60)->nullable();
            $table->string('unit_out', 20)->nullable();
            $table->integer('qty')->default(0);
            $table->decimal('unit_price_out', 18, 2)->default(0);
            $table->decimal('amount_out', 18, 2)->default(0);
            $table->unsignedBigInteger('product_id_in');
            $table->string('product_name_in', 160)->nullable();
            $table->string('spec_in', 60)->nullable();
            $table->string('unit_in', 20)->nullable();
            $table->decimal('unit_price_in', 18, 2)->default(0);
            $table->decimal('amount_in', 18, 2)->default(0);
            $table->decimal('diff_amount', 18, 2)->default(0);
            $table->text('remark')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id_out');
            $table->index('product_id_in');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_order_items');
        Schema::dropIfExists('exchange_orders');
    }
};
