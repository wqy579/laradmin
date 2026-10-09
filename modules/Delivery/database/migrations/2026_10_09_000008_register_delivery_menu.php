<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Permission;

/**
 * 注册「配送管理」菜单（独立顶级，7 子菜单），幂等 + 补角色授权。
 *
 * 注：与历史 order.dispatch「配送管理」（指向旧 /business/disdispatch 壳页面）并存，
 * 本菜单为完整的配送业务闭环入口，命名空间 delivery.*。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 顶级菜单
        if (! DB::table('auth_permission')->where('name', 'delivery')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '配送管理',
                'name' => 'delivery',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/business/delivery-picking',
                'component' => null,
                'meta' => json_encode(['icon' => 'ElIconVan']),
                'sort' => 11, // 位于小程序管理(10)之后
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $parentId = DB::table('auth_permission')->where('name', 'delivery')->value('id');

        $menus = [
            ['name' => 'delivery.picking', 'title' => '配货单', 'path' => '/business/delivery-picking', 'component' => 'business/delivery/picking/index', 'sort' => 1],
            ['name' => 'delivery.pick', 'title' => '拣货单', 'path' => '/business/delivery-pick', 'component' => 'business/delivery/pick/index', 'sort' => 2],
            ['name' => 'delivery.check', 'title' => '验货单', 'path' => '/business/delivery-check', 'component' => 'business/delivery/check/index', 'sort' => 3],
            ['name' => 'delivery.load', 'title' => '装车单', 'path' => '/business/delivery-load', 'component' => 'business/delivery/load/index', 'sort' => 4],
            ['name' => 'delivery.task', 'title' => '配送任务', 'path' => '/business/delivery-task', 'component' => 'business/delivery/task/index', 'sort' => 5],
            ['name' => 'delivery.collection', 'title' => '配送收款', 'path' => '/business/delivery-collection', 'component' => 'business/delivery/collection/index', 'sort' => 6],
            ['name' => 'delivery.remit', 'title' => '上交货款', 'path' => '/business/delivery-remit', 'component' => 'business/delivery/remit/index', 'sort' => 7],
        ];

        foreach ($menus as $menu) {
            if (! DB::table('auth_permission')->where('name', $menu['name'])->exists()) {
                DB::table('auth_permission')->insert([
                    'title' => $menu['title'],
                    'name' => $menu['name'],
                    'type' => 'menu',
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $menuId = DB::table('auth_permission')->where('name', $menu['name'])->value('id');
            $this->grantToOrderRoles($menuId);
        }

        // 顶级菜单授权给已拥有 order 菜单的角色
        $this->grantToOrderRoles($parentId);
    }

    private function grantToOrderRoles(int $menuId): void
    {
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

    public function down(): void
    {
        $names = ['delivery', 'delivery.picking', 'delivery.pick', 'delivery.check', 'delivery.load', 'delivery.task', 'delivery.collection', 'delivery.remit'];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
