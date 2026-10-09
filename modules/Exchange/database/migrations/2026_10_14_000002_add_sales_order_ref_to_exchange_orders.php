<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 换货单关联「原销售单」（设计方案：新增弹窗里选原销售单号，自动带出原商品明细）。
 *
 * 幂等：先判列是否存在再添加，重复执行不报错（SQLite 本地 / MySQL 生产都安全）。
 * after() 只在 MySQL 生效，SQLite 会忽略，不影响结构。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('exchange_orders', 'sales_order_id')) {
                $table->unsignedBigInteger('sales_order_id')->nullable()->after('customer_name')->comment('原销售单ID');
            }
            if (! Schema::hasColumn('exchange_orders', 'sales_order_no')) {
                $table->string('sales_order_no', 40)->nullable()->after('sales_order_id')->comment('原销售单号');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exchange_orders', function (Blueprint $table) {
            if (Schema::hasColumn('exchange_orders', 'sales_order_no')) {
                $table->dropColumn('sales_order_no');
            }
            if (Schema::hasColumn('exchange_orders', 'sales_order_id')) {
                $table->dropColumn('sales_order_id');
            }
        });
    }
};
