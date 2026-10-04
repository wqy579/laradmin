<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 销售订单增加「促销优惠金额」列：
 *  - discount_amount：下单时由 PromotionService 自动匹配出的优惠总额（商品折扣 + 满减）
 *  - total_amount 仍为商品明细金额合计，实付 = total_amount - discount_amount
 *  - 买赠赠品以零价明细行（sale_mode=赠品）落地，不进 discount_amount
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_orders', 'discount_amount')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->decimal('discount_amount', 14, 2)->default(0)->after('total_amount')->comment('促销优惠金额');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_orders', 'discount_amount')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropColumn('discount_amount');
            });
        }
    }
};
