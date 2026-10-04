<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_returns')) {
            Schema::create('sales_returns', function (Blueprint $table) {
                $table->id();
                $table->string('return_no', 30)->unique()->comment('退货单号TH+Ymd+6位');
                $table->unsignedBigInteger('order_id')->nullable()->comment('原销售订单ID');
                $table->string('order_no', 30)->nullable()->comment('原订单号冗余');
                $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
                $table->string('customer_name', 100)->nullable()->comment('客户名冗余');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('退货仓库ID');
                $table->date('return_date')->comment('退货日期');
                $table->string('return_type', 20)->default('quality')->comment('退货类型:quality/cancel/damage/other');
                $table->string('status', 20)->default('draft')->comment('状态:draft/pending/approved/cancelled');
                $table->integer('total_skus')->default(0)->comment('商品种类数');
                $table->integer('total_qty')->default(0)->comment('退货总数量');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('退货总金额');
                $table->decimal('refund_amount', 14, 2)->default(0)->comment('退款金额');
                $table->decimal('receivable_offset', 14, 2)->default(0)->comment('冲减应收金额');
                $table->text('remark')->nullable()->comment('备注');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人ID');
                $table->unsignedBigInteger('approved_by')->nullable()->comment('审核人ID');
                $table->timestamp('approved_at')->nullable()->comment('审核时间');
                $table->text('approval_comment')->nullable()->comment('审核意见');
                $table->timestamps();

                $table->index('return_no');
                $table->index('order_id');
                $table->index('customer_id');
                $table->index('status');
                $table->index('return_date');
            });
        }

        if (! Schema::hasTable('sales_return_items')) {
            Schema::create('sales_return_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('return_id')->comment('退货单ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->string('product_code', 50)->nullable()->comment('商品编码冗余');
                $table->string('product_name', 200)->nullable()->comment('商品名冗余');
                $table->string('spec', 100)->nullable()->comment('规格冗余');
                $table->string('unit', 20)->nullable()->comment('单位冗余');
                $table->integer('order_qty')->default(0)->comment('原订单数量');
                $table->integer('returned_qty')->default(0)->comment('之前已退数量');
                $table->integer('return_qty')->default(0)->comment('本次退货数量');
                $table->decimal('return_price', 10, 2)->default(0)->comment('退货单价');
                $table->decimal('return_amount', 14, 2)->default(0)->comment('退货金额');
                $table->timestamps();

                $table->index('return_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }
};
