<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「总账管理」一级菜单 + 八个子菜单 + 权限节点，并给 super_admin 默认授权。
 *
 * 关键约定（与 BusinessSeeder 一致，避免踩坑）：
 *  - 图标必须带 ElIcon 前缀（boot.js 只注册 ElIcon 与 AIcon 前缀），否则侧边栏渲染空 el-icon。
 *  - 顶级 path 必须指向第一个子菜单 /ledger/subject，绝不能留空字符串：
 *    前端 transformMenusToRoutes 用 filter(menu => menu && menu.path) 过滤，
 *    path='' 的顶级菜单连同整棵子树会被丢弃（车销菜单踩过此坑）。
 *  - 分组菜单 component 必须为空：子路由以绝对路径挂在分组下，分组自带 component
 *    会让子页面再渲染一层 <router-view>，没有就出不来。
 *  - AuthSeeder 会 Permission::truncate()，所以菜单最终在 LedgerSeeder 里重建；
 *    本 migration 用于已部署环境在 seed 之前刷新菜单与权限节点。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            // 顶级分组：path 指向第一个子菜单 /ledger/subject，component 为空
            ['name' => 'ledger', 'title' => '总账管理', 'parent_name' => null, 'path' => '/ledger/subject', 'component' => '', 'sort' => 13, 'icon' => 'ElIconNotebook'],
            // 总账驾驶舱排首（A8 首页 dashboard），其余九个子菜单顺序固定
            ['name' => 'ledger.dashboard', 'title' => '总账驾驶舱', 'parent_name' => 'ledger', 'path' => '/ledger/dashboard', 'component' => 'ledger/dashboard/index', 'sort' => 1, 'icon' => null],
            ['name' => 'ledger.subject', 'title' => '会计科目', 'parent_name' => 'ledger', 'path' => '/ledger/subject', 'component' => 'ledger/subject/index', 'sort' => 2, 'icon' => null],
            ['name' => 'ledger.opening', 'title' => '期初余额', 'parent_name' => 'ledger', 'path' => '/ledger/opening', 'component' => 'ledger/opening/index', 'sort' => 3, 'icon' => null],
            ['name' => 'ledger.voucher', 'title' => '记账凭证', 'parent_name' => 'ledger', 'path' => '/ledger/voucher', 'component' => 'ledger/voucher/index', 'sort' => 4, 'icon' => null],
            ['name' => 'ledger.voucher-query', 'title' => '凭证查询', 'parent_name' => 'ledger', 'path' => '/ledger/voucher-query', 'component' => 'ledger/voucher-query/index', 'sort' => 5, 'icon' => null],
            ['name' => 'ledger.voucher-audit', 'title' => '凭证审核', 'parent_name' => 'ledger', 'path' => '/ledger/voucher-audit', 'component' => 'ledger/voucher-audit/index', 'sort' => 6, 'icon' => null],
            ['name' => 'ledger.period', 'title' => '财务月结', 'parent_name' => 'ledger', 'path' => '/ledger/period', 'component' => 'ledger/period/index', 'sort' => 7, 'icon' => null],
            ['name' => 'ledger.balance', 'title' => '科目余额表', 'parent_name' => 'ledger', 'path' => '/ledger/balance', 'component' => 'ledger/balance/index', 'sort' => 8, 'icon' => null],
            ['name' => 'ledger.report', 'title' => '财务报表', 'parent_name' => 'ledger', 'path' => '/ledger/report', 'component' => 'ledger/report/index', 'sort' => 9, 'icon' => null],
        ];

        $idMap = [];
        foreach ($menus as $menu) {
            $exists = DB::table('auth_permission')->where('name', $menu['name'])->exists();
            $pid = $menu['parent_name'] === null ? 0 : ($idMap[$menu['parent_name']] ?? 0);
            // 与 LedgerSeeder 同步：icon 与 hidden 合并写入，不能二选一丢键
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

        // 权限节点（按钮）：查询/新增/编辑/删除/审核/导出，挂在各子页面菜单下
        $buttons = [
            ['name' => 'ledger.subject.view', 'title' => '查看科目', 'parent_name' => 'ledger.subject', 'path' => 'ledger.subject.view', 'sort' => 1],
            ['name' => 'ledger.subject.create', 'title' => '新增科目', 'parent_name' => 'ledger.subject', 'path' => 'ledger.subject.create', 'sort' => 2],
            ['name' => 'ledger.subject.update', 'title' => '编辑科目', 'parent_name' => 'ledger.subject', 'path' => 'ledger.subject.update', 'sort' => 3],
            ['name' => 'ledger.subject.delete', 'title' => '删除科目', 'parent_name' => 'ledger.subject', 'path' => 'ledger.subject.delete', 'sort' => 4],
            ['name' => 'ledger.subject.export', 'title' => '导出科目', 'parent_name' => 'ledger.subject', 'path' => 'ledger.subject.export', 'sort' => 5],
            ['name' => 'ledger.opening.update', 'title' => '录入期初', 'parent_name' => 'ledger.opening', 'path' => 'ledger.opening.update', 'sort' => 1],
            ['name' => 'ledger.voucher.view', 'title' => '查看凭证', 'parent_name' => 'ledger.voucher', 'path' => 'ledger.voucher.view', 'sort' => 1],
            ['name' => 'ledger.voucher.create', 'title' => '新增凭证', 'parent_name' => 'ledger.voucher', 'path' => 'ledger.voucher.create', 'sort' => 2],
            ['name' => 'ledger.voucher.update', 'title' => '编辑凭证', 'parent_name' => 'ledger.voucher', 'path' => 'ledger.voucher.update', 'sort' => 3],
            ['name' => 'ledger.voucher.delete', 'title' => '删除凭证', 'parent_name' => 'ledger.voucher', 'path' => 'ledger.voucher.delete', 'sort' => 4],
            ['name' => 'ledger.voucher.audit', 'title' => '审核凭证', 'parent_name' => 'ledger.voucher', 'path' => 'ledger.voucher.audit', 'sort' => 5],
            ['name' => 'ledger.voucher.reverse', 'title' => '作废凭证', 'parent_name' => 'ledger.voucher', 'path' => 'ledger.voucher.reverse', 'sort' => 6],
            ['name' => 'ledger.voucher.export', 'title' => '导出凭证', 'parent_name' => 'ledger.voucher', 'path' => 'ledger.voucher.export', 'sort' => 7],
            ['name' => 'ledger.period.view', 'title' => '查看月结', 'parent_name' => 'ledger.period', 'path' => 'ledger.period.view', 'sort' => 1],
            ['name' => 'ledger.period.close', 'title' => '月结', 'parent_name' => 'ledger.period', 'path' => 'ledger.period.close', 'sort' => 2],
            ['name' => 'ledger.period.reopen', 'title' => '反结账', 'parent_name' => 'ledger.period', 'path' => 'ledger.period.reopen', 'sort' => 3],
            ['name' => 'ledger.balance.view', 'title' => '查看余额表', 'parent_name' => 'ledger.balance', 'path' => 'ledger.balance.view', 'sort' => 1],
            ['name' => 'ledger.balance.export', 'title' => '导出余额表', 'parent_name' => 'ledger.balance', 'path' => 'ledger.balance.export', 'sort' => 2],
            ['name' => 'ledger.report.view', 'title' => '查看报表', 'parent_name' => 'ledger.report', 'path' => 'ledger.report.view', 'sort' => 1],
            ['name' => 'ledger.report.export', 'title' => '导出报表', 'parent_name' => 'ledger.report', 'path' => 'ledger.report.export', 'sort' => 2],
        ];

        foreach ($buttons as $btn) {
            $exists = DB::table('auth_permission')->where('name', $btn['name'])->exists();
            $pid = $idMap[$btn['parent_name']] ?? 0;
            if (! $exists) {
                $id = DB::table('auth_permission')->insertGetId([
                    'title' => $btn['title'],
                    'name' => $btn['name'],
                    'type' => 'button',
                    'parent_id' => $pid,
                    'path' => $btn['path'],
                    'component' => null,
                    'meta' => null,
                    'sort' => $btn['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $idMap[$btn['name']] = $id;
            } else {
                $idMap[$btn['name']] = DB::table('auth_permission')->where('name', $btn['name'])->value('id');
            }
        }

        // 给 super_admin 角色默认授权全部菜单与按钮
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
            'ledger', 'ledger.subject', 'ledger.opening', 'ledger.voucher', 'ledger.voucher-query',
            'ledger.voucher-audit', 'ledger.period', 'ledger.balance', 'ledger.report',
            'ledger.subject.view', 'ledger.subject.create', 'ledger.subject.update', 'ledger.subject.delete', 'ledger.subject.export',
            'ledger.opening.update',
            'ledger.voucher.view', 'ledger.voucher.create', 'ledger.voucher.update', 'ledger.voucher.delete', 'ledger.voucher.audit', 'ledger.voucher.reverse', 'ledger.voucher.export',
            'ledger.period.view', 'ledger.period.close', 'ledger.period.reopen',
            'ledger.balance.view', 'ledger.balance.export',
            'ledger.report.view', 'ledger.report.export',
        ];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
