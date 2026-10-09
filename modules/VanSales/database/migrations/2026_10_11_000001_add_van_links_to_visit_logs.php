<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段二：给 visit_logs 加车销单据关联列。
 * 客户拜访（visit_logs）已完整存在（GPS签到签退/照片/时长），本迁移只加联动字段：
 * van_sale_order_id / van_return_order_id，车销单 approve 时回写，拜访详情页展示关联单据。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('visit_logs', 'van_sale_order_id')) {
            Schema::table('visit_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('van_sale_order_id')->nullable()->after('status')->comment('关联车销销售单ID');
                $table->unsignedBigInteger('van_return_order_id')->nullable()->after('van_sale_order_id')->comment('关联车销退货单ID');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('visit_logs', 'van_return_order_id')) {
            Schema::table('visit_logs', function (Blueprint $table) {
                $table->dropColumn(['van_sale_order_id', 'van_return_order_id']);
            });
        }
    }
};
