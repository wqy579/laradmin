<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「出纳管理」一级菜单 + 两个子菜单 + 权限节点，并给 super_admin 默认授权。
 *
 * 关键约定（与 Ledger 菜单迁移一致）：
 *  - 图标必须带 ElIcon 前缀（boot.js 只注册 ElIcon 与 AIcon 前缀）。
 *  - 顶级 path 必须指向第一个子菜单 /cash/account-flow，留空整棵子树会被前端丢弃。
 *  - 分组菜单 component 必须为空。
 *  - AuthSeeder 会 Permission::truncate()，菜单最终在 CashSeeder 里重建；
 *    本 migration 用于已部署环境在 seed 之前刷新菜单与权限节点。
 *  - 静态路由 meta.perm 用的是 cash:query / cash:card:view 冒号写法，
 *    权限节点同时登记点号与冒号两个 name，保证 v-permission 与后端授权都能命中。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            ['name' => 'cash', 'title' => '出纳管理', 'parent_name' => null, 'path' => '/cash/account-flow', 'component' => '', 'sort' => 14, 'icon' => 'ElIconMoney'],
            ['name' => 'cash.account-flow', 'title' => '现金账户', 'parent_name' => 'cash', 'path' => '/cash/account-flow', 'component' => 'cash/account-flow/index', 'sort' => 1, 'icon' => null],
            ['name' => 'cash.card', 'title' => '刷卡记录', 'parent_name' => 'cash', 'path' => '/cashier/card', 'component' => 'cashier/card/index', 'sort' => 2, 'icon' => null],
        ];

        $idMap = [];
        foreach ($menus as $menu) {
            $exists = DB::table('auth_permission')->where('name', $menu['name'])->exists();
            $pid = $menu['parent_name'] === null ? 0 : ($idMap[$menu['parent_name']] ?? 0);
            $meta = array_merge(
                ['hidden' => false, 'hiddenBreadcrumb' => false],
                $menu['icon'] !== null ? ['icon' => $menu['icon']] : []
            );

            if (! $exists) {
                $id = DB::table('auth_permission')->insertGetId([
                    'title' => $menu['title'],
                    'name' => $menu['name'],
                    'type' => 'menu',
                    'parent_id' => $pid,
                    'path' => $menu['path'],
                    'component' => $menu['component'] === '' ? null : $menu['component'],
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'sort' => $menu['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $idMap[$menu['name']] = $id;
            } else {
                $idMap[$menu['name']] = DB::table('auth_permission')->where('name', $menu['name'])->value('id');
            }
        }

        $buttons = [
            ['name' => 'cash.account-flow.view', 'alias' => 'cash:query', 'title' => '查询账户收支', 'parent_name' => 'cash.account-flow', 'sort' => 1],
            ['name' => 'cash.account-flow.export', 'alias' => null, 'title' => '导出账户收支', 'parent_name' => 'cash.account-flow', 'sort' => 2],
            ['name' => 'cash.card.view', 'alias' => 'cash:card:view', 'title' => '查看刷卡记录', 'parent_name' => 'cash.card', 'sort' => 1],
            ['name' => 'cash.card.refund', 'alias' => null, 'title' => '刷卡退款', 'parent_name' => 'cash.card', 'sort' => 2],
            ['name' => 'cash.card.export', 'alias' => null, 'title' => '导出刷卡记录', 'parent_name' => 'cash.card', 'sort' => 3],
        ];

        foreach ($buttons as $btn) {
            $pid = $idMap[$btn['parent_name']] ?? 0;
            foreach (array_filter([$btn['name'], $btn['alias']]) as $name) {
                if (DB::table('auth_permission')->where('name', $name)->exists()) {
                    $idMap[$name] = DB::table('auth_permission')->where('name', $name)->value('id');

                    continue;
                }
                $id = DB::table('auth_permission')->insertGetId([
                    'title' => $btn['title'],
                    'name' => $name,
                    'type' => 'button',
                    'parent_id' => $pid,
                    'path' => $name,
                    'component' => null,
                    'meta' => null,
                    'sort' => $btn['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $idMap[$name] = $id;
            }
        }

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        if ($superRoleId) {
            foreach ($idMap as $permissionId) {
                $has = DB::table('auth_role_permission')
                    ->where('role_id', $superRoleId)
                    ->where('permission_id', $permissionId)
                    ->exists();
                if (! $has) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $superRoleId,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $names = [
            'cash', 'cash.account-flow', 'cash.card',
            'cash.account-flow.view', 'cash:query', 'cash.account-flow.export',
            'cash.card.view', 'cash:card:view', 'cash.card.refund', 'cash.card.export',
        ];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
