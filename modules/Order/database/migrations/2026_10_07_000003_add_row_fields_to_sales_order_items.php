<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 明细行扩展字段（对齐连凯录入体验）：
 *   - production_date：生产日期（启用生产日期开关时录入，临期/批次管理用）
 *   - tax_rate：税率（启用税率开关时录入，含税金额 = 不含税 × (1 + tax_rate/100)）
 *   - discount_rate：折扣率（销售折扣开关时录入，折后金额 = 原价 × (1 - discount_rate/100)）
 *
 * tax_rate / discount_rate 默认 0，不影响旧单金额；启用后 amount 公式扩展为：
 *   amount = 原价小计 × (1 + tax_rate/100) × (1 - discount_rate/100)
 * 前后端同口径计算，后端不信前端算好的 amount（与现有行为一致）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->date('production_date')->nullable()->after('remark')->comment('生产日期');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('production_date')->comment('税率%');
            $table->decimal('discount_rate', 5, 2)->default(0)->after('tax_rate')->comment('折扣率%');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn(['production_date', 'tax_rate', 'discount_rate']);
        });
    }
};
