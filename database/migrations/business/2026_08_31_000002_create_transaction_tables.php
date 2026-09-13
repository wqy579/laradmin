<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 库存
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->integer('quantity')->default(0)->comment('库存数量');
            $table->integer('frozen_qty')->default(0)->comment('冻结数量');
            $table->decimal('cost_price', 10, 2)->default(0)->comment('成本价');
            $table->decimal('total_amount', 14, 2)->default(0)->comment('库存金额');
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });

        // 库存历史
        Schema::create('stocks_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('change_type', 20)->comment('变动类型');
            $table->integer('change_qty')->default(0)->comment('变动数量');
            $table->integer('before_qty')->default(0)->comment('变动前数量');
            $table->integer('after_qty')->default(0)->comment('变动后数量');
            $table->unsignedBigInteger('related_id')->nullable()->comment('关联ID');
            $table->string('related_type', 50)->nullable()->comment('关联类型');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'warehouse_id']);
        });

        // 销售订单
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique()->comment('订单号');
            $table->string('order_type', 20)->default('normal')->comment('订单类型');
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->date('order_date')->comment('订单日期');
            $table->decimal('total_amount', 14, 2)->default(0)->comment('总金额');
            $table->integer('total_qty')->default(0)->comment('总数量');
            $table->decimal('paid_amount', 14, 2)->default(0)->comment('已付金额');
            $table->string('status', 20)->default('draft')->comment('状态: draft/pending/approved/completed/cancelled');
            $table->unsignedBigInteger('transferred_to')->nullable()->comment('转交ID');
            $table->foreignId('salesman_id')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('delivery_person_id')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->string('salesman_name', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->text('remark')->nullable();
            $table->integer('print_count')->default(0);
            $table->timestamps();
        });

        // 销售订单明细
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(0)->comment('数量');
            $table->integer('actual_qty')->nullable()->comment('实发数量');
            $table->integer('qty_large')->default(0)->comment('大码数量');
            $table->integer('qty_medium')->default(0)->comment('中码数量');
            $table->integer('qty_small')->default(0)->comment('小码数量');
            $table->decimal('price', 10, 2)->default(0)->comment('单价');
            $table->decimal('price_large', 10, 2)->default(0)->comment('大码单价');
            $table->decimal('price_medium', 10, 2)->default(0)->comment('中码单价');
            $table->decimal('price_small', 10, 2)->default(0)->comment('小码单价');
            $table->decimal('amount', 14, 2)->default(0)->comment('金额');
            $table->text('remark')->nullable();
            $table->string('sale_mode', 20)->default('normal')->comment('销售模式');
            $table->timestamps();
        });

        // 采购订单
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->date('order_date');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->integer('total_qty')->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        // 采购订单明细
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        // 入库单
        Schema::create('stock_ins', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();
            $table->string('type', 20)->default('purchase')->comment('类型: purchase/adjustment');
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->date('stock_date');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        // 入库明细
        Schema::create('stock_in_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_in_id')->constrained('stock_ins')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        // 出库单
        Schema::create('stock_outs', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();
            $table->string('type', 20)->default('sale')->comment('类型: sale/damage/transfer');
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->date('stock_date');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        // 出库明细
        Schema::create('stock_out_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_out_id')->constrained('stock_outs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('remark')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_out_items');
        Schema::dropIfExists('stock_outs');
        Schema::dropIfExists('stock_in_items');
        Schema::dropIfExists('stock_ins');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('stocks_history');
        Schema::dropIfExists('stocks');
    }
};
