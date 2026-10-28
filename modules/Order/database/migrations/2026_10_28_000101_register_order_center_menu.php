<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T5.1 客户订货核对 —— 注册「订货中心」顶级菜单分组 + 首个菜单「客户订货核对」。
 *
 * 订货中心（F 域 大块5 订货闭环）与 订单管理/总账管理 并列，sort=15。
 * 幂等：按 name 判重再插入；已拥有 order 域菜单权限的角色自动补授权。
 * 权限点命名沿用库内点式约定（order.check），前缀 order。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. 顶级分组「订货中心」
        if (! DB::table('auth_permission')->where('name', 'order-center')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '订货中心',
                'name' => 'order-center',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/order/order-check',
                'component' => '',
                'meta' => '{"icon":"ElIconGoods"}',
                'sort' => 15,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $groupId = (int) DB::table('auth_permission')->where('name', 'order-center')->value('id');
        if (! $groupId) {
            return;
        }

        // 2. 子菜单：客户订货核对
        if (! DB::table('auth_permission')->where('name', 'order.check')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '客户订货核对',
                'name' => 'order.check',
                'type' => 'menu',
                'parent_id' => $groupId,
                'path' => '/order/order-check',
                'component' => 'order/order-check/index',
                'sort' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. 给已有订货中心/订单域菜单权限的角色补授权
        $menuId = (int) DB::table('auth_permission')->where('name', 'order.check')->value('id');
        if ($menuId) {
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where(function ($q) {
                    $q->where('p.name', 'order')->orWhere('p.name', 'like', 'order.%');
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
    }

    public function down(): void
    {
        $id = DB::table('auth_permission')->where('name', 'order.check')->value('id');
        if ($id) {
            DB::table('auth_role_permission')->where('permission_id', $id)->delete();
            DB::table('auth_permission')->where('id', $id)->delete();
        }

        // 分组仅在无其余子菜单时一并删除
        $groupId = DB::table('auth_permission')->where('name', 'order-center')->value('id');
        if ($groupId && ! DB::table('auth_permission')->where('parent_id', $groupId)->exists()) {
            DB::table('auth_role_permission')->where('permission_id', $groupId)->delete();
            DB::table('auth_permission')->where('id', $groupId)->delete();
        }
    }
};
