<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单列表与状态机查询的索引补齐
 *
 * 背景：订单管理台是后台访问量最大的模块，列表页几乎都按「状态 + 下单日期」筛选排序，
 * 而历史建表（create_transaction_tables）只建了 order_no 唯一键和主外键，
 * 没有覆盖这两条最常见的扫描路径。拆出 Order 模块后顺手补齐，避免再往 Business 的
 * 混合表迁移里加东西（那些迁移已在全量库上执行过，改动会污染 migrations 记录）。
 *
 * 幂等：Schema::hasIndex 判断，重复执行安全，CI 的 RefreshDatabase 不会因索引已存在而失败。
 */
return new class extends Migration
{
    /**
     * 表名 => 待补的索引列组合
     *
     * sales_orders/purchase_orders 的列表筛选是「状态 + 下单日期」；
     * 明细表的关联 ID 被列表 eager load 频繁命中，补单列索引。
     */
    private const INDEXES = [
        'sales_orders' => [
            ['status'],
            ['status', 'order_date'],
            ['customer_id'],
            ['warehouse_id'],
        ],
        'purchase_orders' => [
            ['status'],
            ['status', 'order_date'],
            ['supplier_id'],
            ['warehouse_id'],
        ],
        'sales_order_items' => [
            ['sales_order_id'],
        ],
        'purchase_order_items' => [
            ['purchase_order_id'],
        ],
        'deliveries' => [
            ['status'],
        ],
        'return_orders' => [
            ['status'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $cols) {
                $name = $this->indexName($table, $cols);
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $t) use ($cols, $name) {
                    $t->index($cols, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach (array_reverse($columns) as $cols) {
                $name = $this->indexName($table, $cols);
                if (!Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $t) use ($name) {
                    $t->dropIndex($name);
                });
            }
        }
    }

    private function indexName(string $table, array $columns): string
    {
        return $table.'_'.implode('_', $columns).'_index';
    }
};
