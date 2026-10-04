<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 查找并删除所有错误的 customer_id 外键（可能指向 suppliers）
        $foreignKeys = DB::select("
            SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'returns'
              AND COLUMN_NAME = 'customer_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        
        foreach ($foreignKeys as $fk) {
            if ($fk->REFERENCED_TABLE_NAME !== 'customers') {
                // 删除错误的外键
                DB::statement("ALTER TABLE `returns` DROP FOREIGN KEY `" . $fk->CONSTRAINT_NAME . "`");
            }
        }
        
        // 如果还没有正确的 customer_id 外键，添加它
        $existing = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'returns'
              AND COLUMN_NAME = 'customer_id'
              AND REFERENCED_TABLE_NAME = 'customers'
        ");
        
        if (empty($existing)) {
            DB::statement("
                ALTER TABLE `returns`
                ADD CONSTRAINT `returns_customer_id_foreign`
                FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
                ON DELETE CASCADE
            ");
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE `returns` DROP FOREIGN KEY `returns_customer_id_foreign`");
        } catch (\Exception $e) {
            // 外键可能不存在
        }
    }
};
