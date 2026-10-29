<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「薪酬管理」一级菜单 + F4 权限节点，并给 super_admin 默认授权。
 *
 * 与 Cash 模块菜单迁移同一套约定：
 *  - 幂等：已存在的 name 只回填 id，不重复插入，可反复执行。
 *  - 权限点同时登记点号名与冒号别名，前端 v-permission 与后端授权各命中一种写法。
 *  - HrSeeder 里有一份完全一致的数组，供全新安装重建（AuthSeeder 会 truncate 权限表）。
 *  - sort=15 排在 ledger(13)/cash(14) 之后，不动已有条目的 sort。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            ['name' => 'hr', 'title' => '薪酬管理', 'parent_name' => null, 'path' => '/hr/payroll', 'component' => '', 'sort' => 15, 'icon' => 'ElIconWallet'],
            ['name' => 'hr.payroll', 'title' => '工资核算', 'parent_name' => 'hr', 'path' => '/hr/payroll', 'component' => 'hr/payroll/index', 'sort' => 1, 'icon' => null],
            ['name' => 'hr.commission-flow', 'title' => '提成流水', 'parent_name' => 'hr', 'path' => '/hr/commission-flow', 'component' => 'hr/commission-flow/index', 'sort' => 2, 'icon' => null],
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
            ['name' => 'hr.payroll.view', 'alias' => 'hr:payroll:view', 'title' => '查看工资核算', 'parent_name' => 'hr.payroll', 'sort' => 1],
            ['name' => 'hr.payroll.create', 'alias' => 'hr:payroll:create', 'title' => '生成工资单', 'parent_name' => 'hr.payroll', 'sort' => 2],
            ['name' => 'hr.payroll.edit', 'alias' => 'hr:payroll:edit', 'title' => '编辑工资单', 'parent_name' => 'hr.payroll', 'sort' => 3],
            ['name' => 'hr.payroll.pay', 'alias' => 'hr:payroll:pay', 'title' => '工资发放', 'parent_name' => 'hr.payroll', 'sort' => 4],
            ['name' => 'hr.payroll.export', 'alias' => 'hr:payroll:export', 'title' => '导出工资表', 'parent_name' => 'hr.payroll', 'sort' => 5],
            ['name' => 'hr.commission-flow.view', 'alias' => 'hr:commission-flow:view', 'title' => '查看提成流水', 'parent_name' => 'hr.commission-flow', 'sort' => 1],
            ['name' => 'hr.commission-flow.export', 'alias' => 'hr:commission-flow:export', 'title' => '导出提成流水', 'parent_name' => 'hr.commission-flow', 'sort' => 2],
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
            'hr', 'hr.payroll', 'hr.commission-flow',
            'hr.payroll.view', 'hr:payroll:view',
            'hr.payroll.create', 'hr:payroll:create',
            'hr.payroll.edit', 'hr:payroll:edit',
            'hr.payroll.pay', 'hr:payroll:pay',
            'hr.payroll.export', 'hr:payroll:export',
            'hr.commission-flow.view', 'hr:commission-flow:view',
            'hr.commission-flow.export', 'hr:commission-flow:export',
        ];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
