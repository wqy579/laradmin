<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 库存查询路径的索引补齐
 *
 * 背景：库存领域的索引一直跟着建表迁移走，而历史建表只覆盖了「主键 + 唯一键 + 外键」。
 * 库存管理台是高频页面，StockController::statistics 与 StockService 的聚合查询都要按
 * 仓库维度扫表，历史迁移里漏了单列索引：
 *
 *   - stocks.warehouse_id      按仓库聚合（statistics、盘点快照），
 *                              现有 unique(product_id, warehouse_id) 的左列是 product_id，
 *                              对「按仓库筛」没有用
 *   - stocks_history.warehouse_id  同上，变动明细列表按仓库过滤
 *
 *   transfers 不在此列：transfers 表只有 from_warehouse_id / to_warehouse_id
 *   （调拨有出库仓与入库仓），没有 warehouse_id 列；且这两列是
 *   foreignId()->constrained()，MySQL 已自动建 FK 索引，无需补建。
 *   此前误列 transfers.warehouse_id 导致 migrate 在生产 1072 失败、每次
 *   部署卡死在前端上线之前的步骤、前端长期 404（2026-09-18 修复）。
 *
 * 幂等：用 Schema::hasIndex 判断，重复执行不会失败，也不会在 CI 的
 * RefreshDatabase 里因「索引已存在」而炸掉。
 */
return new class extends Migration
{
    private const INDEXES = [
        'stocks' => [
            ['warehouse_id'],
            ['quantity'],
        ],
        'stocks_history' => [
            ['warehouse_id'],
            ['change_type'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
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
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_reverse($columns) as $cols) {
                $name = $this->indexName($table, $cols);
                if (! Schema::hasIndex($table, $name)) {
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
