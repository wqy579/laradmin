<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('returns') || ! Schema::hasColumn('returns', 'customer_id')) {
            return;
        }

        // 先尝试删除可能存在的错误外键（无论外键名是什么）
        try {
            DB::statement('ALTER TABLE `returns` DROP FOREIGN KEY `returns_ibfk_1`');
        } catch (Throwable $e) {
            // 忽略，外键可能不存在或名字不同
        }
        try {
            DB::statement('ALTER TABLE `returns` DROP FOREIGN KEY `returns_customer_id_foreign`');
        } catch (Throwable $e) {
            // 忽略
        }

        // 添加正确的外键约束（引用 customers 表）
        try {
            DB::statement(
                'ALTER TABLE `returns` ADD CONSTRAINT `returns_customer_id_foreign` '.
                'FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE'
            );
        } catch (Throwable $e) {
            // 外键可能已存在，忽略
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('returns')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE `returns` DROP FOREIGN KEY `returns_customer_id_foreign`');
        } catch (Throwable $e) {
            // 忽略
        }
    }
};
