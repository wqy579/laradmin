<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 删除旧的库存核对菜单（inventory.liankai-stock-check）
        // 保留更新后的 inventory.query（库存监控）
        DB::table('auth_permission')
            ->where('name', 'inventory.liankai-stock-check')
            ->delete();
    }

    public function down(): void
    {
        // 恢复旧菜单
        $inventoryId = DB::table('auth_permission')->where('name', 'inventory')->value('id');
        if ($inventoryId) {
            DB::table('auth_permission')->insertOrIgnore([
                [
                    'name' => 'inventory.liankai-stock-check',
                    'title' => '库存核对',
                    'type' => 'menu',
                    'parent_id' => $inventoryId,
                    'path' => '/business/liankai-stock-check',
                    'component' => 'business/liankai-stock-check/index',
                    'sort' => 9,
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
};
