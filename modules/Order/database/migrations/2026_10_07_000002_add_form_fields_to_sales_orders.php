<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 销售订单新增/编辑表单补齐（对齐连凯录入体验）：
 *   - delivery_date：送货日期（区别于 order_date 下单日期）
 *   - dispatch_date：配送日期（与列表页批量调度共用）
 *   - print_type：打印类型（不打印/订单明细单/送货单）
 *   - sort_type：申报顺序（商品编码/商品名称/录入顺序）
 *
 * vehicle_id / reconcile_date / stocker_id / stocker_name 已存在（见
 * 2026_10_07_000001_add_stocker_reconcile_fields 与原始建表迁移），
 * 但 store() 之前没写入——本轮同时补 controller 的落库。
 *
 * 均为可空字段，不回填历史数据；默认值保证旧单读出来有合理值。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->date('delivery_date')->nullable()->after('order_date')->comment('送货日期');
            $table->date('dispatch_date')->nullable()->after('delivery_date')->comment('配送日期');
            $table->string('print_type', 20)->default('none')->after('remark')->comment('打印类型：none/item_note/delivery_note');
            $table->string('sort_type', 20)->default('entry')->after('print_type')->comment('申报顺序：code/name/entry');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_date', 'dispatch_date', 'print_type', 'sort_type']);
        });
    }
};
