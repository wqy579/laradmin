<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 配货单表 + 配货单明细表。
 *
 * 配货单由文员从「已审核」销售订单创建，确认配货后冻结库存并自动生成拣货单。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_picking')) {
            Schema::create('delivery_picking', function (Blueprint $table) {
                $table->id();
                $table->string('picking_no', 30)->unique()->comment('配货单号 PH+Ymd+6位');
                $table->unsignedBigInteger('sales_order_id')->comment('销售订单ID');
                $table->string('order_no', 30)->comment('订单号快照');
                $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
                $table->string('customer_name', 200)->nullable()->comment('客户名快照');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('仓库ID');
                $table->date('picking_date')->comment('配货日期');
                $table->integer('total_skus')->default(0)->comment('商品种类');
                $table->integer('total_qty')->default(0)->comment('商品总数(折算最小单位)');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('金额');
                $table->string('status', 20)->default('pending')->comment('状态: pending待配货/picked已配货/cancelled已取消');
                $table->boolean('stock_frozen')->default(false)->comment('是否已冻结库存(去重标记)');
                $table->boolean('frozen_from_order')->default(false)->comment('冻结量是否源自订单原冻结(非配货新增)');
                $table->unsignedBigInteger('pick_id')->nullable()->comment('生成的拣货单ID');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人(auth_user.id)');
                $table->string('creator_name', 50)->nullable()->comment('制单人姓名快照');
                $table->timestamp('confirmed_at')->nullable()->comment('确认配货时间');
                $table->timestamps();

                $table->index('picking_no');
                $table->index('sales_order_id');
                $table->index('customer_id');
                $table->index('status');
                $table->index('picking_date');
            });
        }

        if (! Schema::hasTable('delivery_picking_items')) {
            Schema::create('delivery_picking_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('picking_id')->comment('配货单ID');
                $table->unsignedBigInteger('sales_order_item_id')->nullable()->comment('订单明细ID');
                $table->unsignedBigInteger('product_id')->nullable()->comment('商品ID');
                $table->string('product_code', 50)->nullable()->comment('商品编码快照');
                $table->string('product_name', 200)->nullable()->comment('商品名快照');
                $table->string('spec', 100)->nullable()->comment('规格快照');
                $table->string('unit', 20)->nullable()->comment('单位快照');
                $table->integer('order_qty')->default(0)->comment('订单数量');
                $table->integer('qty_large')->default(0)->comment('大码数量');
                $table->integer('qty_medium')->default(0)->comment('中码数量');
                $table->integer('qty_small')->default(0)->comment('小码数量');
                $table->integer('quantity')->default(0)->comment('折算最小单位数量');
                $table->decimal('price', 10, 2)->default(0)->comment('单价');
                $table->decimal('amount', 14, 2)->default(0)->comment('金额');
                $table->integer('stock_qty')->default(0)->comment('库存数量(可用,展示用)');
                $table->text('remark')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();

                $table->index('picking_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_picking_items');
        Schema::dropIfExists('delivery_picking');
    }
};
