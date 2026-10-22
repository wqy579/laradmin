<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客户订货核对（T5.1）模块表结构
 *
 * presale_check          订货核对主表（一张销售订单一张核对单）
 * presale_check_item     核对明细（申报数量 vs 建议/历史数量 vs 核对确认数量）
 *
 * 状态机：pending → checked（checked 为终态，核对完成留 checked_by/checked_at 痕迹）
 *
 * 差异率：diff_rate = (declared_qty - suggest_qty) / suggest_qty * 100（百分比）
 *   - suggest_qty 为 0 或空时无法算比率 → diff_rate 为 null，
 *     result 按 declared_qty > 0 ? 'high' : 'agree' 兜底判定
 *   - |diff_rate| > 30 为异常（high=申报偏多 / low=申报偏少），<= 30 为 agree
 *
 * 单号：PCHK + Ymd + 6位流水（对齐 CGSQ/SO 的单号生成模式）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('presale_check')) {
            Schema::create('presale_check', function (Blueprint $table) {
                $table->id();
                $table->string('check_no', 30)->unique()->comment('核对单号 PCHK+Ymd+6位');
                $table->unsignedBigInteger('sales_order_id')->comment('销售订单ID(sales_orders.id)');
                $table->unsignedBigInteger('customer_id')->comment('客户ID(customers.id)');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('仓库ID(sales_orders冗余，便于筛选)');
                $table->string('status', 20)->default('pending')->comment('状态: pending待核对/checked已核对');
                $table->unsignedBigInteger('checked_by')->nullable()->comment('核对人(auth_user.id)');
                $table->timestamp('checked_at')->nullable()->comment('核对时间');
                $table->text('remark')->nullable()->comment('备注');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人(auth_user.id)');
                $table->timestamps();

                $table->index('sales_order_id');
                $table->index('customer_id');
                $table->index('status');
                $table->index('check_no');
                $table->index('created_by');
            });
        }

        if (! Schema::hasTable('presale_check_item')) {
            Schema::create('presale_check_item', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('presale_check_id')->comment('核对单ID(presale_check.id)');
                $table->unsignedBigInteger('sales_order_item_id')->comment('销售订单明细ID(sales_order_items.id)');
                $table->unsignedBigInteger('product_id')->comment('商品ID(products.id)');
                $table->decimal('declared_qty', 14, 3)->default(0)->comment('申报数量（订单行 quantity 快照）');
                $table->decimal('suggest_qty', 14, 3)->nullable()->comment('建议/历史数量（可为空，空时无法算差异率）');
                $table->decimal('check_qty', 14, 3)->default(0)->comment('核对确认数量（核对环节填写，初始=申报数量）');
                $table->decimal('diff_rate', 8, 2)->nullable()->comment('差异率% =(申报-建议)/建议*100，建议<=0时为null');
                $table->string('result', 10)->default('agree')->comment('结果: agree一致/high申报偏多/low申偏少');
                $table->text('remark')->nullable()->comment('行备注');
                $table->timestamps();

                $table->index('presale_check_id');
                $table->index('sales_order_item_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('presale_check_item');
        Schema::dropIfExists('presale_check');
    }
};
