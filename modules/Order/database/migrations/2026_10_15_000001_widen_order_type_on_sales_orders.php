<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sales_orders.order_type 由 enum('wholesale','retail') 放宽为 varchar(20)。
 *
 * 背景（订单一张都录不进去的直接原因）：
 *   sales_orders 这张表没有对应的 create 迁移——它来自生产遗留库，建表时
 *   order_type 定成了 enum('wholesale','retail')。
 *   而订单弹窗的「订单类型」四个 tab（普通/处理/换货/还货）会写入
 *   normal / process / exchange / give_back；借货转销售还会写 borrow_convert。
 *   这些值都不在枚举里，MySQL 严格模式下一律拒绝：
 *     SQLSTATE[01000] Warning: 1265 Data truncated for column 'order_type'
 *   结果 POST /admin/business/sales-order 直接 500，订单一张都存不进去。
 *
 * 为什么改表而不是改代码：
 *   列表页的「订单类型 tab」是按 order_type 列的实际取值分组统计的
 *   （见 SalesOrderController::index 的 typeCount），前端 typeLabelMap 也
 *   是按 normal/process/exchange/give_back 做中文映射。也就是说代码这一侧
 *   已经把 order_type 当成业务类型字典用了，枚举只是遗留 schema 的历史包袱。
 *   把 tab 值映射回 wholesale/retail 会让四个类型塌缩成同一个值，信息丢失，
 *   且与列表筛选、打印模板的既有口径冲突，故选择放宽列宽。
 *
 * 幂等：仅当当前列确实是 enum 时才 ALTER，已经是 varchar 则直接跳过。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'order_type')) {
            return;
        }

        $row = DB::selectOne("SHOW COLUMNS FROM `sales_orders` WHERE Field = 'order_type'");
        $type = strtolower((string) ($row->Type ?? ''));

        // 已是 varchar / 非 enum：无需处理
        if (! str_starts_with($type, 'enum(')) {
            return;
        }

        DB::statement("ALTER TABLE `sales_orders` MODIFY `order_type` VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT '订单类型：normal/process/exchange/give_back'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'order_type')) {
            return;
        }

        // 回滚前先把枚举外的值收敛到 wholesale，否则 enum 会拒收导致 ALTER 失败
        DB::table('sales_orders')
            ->whereNotIn('order_type', ['wholesale', 'retail'])
            ->update(['order_type' => 'wholesale']);

        DB::statement("ALTER TABLE `sales_orders` MODIFY `order_type` ENUM('wholesale','retail') NOT NULL DEFAULT 'wholesale'");
    }
};
