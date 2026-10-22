<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('presale_adjust')) {
            Schema::create('presale_adjust', function (Blueprint $table) {
                $table->id();
                $table->string('adjust_no', 30)->unique()->comment('调整单号PADJ+Ymd+6位');
                $table->unsignedBigInteger('sales_order_id')->comment('原销售订单ID');
                $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
                $table->string('status', 20)->default('draft')->comment('状态:draft/confirmed');
                $table->decimal('total_before', 14, 2)->default(0)->comment('调整前合计');
                $table->decimal('total_after', 14, 2)->default(0)->comment('调整后合计');
                $table->json('history')->nullable()->comment('留痕数组[{action,operator,at,detail}]');
                $table->unsignedBigInteger('adjusted_by')->nullable()->comment('调整人ID');
                $table->timestamp('adjusted_at')->nullable()->comment('确认时间');
                $table->string('remark', 500)->nullable()->comment('备注');
                $table->unsignedBigInteger('created_by')->nullable()->comment('创建人ID');
                $table->timestamps();

                $table->index('adjust_no');
                $table->index('sales_order_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('presale_adjust_item')) {
            Schema::create('presale_adjust_item', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('presale_adjust_id')->comment('调整单ID');
                $table->unsignedBigInteger('sales_order_item_id')->comment('原销售订单明细ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->decimal('original_qty', 14, 3)->default(0)->comment('原数量');
                $table->decimal('original_price', 14, 2)->default(0)->comment('原单价');
                $table->decimal('original_amount', 14, 2)->default(0)->comment('原金额');
                $table->decimal('adjusted_qty', 14, 3)->default(0)->comment('调整后数量');
                $table->decimal('adjusted_price', 14, 2)->default(0)->comment('调整后单价');
                $table->decimal('adjusted_amount', 14, 2)->default(0)->comment('调整后金额');
                $table->decimal('diff_amount', 14, 2)->default(0)->comment('差额=adjusted−original（正负号保留）');
                $table->string('remark', 500)->nullable()->comment('行备注');
                $table->timestamps();

                $table->index('presale_adjust_id');
                $table->index('sales_order_item_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('presale_adjust_item');
        Schema::dropIfExists('presale_adjust');
    }
};
