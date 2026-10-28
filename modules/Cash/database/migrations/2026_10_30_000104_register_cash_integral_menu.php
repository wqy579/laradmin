<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「出纳管理」下的积分规则 / 积分发放子菜单 + 权限节点，并给 super_admin 默认授权。
 *
 * 约定与 2026_10_28_000004_register_cash_menu 一致：
 *  - 权限节点双写：点号名（v-permission / 后端）+ 冒号别名（路由 meta.perm）。
 *  - 静态路由 frontend/src/router/modules/points.js 的 meta.perm 用的是
 *    菜单级冒号写法 cash:integral:rule / cash:integral:grant，
 *    因此这两个裸冒号节点也要登记，否则页面级权限判定不命中。
 *  - 顶级 cash 菜单由 D1 迁移创建，本迁移只挂子菜单；parent 查找容忍已存在。
 *  - AuthSeeder 会 truncate auth_permission，最终形态由 CashSeeder 重建；
 *    本迁移用于已部署环境在 seed 之前刷新菜单与权限节点。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            ['name' => 'cash.integral-rule', 'title' => '积分规则', 'parent_name' => 'cash', 'path' => '/points/rule', 'component' => 'points/rule/index', 'sort' => 5, 'icon' => null],
            ['name' => 'cash.integral-grant', 'title' => '积分发放', 'parent_name' => 'cash', 'path' => '/points/grant', 'component' => 'points/grant/index', 'sort' => 6, 'icon' => null],
        ];

        $idMap = [];
        foreach ($menus as $menu) {
            if (DB::table('auth_permission')->where('name', $menu['name'])->exists()) {
                $idMap[$menu['name']] = DB::table('auth_permission')->where('name', $menu['name'])->value('id');

                continue;
            }
            $pid = $menu['parent_name'] === null ? 0 : DB::table('auth_permission')->where('name', $menu['parent_name'])->value('id');
            $meta = ['hidden' => false, 'hiddenBreadcrumb' => false];
            $idMap[$menu['name']] = DB::table('auth_permission')->insertGetId([
                'title' => $menu['title'],
                'name' => $menu['name'],
                'type' => 'menu',
                'parent_id' => $pid ?? 0,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'sort' => $menu['sort'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $verbs = ['view' => '查询', 'create' => '新增', 'edit' => '编辑', 'delete' => '删除', 'audit' => '审核', 'export' => '导出'];
        $nodes = [];
        foreach (['rule' => ['title' => '积分规则', 'menu' => 'cash.integral-rule'], 'grant' => ['title' => '积分发放', 'menu' => 'cash.integral-grant']] as $domain => $info) {
            $nodes[] = ['name' => 'cash:integral:'.$domain, 'title' => '查看'.$info['title'], 'parent_name' => $info['menu'], 'sort' => 0];
            $sort = 1;
            foreach ($verbs as $verb => $verbTitle) {
                $nodes[] = ['name' => 'cash.integral.'.$domain.'.'.$verb, 'title' => $verbTitle.$info['title'], 'parent_name' => $info['menu'], 'sort' => $sort++];
                $nodes[] = ['name' => 'cash:integral:'.$domain.':'.$verb, 'title' => $verbTitle.$info['title'], 'parent_name' => $info['menu'], 'sort' => $sort++];
            }
        }

        foreach ($nodes as $node) {
            if (DB::table('auth_permission')->where('name', $node['name'])->exists()) {
                $idMap[$node['name']] = DB::table('auth_permission')->where('name', $node['name'])->value('id');

                continue;
            }
            $pid = $idMap[$node['parent_name']] ?? DB::table('auth_permission')->where('name', $node['parent_name'])->value('id');
            $idMap[$node['name']] = DB::table('auth_permission')->insertGetId([
                'title' => $node['title'],
                'name' => $node['name'],
                'type' => 'button',
                'parent_id' => $pid ?? 0,
                'path' => $node['name'],
                'component' => null,
                'meta' => null,
                'sort' => $node['sort'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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
        $names = ['cash.integral-rule', 'cash.integral-grant', 'cash:integral:rule', 'cash:integral:grant'];
        foreach (['rule', 'grant'] as $domain) {
            foreach (['view', 'create', 'edit', 'delete', 'audit', 'export'] as $verb) {
                $names[] = 'cash.integral.'.$domain.'.'.$verb;
                $names[] = 'cash:integral:'.$domain.':'.$verb;
            }
        }
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
