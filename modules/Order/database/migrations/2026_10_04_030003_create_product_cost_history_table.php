<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品成本变动历史。组装/拆分审核结转成本时写入，仿 product_price_history。
 * 系统此前无成本历史表（product_price_history 只记售价）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_cost_history')) {
            Schema::create('product_cost_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('仓库ID');
                $table->decimal('old_cost', 10, 2)->nullable()->comment('变动前成本');
                $table->decimal('new_cost', 10, 2)->nullable()->comment('变动后成本');
                $table->decimal('old_qty', 10, 3)->nullable()->comment('变动前库存');
                $table->decimal('new_qty', 10, 3)->nullable()->comment('变动后库存');
                $table->string('change_type', 20)->comment('变更类型:assembly/split/manual');
                $table->unsignedBigInteger('related_id')->nullable()->comment('关联单据ID');
                $table->string('related_type', 50)->nullable()->comment('关联类型:Assembly/Disassembly');
                $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人ID');
                $table->string('operator_name', 50)->nullable()->comment('操作人名');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('product_id');
                $table->index(['related_id', 'related_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cost_history');
    }
};
