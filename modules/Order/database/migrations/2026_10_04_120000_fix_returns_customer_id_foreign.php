<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // 检查并修复 returns 表的 customer_id 外键
        // 确保 customer_id 引用 customers 表而非 suppliers 表
        
        if (Schema::hasTable('returns') && Schema::hasColumn('returns', 'customer_id')) {
            // 获取当前外键信息
            $foreignKeys = DB::select("SHOW CREATE TABLE returns");
            
            if (!empty($foreignKeys)) {
                $createStatement = $foreignKeys[0]->{'Create Table'};
                
                // 如果外键指向 suppliers 表，则删除并重新创建指向 customers 的外键
                if (strpos($createStatement, 'REFERENCES `suppliers`') !== false) {
                    // 删除现有的 customer_id 外键
                    try {
                        DB::statement("ALTER TABLE `returns` DROP FOREIGN KEY `returns_ibfk_1`");
                    } catch (\Exception $e) {
                        // 尝试其他可能的外键名
                        try {
                            DB::statement("ALTER TABLE `returns` DROP FOREIGN KEY `returns_customer_id_foreign`");
                        } catch (\Exception $e2) {
                            // 忽略错误
                        }
                    }
                    
                    // 添加正确的外键
                    DB::statement("ALTER TABLE `returns` ADD CONSTRAINT `returns_customer_id_foreign` 
                        FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE");
                }
            }
        }
    }

    public function down(): void
    {
        // 回滚操作
        try {
            DB::statement("ALTER TABLE `returns` DROP FOREIGN KEY `returns_customer_id_foreign`");
            DB::statement("ALTER TABLE `returns` ADD CONSTRAINT `returns_ibfk_1` 
                FOREIGN KEY (`customer_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE");
        } catch (\Exception $e) {
            // 忽略错误
        }
    }
};
