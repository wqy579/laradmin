<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T5.4 业务员拜访提醒 —— 在「订货中心」分组下注册菜单。
 *
 * 幂等：按 name 判重再插入；已拥有 order 域菜单权限的角色自动补授权。
 * 权限点命名沿用点式约定（order.visit-reminder）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $groupId = (int) DB::table('auth_permission')->where('name', 'order-center')->value('id');
        if (! $groupId) {
            return;
        }

        if (! DB::table('auth_permission')->where('name', 'order.visit-reminder')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '业务员拜访提醒',
                'name' => 'order.visit-reminder',
                'type' => 'menu',
                'parent_id' => $groupId,
                'path' => '/order/visit-reminder',
                'component' => 'order/visit-reminder/index',
                'sort' => 2,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menuId = (int) DB::table('auth_permission')->where('name', 'order.visit-reminder')->value('id');
        if (! $menuId) {
            return;
        }

        $roleIds = DB::table('auth_role_permission as rp')
            ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
            ->where(function ($q) {
                $q->where('p.name', 'order-center')->orWhere('p.name', 'like', 'order.%');
            })
            ->distinct()
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

    public function down(): void
    {
        $id = DB::table('auth_permission')->where('name', 'order.visit-reminder')->value('id');
        if ($id) {
            DB::table('auth_role_permission')->where('permission_id', $id)->delete();
            DB::table('auth_permission')->where('id', $id)->delete();
        }
    }
};
