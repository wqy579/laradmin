<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 删除所有库存监控相关的重复菜单，只保留 inventory.query
        $menusToDelete = [
            'inventory.liankai-stock-check',
            'inventory.monitor',
        ];

        foreach ($menusToDelete as $menuName) {
            DB::table('auth_permission')
                ->where('name', $menuName)
                ->delete();
        }

        // 确保 inventory.query 指向正确的路径
        DB::table('auth_permission')
            ->where('name', 'inventory.query')
            ->update([
                'title' => '库存监控',
                'path' => '/business/liankai-stock-monitor',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // 恢复 menu.query
        DB::table('auth_permission')
            ->where('name', 'inventory.query')
            ->update([
                'title' => '库存查询',
                'path' => '/business/stock',
            ]);

        // 恢复 inventory.liankai-stock-check
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
