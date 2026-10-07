<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单列表重构（对齐连凯操作体验）所需的字段补充：
 *   - sales_orders.stocker_id / stocker_name：备货人（配货环节记录谁备的货）
 *   - sales_orders.reconcile_date：对账日期（与客户对账时填写）
 *
 * products.weight / volume（列表汇总的重量/体积）另见
 * modules/Stock 模块的 2026_10_07_000001_add_weight_volume_to_products.php。
 *
 * 均为可空字段，不回填历史数据；列表为空时前端显示占位符。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('stocker_id')->nullable()->after('dispatched_by')->comment('备货人ID');
            $table->string('stocker_name', 50)->nullable()->after('stocker_id')->comment('备货人姓名');
            $table->date('reconcile_date')->nullable()->after('approved_at')->comment('对账日期');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['stocker_id', 'stocker_name', 'reconcile_date']);
        });
    }
};
