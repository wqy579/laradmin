<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品组装单：把多种子件商品组合成一个父件商品。
 * 状态机 draft/pending/approved/cancelled，审核后子件出库、父件入库并结转成本。
 * 参照 purchase_returns 表结构。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assembly_orders')) {
            Schema::create('assembly_orders', function (Blueprint $table) {
                $table->id();
                $table->string('assembly_no', 30)->unique()->comment('组装单号ZC+Ymd+6位');
                $table->unsignedBigInteger('parent_product_id')->comment('父件商品ID');
                $table->string('parent_product_name', 200)->nullable()->comment('父件名冗余');
                $table->string('parent_product_code', 50)->nullable()->comment('父件编码冗余');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('仓库ID');
                $table->integer('quantity')->default(1)->comment('组装数量(父件最小单位)');
                $table->decimal('total_cost', 14, 2)->default(0)->comment('子件总成本');
                $table->decimal('unit_cost', 14, 2)->default(0)->comment('父件加权单位成本');
                $table->string('status', 20)->default('draft')->comment('状态:draft/pending/approved/cancelled');
                $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员ID');
                $table->date('assembly_date')->comment('组装日期');
                $table->text('remark')->nullable()->comment('备注');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人ID');
                $table->unsignedBigInteger('approved_by')->nullable()->comment('审核人ID');
                $table->timestamp('approved_at')->nullable()->comment('审核时间');
                $table->text('approval_comment')->nullable()->comment('审核意见');
                $table->timestamps();

                $table->index('assembly_no');
                $table->index('parent_product_id');
                $table->index('warehouse_id');
                $table->index('status');
                $table->index('assembly_date');
            });
        }

        if (! Schema::hasTable('assembly_order_items')) {
            Schema::create('assembly_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assembly_order_id')->comment('组装单ID');
                $table->unsignedBigInteger('product_id')->comment('子件商品ID');
                $table->string('product_code', 50)->nullable()->comment('子件编码冗余');
                $table->string('product_name', 200)->nullable()->comment('子件名冗余');
                $table->string('spec', 100)->nullable()->comment('规格冗余');
                $table->string('unit', 20)->nullable()->comment('单位冗余');
                $table->integer('unit_usage')->default(1)->comment('单位用量(组装一件父件需多少子件)');
                $table->integer('total_usage')->default(0)->comment('总用量=unit_usage×quantity');
                $table->decimal('unit_cost', 10, 2)->default(0)->comment('子件单位成本');
                $table->decimal('total_cost', 14, 2)->default(0)->comment('子件总成本=total_usage×unit_cost');
                $table->timestamps();

                $table->index('assembly_order_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_order_items');
        Schema::dropIfExists('assembly_orders');
    }
};
