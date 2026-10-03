<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 库存盘点（Stocktaking）模块表结构
 *
 * stock_checks       盘点单主表
 * stock_check_items  盘点明细（每个商品一行：账面 / 实盘 / 差异）
 *
 * 库存变动流水复用已有 stocks_history（change_type 新增 check_in 盘盈 / check_out 盘亏），
 * 成本单价取仓级 stocks.cost_price。状态字段沿用项目惯例用字符串（同 sales_orders）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_checks')) {
            Schema::create('stock_checks', function (Blueprint $table) {
                $table->id();
                $table->string('check_no', 50)->unique()->comment('盘点单号 PD+Ymd+6位');
                $table->unsignedBigInteger('warehouse_id')->comment('仓库ID');
                $table->date('check_date')->comment('盘点日期');
                $table->string('check_type', 20)->default('full')->comment('类型: full全面/sample抽盘/adjust异动');
                $table->string('status', 20)->default('draft')->comment('状态: draft待盘点/in_progress盘点中/pending待审核/approved已审核/cancelled已取消');
                $table->integer('total_skus')->default(0)->comment('商品种类数');
                $table->integer('profit_qty')->default(0)->comment('盘盈数量合计');
                $table->integer('loss_qty')->default(0)->comment('盘亏数量合计');
                $table->decimal('profit_amount', 14, 2)->default(0)->comment('盘盈金额合计');
                $table->decimal('loss_amount', 14, 2)->default(0)->comment('盘亏金额合计');
                $table->text('remark')->nullable()->comment('备注');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人ID');
                $table->string('creator_name', 50)->nullable()->comment('制单人姓名快照');
                $table->unsignedBigInteger('approved_by')->nullable()->comment('审核人ID');
                $table->string('approver_name', 50)->nullable()->comment('审核人姓名快照');
                $table->timestamp('approved_at')->nullable()->comment('审核时间');
                $table->text('approval_comment')->nullable()->comment('审核意见');
                $table->timestamps();

                $table->index('warehouse_id');
                $table->index('status');
                $table->index('check_date');
            });
        }

        if (! Schema::hasTable('stock_check_items')) {
            Schema::create('stock_check_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('check_id')->comment('盘点单ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->string('product_code', 50)->nullable()->comment('商品编码快照');
                $table->string('product_name', 200)->comment('商品名称快照');
                $table->string('spec', 100)->nullable()->comment('规格快照');
                $table->string('unit', 20)->nullable()->comment('单位快照');
                $table->integer('book_qty')->default(0)->comment('账面数量');
                $table->integer('actual_qty')->default(0)->comment('实盘数量');
                $table->integer('diff_qty')->default(0)->comment('差异数量=实盘-账面');
                $table->decimal('cost_price', 10, 2)->default(0)->comment('成本单价快照');
                $table->decimal('diff_amount', 14, 2)->default(0)->comment('差异金额=差异数量×成本单价');
                $table->string('checker', 50)->nullable()->comment('盘点人');
                $table->timestamps();

                $table->index('check_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_check_items');
        Schema::dropIfExists('stock_checks');
    }
};
