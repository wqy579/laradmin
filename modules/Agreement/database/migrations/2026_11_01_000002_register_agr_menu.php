<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「协议管理」一级菜单 + F4 权限节点，并给 super_admin 默认授权。
 * 约定与 register_hr_menu 完全一致；sort=16 紧跟薪酬管理(15)。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            ['name' => 'agr', 'title' => '协议管理', 'parent_name' => null, 'path' => '/agr/list', 'component' => '', 'sort' => 16, 'icon' => 'ElIconDocument'],
            ['name' => 'agr.list', 'title' => '协议档案', 'parent_name' => 'agr', 'path' => '/agr/list', 'component' => 'agr/list/index', 'sort' => 1, 'icon' => null],
            ['name' => 'agr.stats', 'title' => '陈列协议统计', 'parent_name' => 'agr', 'path' => '/agr/stats', 'component' => 'agr/stats/index', 'sort' => 2, 'icon' => null],
        ];

        $idMap = [];
        foreach ($menus as $menu) {
            $pid = $menu['parent_name'] === null ? 0 : ($idMap[$menu['parent_name']] ?? 0);
            $meta = array_merge(
                ['hidden' => false, 'hiddenBreadcrumb' => false],
                $menu['icon'] !== null ? ['icon' => $menu['icon']] : []
            );

            if ($exists = DB::table('auth_permission')->where('name', $menu['name'])->first()) {
                $idMap[$menu['name']] = $exists->id;

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
            ['name' => 'agr.list.view', 'alias' => 'agr:list:view', 'title' => '查看协议档案', 'parent_name' => 'agr.list', 'sort' => 1],
            ['name' => 'agr.list.create', 'alias' => 'agr:list:create', 'title' => '新增协议', 'parent_name' => 'agr.list', 'sort' => 2],
            ['name' => 'agr.list.edit', 'alias' => 'agr:list:edit', 'title' => '编辑协议', 'parent_name' => 'agr.list', 'sort' => 3],
            ['name' => 'agr.list.terminate', 'alias' => 'agr:list:terminate', 'title' => '终止协议', 'parent_name' => 'agr.list', 'sort' => 4],
            ['name' => 'agr.list.redeem', 'alias' => 'agr:list:redeem', 'title' => '登记兑现', 'parent_name' => 'agr.list', 'sort' => 5],
            ['name' => 'agr.list.export', 'alias' => 'agr:list:export', 'title' => '导出协议', 'parent_name' => 'agr.list', 'sort' => 6],
            ['name' => 'agr.stats.view', 'alias' => 'agr:stats:view', 'title' => '查看陈列协议统计', 'parent_name' => 'agr.stats', 'sort' => 1],
            ['name' => 'agr.stats.export', 'alias' => 'agr:stats:export', 'title' => '导出陈列协议统计', 'parent_name' => 'agr.stats', 'sort' => 2],
        ];

        foreach ($buttons as $btn) {
            $pid = $idMap[$btn['parent_name']] ?? 0;
            foreach (array_filter([$btn['name'], $btn['alias']]) as $name) {
                if ($exists = DB::table('auth_permission')->where('name', $name)->first()) {
                    $idMap[$name] = $exists->id;

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
            'agr', 'agr.list', 'agr.stats',
            'agr.list.view', 'agr:list:view',
            'agr.list.create', 'agr:list:create',
            'agr.list.edit', 'agr:list:edit',
            'agr.list.terminate', 'agr:list:terminate',
            'agr.list.redeem', 'agr:list:redeem',
            'agr.list.export', 'agr:list:export',
            'agr.stats.view', 'agr:stats:view',
            'agr.stats.export', 'agr:stats:export',
        ];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
