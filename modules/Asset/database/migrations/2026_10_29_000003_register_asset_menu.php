<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「固定资产」一级菜单 + 购置单/台账子菜单 + 权限节点，并给 super_admin 默认授权。
 *
 * 与 Cash/Ledger 菜单迁移同一套约定：
 *  - 图标必须带 ElIcon 前缀（boot.js 只注册 ElIcon 与 AIcon 前缀）。
 *  - 顶级 path 必须指向第一个子菜单，留空整棵子树会被前端丢弃。
 *  - 分组菜单 component 必须为空。
 *  - AuthSeeder 会 truncate auth_permission，菜单最终在 AssetSeeder 里重建；
 *    本 migration 用于已部署环境在 seed 之前刷新菜单与权限节点。
 *  - 前端静态路由 meta.perm 用冒号写法（asset:purchase / asset:view），
 *    权限节点同时登记点号与冒号两个 name，保证 v-permission 与后端授权都能命中。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            ['name' => 'asset', 'title' => '固定资产', 'parent_name' => null, 'path' => '/asset/purchase', 'component' => '', 'sort' => 15, 'icon' => 'ElIconBox'],
            ['name' => 'asset.purchase', 'title' => '资产购置单', 'parent_name' => 'asset', 'path' => '/asset/purchase', 'component' => 'asset/purchase/index', 'sort' => 1, 'icon' => null],
            ['name' => 'asset.card', 'title' => '固定资产台账', 'parent_name' => 'asset', 'path' => '/asset/card', 'component' => 'asset/card/index', 'sort' => 2, 'icon' => null],
            ['name' => 'asset.depreciation', 'title' => '资产折旧单', 'parent_name' => 'asset', 'path' => '/asset/depreciation', 'component' => 'asset/depreciation/index', 'sort' => 3, 'icon' => null],
            ['name' => 'asset.sale', 'title' => '资产变卖单', 'parent_name' => 'asset', 'path' => '/asset/sale', 'component' => 'asset/sale/index', 'sort' => 4, 'icon' => null],
        ];

        $idMap = [];
        foreach ($menus as $menu) {
            $exists = DB::table('auth_permission')->where('name', $menu['name'])->exists();
            $pid = $menu['parent_name'] === null ? 0 : ($idMap[$menu['parent_name']] ?? 0);
            $meta = array_merge(
                ['hidden' => false, 'hiddenBreadcrumb' => false],
                $menu['icon'] !== null ? ['icon' => $menu['icon']] : []
            );

            if ($exists) {
                $idMap[$menu['name']] = DB::table('auth_permission')->where('name', $menu['name'])->value('id');

                continue;
            }

            $idMap[$menu['name']] = DB::table('auth_permission')->insertGetId([
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
        }

        $buttons = [
            ['name' => 'asset.purchase.view', 'alias' => 'asset:purchase', 'title' => '查看购置单', 'parent_name' => 'asset.purchase', 'sort' => 1],
            ['name' => 'asset.purchase.create', 'alias' => 'asset:purchase:create', 'title' => '新增购置单', 'parent_name' => 'asset.purchase', 'sort' => 2],
            ['name' => 'asset.card.view', 'alias' => 'asset:view', 'title' => '查看资产台账', 'parent_name' => 'asset.card', 'sort' => 1],
            ['name' => 'asset.depreciation.view', 'alias' => 'asset:depreciate', 'title' => '查看折旧单', 'parent_name' => 'asset.depreciation', 'sort' => 1],
            ['name' => 'asset.depreciation.run', 'alias' => 'asset:depreciate:run', 'title' => '计提折旧', 'parent_name' => 'asset.depreciation', 'sort' => 2],
            ['name' => 'asset.sale.view', 'alias' => 'asset:sale', 'title' => '查看变卖单', 'parent_name' => 'asset.sale', 'sort' => 1],
            ['name' => 'asset.sale.create', 'alias' => 'asset:sale:create', 'title' => '新增变卖单', 'parent_name' => 'asset.sale', 'sort' => 2],
        ];

        foreach ($buttons as $btn) {
            $pid = $idMap[$btn['parent_name']] ?? 0;
            foreach (array_filter([$btn['name'], $btn['alias']]) as $name) {
                if (DB::table('auth_permission')->where('name', $name)->exists()) {
                    $idMap[$name] = DB::table('auth_permission')->where('name', $name)->value('id');

                    continue;
                }
                $idMap[$name] = DB::table('auth_permission')->insertGetId([
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
            'asset', 'asset.purchase', 'asset.card', 'asset.depreciation', 'asset.sale',
            'asset.purchase.view', 'asset:purchase', 'asset.purchase.create',
            'asset:purchase:create', 'asset.card.view', 'asset:view',
            'asset.depreciation.view', 'asset:depreciate', 'asset.depreciation.run', 'asset:depreciate:run',
            'asset.sale.view', 'asset:sale', 'asset.sale.create', 'asset:sale:create',
        ];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
