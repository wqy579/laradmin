<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补齐报表筛选需要的两个维度：客户渠道类别、销售订单单据来源。
 *
 * 连凯对标方案的销售/业务员报表查询条件里有「渠道大类 / 渠道小类 / 单据来源」三项，
 * 现有 customers / sales_orders 都没有对应列。这两列都是纯筛选维度、可空、不参与任何
 * 既有业务流程，新增列对历史数据零影响（历史行取 NULL，报表里显示为「未填写」）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'channel_category')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('channel_category', 50)->nullable()->after('category')->comment('渠道大类');
                $table->string('channel_sub_category', 50)->nullable()->after('channel_category')->comment('渠道小类');
            });
        }

        if (! Schema::hasColumn('sales_orders', 'source')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                // admin=后台录单 / miniapp=小程序下单 / app=APP下单 / import=导入
                $table->string('source', 20)->default('admin')->after('order_type')->comment('单据来源');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'channel_category')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('channel_category');
            });
        }

        if (Schema::hasColumn('customers', 'channel_sub_category')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('channel_sub_category');
            });
        }

        if (Schema::hasColumn('sales_orders', 'source')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
