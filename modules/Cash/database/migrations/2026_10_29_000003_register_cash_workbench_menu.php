<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「出纳工作台」子菜单 + D8 权限节点，并给 super_admin 默认授权。
 *
 * 与 2026_10_29_000002_register_cash_handover_menu 同一套约定：
 *  - 幂等：已存在的 name 只回填 id，不重复插入，可反复执行。
 *  - 顶级 cash 菜单在这里也登记一次（存在即复用），使本迁移不依赖前一个迁移是否跑过。
 *  - 权限点同时登记点号名与冒号别名，前端 v-permission 与后端授权各命中一种写法。
 *  - CashSeeder 里有一份完全一致的数组，供全新安装重建（AuthSeeder 会 truncate 权限表）。
 *  - sort=7 排在 cash 子菜单（1~6，含 D5 积分两项）之后，不动已有条目的 sort。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            ['name' => 'cash', 'title' => '出纳管理', 'parent_name' => null, 'path' => '/cash/account-flow', 'component' => '', 'sort' => 18, 'icon' => 'ElIconMoney'],
            ['name' => 'cash.workbench', 'title' => '出纳工作台', 'parent_name' => 'cash', 'path' => '/cash/workbench', 'component' => 'cash/workbench/index', 'sort' => 7, 'icon' => null],
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
            ['name' => 'cash.workbench.view', 'alias' => 'cash:workbench:view', 'title' => '查看出纳工作台', 'parent_name' => 'cash.workbench', 'sort' => 1],
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
            'cash.workbench',
            'cash.workbench.view', 'cash:workbench:view',
        ];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
