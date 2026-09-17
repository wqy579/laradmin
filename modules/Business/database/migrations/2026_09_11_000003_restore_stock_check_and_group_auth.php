<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 1. 恢复"库存核对"菜单：stock-check 页面在"库存监控"重构时被取代，
     *    菜单、前端路由、后端路由均缺失，用户侧看不到库存核对入口。
     * 2. 权限管理（用户/角色/权限/部门）归入"系统管理"分组，不再作为顶级主菜单。
     * 3. 隐藏空壳顶级菜单 auth（无子菜单、无路由，点击无效）。
     */
    public function up(): void
    {
        $inventoryId = DB::table('auth_permission')->where('name', 'inventory')->value('id') ?? 72;
        $systemId = DB::table('auth_permission')->where('name', 'system')->value('id') ?? 35;

        // 1. 恢复库存核对菜单（幂等：不存在才插入）
        if (! DB::table('auth_permission')->where('name', 'inventory.stock-check')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '库存核对',
                'name' => 'inventory.stock-check',
                'type' => 'menu',
                'parent_id' => $inventoryId,
                'path' => '/business/stock-check',
                'component' => 'business/stock-check/index',
                'sort' => 20,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            echo "  插入菜单: 库存核对 (inventory.stock-check)\n";
        } else {
            echo "  库存核对菜单已存在，跳过\n";
        }

        // 2. 权限管理归类到系统管理
        $moves = [
            ['name' => 'auth.users',       'sort' => 78],
            ['name' => 'auth.roles',       'sort' => 79],
            ['name' => 'auth.permissions', 'sort' => 80],
            ['name' => 'auth.departments', 'sort' => 81],
        ];
        foreach ($moves as $m) {
            $affected = DB::table('auth_permission')
                ->where('name', $m['name'])
                ->update(['parent_id' => $systemId, 'sort' => $m['sort']]);
            echo "  归类 {$m['name']} -> system (affected: $affected)\n";
        }

        // 3. 隐藏空壳 auth 顶级菜单
        $hidden = DB::table('auth_permission')
            ->where('name', 'auth')->where('parent_id', 0)
            ->update(['status' => 0]);
        echo "  隐藏空壳 auth 菜单 (affected: $hidden)\n";
    }

    public function down(): void
    {
        $systemId = DB::table('auth_permission')->where('name', 'system')->value('id') ?? 35;
        DB::table('auth_permission')->where('name', 'inventory.stock-check')->delete();
        $moves = [
            ['name' => 'auth.users',       'sort' => 3],
            ['name' => 'auth.roles',       'sort' => 2],
            ['name' => 'auth.permissions', 'sort' => 1],
            ['name' => 'auth.departments', 'sort' => 4],
        ];
        foreach ($moves as $m) {
            DB::table('auth_permission')->where('name', $m['name'])
                ->update(['parent_id' => 0, 'sort' => $m['sort']]);
        }
        DB::table('auth_permission')->where('name', 'auth')->where('parent_id', 0)
            ->update(['status' => 1]);
    }
};
