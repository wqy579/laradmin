<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「库存调整」菜单（库存管理 → 库存调整）。
 * 幂等：按 name 判重再插入；已拥有 inventory 权限的角色自动补授权。
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'inventory')->value('id');
        if (! $parentId) {
            return;
        }

        if (! DB::table('auth_permission')->where('name', 'inventory.stock-adjust')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '库存调整',
                'name' => 'inventory.stock-adjust',
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => '/business/stock-adjust',
                'component' => 'business/stock-adjust/index',
                'sort' => 31,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menuId = DB::table('auth_permission')->where('name', 'inventory.stock-adjust')->value('id');
        if ($menuId) {
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where(function ($q) {
                    $q->where('p.name', 'inventory')->orWhere('p.name', 'like', 'inventory.%');
                })
                ->pluck('rp.role_id');

            foreach ($roleIds as $roleId) {
                $exists = DB::table('auth_role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $menuId)
                    ->exists();
                if (! $exists) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $menuId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $menuId = DB::table('auth_permission')->where('name', 'inventory.stock-adjust')->value('id');
        if ($menuId) {
            DB::table('auth_role_permission')->where('permission_id', $menuId)->delete();
            DB::table('auth_permission')->where('id', $menuId)->delete();
        }
    }
};
