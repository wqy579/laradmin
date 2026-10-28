<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T5.2 订货调整 —— 订货调整单 + 明细。
 *
 * 针对已存在的销售订单做调整：
 *   - modify：按行调整数量（old_qty → new_qty），审核通过后回写订单明细并重算金额
 *   - void：整单作废（approve 时将源订单置 cancelled）
 * 状态机：pending → approved / rejected / cancelled。
 * 每次动作写 order_operation_logs（order_type=order_adjustment），审核落地时给源订单补一条留痕。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjust_no', 30)->unique()->comment('调整单号');
            $table->foreignId('order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->string('type', 20)->default('modify')->comment('modify=调整数量 void=作废整单');
            $table->string('reason', 500)->nullable()->comment('调整原因');
            $table->string('status', 20)->default('pending')->comment('pending/approved/rejected/cancelled');
            $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('order_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('order_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('old_qty', 12, 2)->default(0)->comment('调整前数量');
            $table->decimal('new_qty', 12, 2)->default(0)->comment('调整后数量');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_adjustment_items');
        Schema::dropIfExists('order_adjustments');
    }
};
