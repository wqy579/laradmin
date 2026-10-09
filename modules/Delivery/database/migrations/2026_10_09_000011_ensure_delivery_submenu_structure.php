<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 自愈「配送管理」菜单结构 + 清权限缓存。
 *
 * 背景：2026_10_09_000008_register_delivery_menu 注册了 delivery 顶级菜单 + 7 子菜单，
 * BusinessSeeder 也同步定义。代码层面正确，但线上出现「配送管理点击直接跳配货单、
 * 不展开子菜单」的现象，根因有二：
 *
 * 1. 子菜单 parent_id 未正确指向 delivery 顶级菜单（迁移与 seeder 先后/并发跑过），
 *    或 component/status 字段缺失，导致 AuthService::buildMenuTree 构建不出 children，
 *    前端 menu-item.vue 因 children 为空把顶级菜单渲染成 el-menu-item 直接跳转。
 *
 * 2. PermissionCacheService 对用户菜单树缓存 60 分钟，但全仓无任何调用方在菜单变更后
 *    清缓存，导致迁移跑过、权限分配过，用户拿到的仍是旧菜单树（无 delivery 子菜单）。
 *
 * 本迁移幂等修正 8 条菜单记录的字段，补角色授权，并在末尾 Cache::flush() 清缓存——
 * 缓存重建成本低（下次请求自动重建），相比菜单不显示的影响可忽略。
 */
return new class extends Migration
{
    private const TOP_MENU = [
        'name' => 'delivery',
        'title' => '配送管理',
        'path' => '/business/delivery-picking',
        'meta' => ['icon' => 'ElIconVan'],
        'sort' => 11,
    ];

    private const SUB_MENUS = [
        ['name' => 'delivery.picking', 'title' => '配货单', 'path' => '/business/delivery-picking', 'component' => 'business/delivery/picking/index', 'sort' => 1],
        ['name' => 'delivery.pick', 'title' => '拣货单', 'path' => '/business/delivery-pick', 'component' => 'business/delivery/pick/index', 'sort' => 2],
        ['name' => 'delivery.check', 'title' => '验货单', 'path' => '/business/delivery-check', 'component' => 'business/delivery/check/index', 'sort' => 3],
        ['name' => 'delivery.load', 'title' => '装车单', 'path' => '/business/delivery-load', 'component' => 'business/delivery/load/index', 'sort' => 4],
        ['name' => 'delivery.task', 'title' => '配送任务', 'path' => '/business/delivery-task', 'component' => 'business/delivery/task/index', 'sort' => 5],
        ['name' => 'delivery.collection', 'title' => '配送收款', 'path' => '/business/delivery-collection', 'component' => 'business/delivery/collection/index', 'sort' => 6],
        ['name' => 'delivery.remit', 'title' => '上交货款', 'path' => '/business/delivery-remit', 'component' => 'business/delivery/remit/index', 'sort' => 7],
    ];

    public function up(): void
    {
        // 1. 顶级菜单：不存在则建，存在则修正关键字段
        $topExists = DB::table('auth_permission')->where('name', self::TOP_MENU['name'])->exists();
        if (! $topExists) {
            DB::table('auth_permission')->insert([
                'title' => self::TOP_MENU['title'],
                'name' => self::TOP_MENU['name'],
                'type' => 'menu',
                'parent_id' => 0,
                'path' => self::TOP_MENU['path'],
                'component' => null,
                'meta' => json_encode(self::TOP_MENU['meta']),
                'sort' => self::TOP_MENU['sort'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('auth_permission')->where('name', self::TOP_MENU['name'])->update([
                'title' => self::TOP_MENU['title'],
                'type' => 'menu',
                'parent_id' => 0,
                'path' => self::TOP_MENU['path'],
                'component' => null,
                'meta' => json_encode(self::TOP_MENU['meta']),
                'sort' => self::TOP_MENU['sort'],
                'status' => 1,
                'updated_at' => now(),
            ]);
        }

        $parentId = DB::table('auth_permission')->where('name', self::TOP_MENU['name'])->value('id');

        // 2. 7 子菜单：不存在则建，存在则修正 parent_id/component/status 等关键字段
        foreach (self::SUB_MENUS as $menu) {
            $exists = DB::table('auth_permission')->where('name', $menu['name'])->exists();
            if (! $exists) {
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
            } else {
                DB::table('auth_permission')->where('name', $menu['name'])->update([
                    'title' => $menu['title'],
                    'type' => 'menu',
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                    'status' => 1,
                    'updated_at' => now(),
                ]);
            }
        }

        // 3. 授权：顶级 + 7 子菜单都授权给已拥有 order 菜单的角色
        $allMenuIds = collect(self::SUB_MENUS)->map(fn ($m) => DB::table('auth_permission')->where('name', $m['name'])->value('id'))->all();
        $allMenuIds[] = $parentId;
        foreach ($allMenuIds as $menuId) {
            $this->grantToOrderRoles($menuId);
        }

        // 4. 清权限缓存：菜单树缓存 60 分钟且全仓无清理调用方，
        //    迁移跑过但用户拿旧菜单树的直接原因即此。flush 后下次请求自动重建。
        Cache::flush();
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
        // 不删除菜单（菜单是 seeder 资产），down 只清缓存恢复运行时状态
        Cache::flush();
    }
};
