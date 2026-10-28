<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T5.3 订单移库 —— 订单级移库单 + 明细。
 *
 * 将销售订单的发货仓从 from_warehouse_id 调整为 to_warehouse_id（可整单或按明细行）。
 * 强约束：移库量 ≤ 订货量（quantity），后端校验超量报 422 且不落库，前端同规则拦截。
 * 每次动作写 order_operation_logs（order_type=order_transfer），留痕可在详情查看。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 30)->unique()->comment('移库单号');
            $table->foreignId('order_id')->constrained('sales_orders')->cascadeOnDelete()->comment('源销售订单');
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('原仓');
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete()->comment('目标仓');
            $table->date('transfer_date')->comment('移库日期');
            $table->string('reason', 500)->nullable()->comment('移库原因');
            $table->string('status', 20)->default('done')->comment('done=已移库 cancelled=已作废');
            $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index('to_warehouse_id');
        });

        Schema::create('order_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_transfer_id')->constrained('order_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty', 12, 2)->default(0)->comment('移库数量（≤ 订货量）');
            $table->timestamps();
            $table->unique(['order_transfer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transfer_items');
        Schema::dropIfExists('order_transfers');
    }
};
