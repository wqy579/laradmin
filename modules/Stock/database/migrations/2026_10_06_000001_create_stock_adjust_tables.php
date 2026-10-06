<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 库存调整单（StockAdjust）模块表结构
 *
 * stock_adjusts        调整单主表
 * stock_adjust_items   调整明细（每个商品一行：调整前/调整数量/调整后/成本/金额）
 *
 * 与库存盘点（stock_checks）的区别：
 *   - 盘点是"先有账面→录实盘→自动算差异"；调整单是"直接录入调整数量和原因"
 *   - 调整单没有"in_progress"状态，草稿保存后直接 submit → pending → approve
 *   - change_type 复用 stocks_history：adjust_in（溢余/增加）/ adjust_out（损耗/减少）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_adjusts')) {
            Schema::create('stock_adjusts', function (Blueprint $table) {
                $table->id();
                $table->string('adjust_no', 32)->unique()->comment('调整单号 TZ+Ymd+6位');
                $table->unsignedBigInteger('warehouse_id')->comment('仓库ID');
                $table->date('adjust_date')->comment('调整日期');
                $table->string('adjust_type', 20)->default('other')->comment('类型: stock_loss库存损耗/stock_gain库存溢余/other其他');
                $table->string('status', 20)->default('draft')->comment('状态: draft/pending/approved/cancelled');
                $table->integer('total_qty')->default(0)->comment('调整总数量（正=增加，负=减少）');
                $table->decimal('total_amount', 12, 2)->default(0)->comment('调整总金额');
                $table->text('reason')->nullable()->comment('调整原因（必填）');
                $table->unsignedBigInteger('created_by')->nullable()->comment('创建人ID');
                $table->string('creator_name', 50)->nullable()->comment('创建人姓名快照');
                $table->unsignedBigInteger('approved_by')->nullable()->comment('审核人ID');
                $table->string('approver_name', 50)->nullable()->comment('审核人姓名快照');
                $table->timestamp('approved_at')->nullable()->comment('审核时间');
                $table->text('approval_comment')->nullable()->comment('审核意见');
                $table->timestamps();

                $table->index('warehouse_id');
                $table->index('status');
                $table->index('adjust_date');
                $table->index('adjust_no');
            });
        }

        if (! Schema::hasTable('stock_adjust_items')) {
            Schema::create('stock_adjust_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('adjust_id')->comment('调整单ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->string('product_code', 50)->nullable()->comment('商品编码快照');
                $table->string('product_name', 200)->comment('商品名称快照');
                $table->string('spec', 100)->nullable()->comment('规格快照');
                $table->string('unit', 20)->nullable()->comment('单位快照');
                $table->decimal('before_qty', 14, 2)->default(0)->comment('调整前库存');
                $table->decimal('adjust_qty', 14, 2)->default(0)->comment('调整数量（正=增，负=减）');
                $table->decimal('after_qty', 14, 2)->default(0)->comment('调整后库存');
                $table->decimal('unit_cost', 12, 4)->default(0)->comment('单位成本');
                $table->decimal('total_cost', 14, 2)->default(0)->comment('总成本=abs(adjust_qty)×unit_cost');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('adjust_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjust_items');
        Schema::dropIfExists('stock_adjusts');
    }
};
