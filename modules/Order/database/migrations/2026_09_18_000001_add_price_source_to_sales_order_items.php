<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补齐旧系统新增订单的 price_source 字段。
 *
 * 旧系统的三档单价并非都能被用户改：销售模式为「赠品」或「陈列费」时，前端把
 * price_small / price_medium / price_large 全部清零，并在 price_source 上标记「特殊」，
 * 以便在单据上区分「这个 0 元是业务规则清出来的，不是漏填」。
 *
 * 新系统的 sales_order_items 迁移建表时带了 sale_mode，却漏了 price_source——于是新前端
 * 只能把 sale_mode 存成中文标签，而「特殊」这个来源标记无处落地。这里补上它，
 * 与旧系统字段一一对齐；不改已有列，只在明细表追加一列。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_items') && ! Schema::hasColumn('sales_order_items', 'price_source')) {
            Schema::table('sales_order_items', function (Blueprint $table) {
                $table->string('price_source', 20)->nullable()->after('price_small')->comment('单价来源：特殊=赠品/陈列费按业务规则清零');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_order_items') && Schema::hasColumn('sales_order_items', 'price_source')) {
            Schema::table('sales_order_items', function (Blueprint $table) {
                $table->dropColumn('price_source');
            });
        }
    }
};
