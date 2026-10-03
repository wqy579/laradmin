<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「库存盘点」菜单。
 *
 * 父菜单 inventory（库存管理）已存在。本菜单幂等：按 name 判重，
 * 再为已拥有 inventory 权限的角色补授权（超管走 isSuperAdmin 不查此表）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'inventory')->value('id');
        if (! $parentId) {
            return;
        }

        if (! DB::table('auth_permission')->where('name', 'inventory.stocktaking')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '库存盘点',
                'name' => 'inventory.stocktaking',
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => '/business/stocktaking',
                'component' => 'business/stocktaking/index',
                'sort' => 30,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menuId = DB::table('auth_permission')->where('name', 'inventory.stocktaking')->value('id');
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
        $menuId = DB::table('auth_permission')->where('name', 'inventory.stocktaking')->value('id');
        if ($menuId) {
            DB::table('auth_role_permission')->where('permission_id', $menuId)->delete();
            DB::table('auth_permission')->where('id', $menuId)->delete();
        }
    }
};
