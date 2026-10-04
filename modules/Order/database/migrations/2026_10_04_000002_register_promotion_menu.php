<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「促销管理」菜单，挂在 order（订单管理）下。幂等 + 补角色授权。
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'order')->value('id');
        if (! $parentId) {
            return;
        }

        if (! DB::table('auth_permission')->where('name', 'order.promotion')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '促销管理',
                'name' => 'order.promotion',
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => '/business/promotion',
                'component' => 'business/promotion/index',
                'sort' => 20,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menuId = DB::table('auth_permission')->where('name', 'order.promotion')->value('id');
        if ($menuId) {
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where(fn ($q) => $q->where('p.name', 'order')->orWhere('p.name', 'like', 'order.%'))
                ->pluck('rp.role_id');
            foreach ($roleIds as $roleId) {
                $exists = DB::table('auth_role_permission')
                    ->where('role_id', $roleId)->where('permission_id', $menuId)->exists();
                if (! $exists) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId, 'permission_id' => $menuId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $menuId = DB::table('auth_permission')->where('name', 'order.promotion')->value('id');
        if ($menuId) {
            DB::table('auth_role_permission')->where('permission_id', $menuId)->delete();
            DB::table('auth_permission')->where('id', $menuId)->delete();
        }
    }
};
