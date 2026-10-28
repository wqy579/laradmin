<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T5.2 订货调整 / T5.3 订单移库 —— 在「订货中心」分组下注册菜单。
 *
 * 幂等：按 name 判重再插入；已拥有 order 域菜单权限的角色自动补授权。
 * 权限点命名沿用点式约定（order.order-adjust / order.order-transfer）。
 */
return new class extends Migration
{
    /** @var array<int, array<string, mixed>> */
    private array $menus = [
        [
            'name' => 'order.order-adjust',
            'title' => '订货调整',
            'path' => '/order/order-adjust',
            'component' => 'order/order-adjust/index',
            'sort' => 3,
        ],
        [
            'name' => 'order.order-transfer',
            'title' => '订单移库',
            'path' => '/order/order-transfer',
            'component' => 'order/order-transfer/index',
            'sort' => 4,
        ],
    ];

    public function up(): void
    {
        $groupId = (int) DB::table('auth_permission')->where('name', 'order-center')->value('id');
        if (! $groupId) {
            return;
        }

        foreach ($this->menus as $menu) {
            if (! DB::table('auth_permission')->where('name', $menu['name'])->exists()) {
                DB::table('auth_permission')->insert([
                    'title' => $menu['title'],
                    'name' => $menu['name'],
                    'type' => 'menu',
                    'parent_id' => $groupId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $menuId = (int) DB::table('auth_permission')->where('name', $menu['name'])->value('id');
            if (! $menuId) {
                continue;
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
    }

    public function down(): void
    {
        foreach ($this->menus as $menu) {
            $id = DB::table('auth_permission')->where('name', $menu['name'])->value('id');
            if ($id) {
                DB::table('auth_role_permission')->where('permission_id', $id)->delete();
                DB::table('auth_permission')->where('id', $id)->delete();
            }
        }
    }
};
