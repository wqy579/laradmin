<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「出纳管理」下的积分核销 / 积分查询子菜单 + 权限节点，并给 super_admin 默认授权。
 *
 * 约定与 2026_10_30_000104_register_cash_integral_menu（D5）完全一致：
 *  - 权限节点双写：点号名（v-permission / 后端）+ 冒号别名（路由 meta.perm）。
 *  - 静态路由 frontend/src/router/modules/points.js 的 meta.perm 用的是
 *    菜单级冒号写法 cash:integral:use / cash:integral:query，
 *    因此这两个裸冒号节点也要登记，否则页面级权限判定不命中。
 *  - 顶级 cash 菜单由 D1 迁移创建；sort 5/6 已被 D5 的积分规则/发放占用，
 *    本迁移只新增 sort 7/8，不动别人的 sort 与名称。
 *  - AuthSeeder 会 truncate auth_permission，最终形态由 CashSeeder 重建；
 *    本迁移用于已部署环境在 seed 之前刷新菜单与权限节点。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            ['name' => 'cash.integral-use', 'title' => '积分核销', 'parent_name' => 'cash', 'path' => '/points/use', 'component' => 'points/use/index', 'sort' => 7, 'icon' => null],
            ['name' => 'cash.integral-query', 'title' => '积分查询', 'parent_name' => 'cash', 'path' => '/points/query', 'component' => 'points/query/index', 'sort' => 8, 'icon' => null],
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

        $nodes = [];
        $useVerbs = ['view' => '查询', 'create' => '新增', 'edit' => '编辑', 'delete' => '删除', 'audit' => '审核', 'export' => '导出'];
        $nodes[] = ['name' => 'cash:integral:use', 'title' => '查看积分核销', 'parent_name' => 'cash.integral-use', 'sort' => 0];
        $sort = 1;
        foreach ($useVerbs as $verb => $verbTitle) {
            $nodes[] = ['name' => 'cash.integral.use.'.$verb, 'title' => $verbTitle.'积分核销', 'parent_name' => 'cash.integral-use', 'sort' => $sort++];
            $nodes[] = ['name' => 'cash:integral:use:'.$verb, 'title' => $verbTitle.'积分核销', 'parent_name' => 'cash.integral-use', 'sort' => $sort++];
        }

        $nodes[] = ['name' => 'cash:integral:query', 'title' => '查看积分查询', 'parent_name' => 'cash.integral-query', 'sort' => 0];
        $sort = 1;
        foreach (['view' => '查询', 'export' => '导出'] as $verb => $verbTitle) {
            $nodes[] = ['name' => 'cash.integral.query.'.$verb, 'title' => $verbTitle.'积分查询', 'parent_name' => 'cash.integral-query', 'sort' => $sort++];
            $nodes[] = ['name' => 'cash:integral:query:'.$verb, 'title' => $verbTitle.'积分查询', 'parent_name' => 'cash.integral-query', 'sort' => $sort++];
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
        $names = ['cash.integral-use', 'cash.integral-query', 'cash:integral:use', 'cash:integral:query'];
        foreach (['use' => ['view', 'create', 'edit', 'delete', 'audit', 'export'], 'query' => ['view', 'export']] as $domain => $verbs) {
            foreach ($verbs as $verb) {
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
