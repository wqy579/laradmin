<?php

namespace Modules\Ledger\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 总账管理模块种子编排：菜单 + 权限节点 + super_admin 授权 + 预置科目。
 *
 * 顺序约束：AuthSeeder 会 Permission::truncate() auth_permission，所以本 seeder
 * 必须排在 AuthSeeder 之后重建菜单与权限节点；预置科目不依赖 auth_permission，
 * 但统一在这里调用以保持编排集中。
 */
class LedgerSeeder extends Seeder
{
    public function run(): void
    {
        $this->registerMenus();
        $this->registerButtons();
        $this->grantToSuperAdmin();
        $this->call([
            LedgerSubjectSeeder::class,
        ]);
    }

    /** 顶级 + 子菜单：与 2026_10_13_000006_register_ledger_menu 保持一致 */
    private function registerMenus(): void
    {
        $menus = [
            ['name' => 'ledger', 'title' => '总账管理', 'parent_name' => null, 'path' => '/ledger/subject', 'component' => null, 'sort' => 13, 'icon' => 'ElIconNotebook'],
            ['name' => 'ledger.subject', 'title' => '会计科目', 'parent_name' => 'ledger', 'path' => '/ledger/subject', 'component' => 'ledger/subject/index', 'sort' => 1, 'icon' => null],
            ['name' => 'ledger.opening', 'title' => '期初余额', 'parent_name' => 'ledger', 'path' => '/ledger/opening', 'component' => 'ledger/opening/index', 'sort' => 2, 'icon' => null],
            ['name' => 'ledger.voucher', 'title' => '记账凭证', 'parent_name' => 'ledger', 'path' => '/ledger/voucher', 'component' => 'ledger/voucher/index', 'sort' => 3, 'icon' => null],
            ['name' => 'ledger.voucher-query', 'title' => '凭证查询', 'parent_name' => 'ledger', 'path' => '/ledger/voucher-query', 'component' => 'ledger/voucher-query/index', 'sort' => 4, 'icon' => null],
            ['name' => 'ledger.voucher-audit', 'title' => '凭证审核', 'parent_name' => 'ledger', 'path' => '/ledger/voucher-audit', 'component' => 'ledger/voucher-audit/index', 'sort' => 5, 'icon' => null],
            ['name' => 'ledger.period', 'title' => '财务月结', 'parent_name' => 'ledger', 'path' => '/ledger/period', 'component' => 'ledger/period/index', 'sort' => 6, 'icon' => null],
            ['name' => 'ledger.balance', 'title' => '科目余额表', 'parent_name' => 'ledger', 'path' => '/ledger/balance', 'component' => 'ledger/balance/index', 'sort' => 7, 'icon' => null],
            ['name' => 'ledger.report', 'title' => '财务报表', 'parent_name' => 'ledger', 'path' => '/ledger/report', 'component' => 'ledger/report/index', 'sort' => 8, 'icon' => null],
        ];

        $idMap = [];
        foreach ($menus as $menu) {
            $pid = $menu['parent_name'] === null ? 0 : ($idMap[$menu['parent_name']] ?? 0);
            // meta 合并而不是二选一：icon 与 hidden 两组键都要在，否则带图标的顶级菜单
            // 会丢掉 hidden:false（2026-10 验收缺陷②——菜单父节点被过滤）
            $meta = array_merge(
                ['hidden' => false, 'hiddenBreadcrumb' => false],
                $menu['icon'] !== null ? ['icon' => $menu['icon']] : []
            );
            $perm = DB::table('auth_permission')->where('name', $menu['name'])->first();
            $fields = [
                'title' => $menu['title'],
                'name' => $menu['name'],
                'type' => 'menu',
                'parent_id' => $pid,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'sort' => $menu['sort'],
                'status' => 1,
                'updated_at' => now(),
            ];
            if ($perm) {
                DB::table('auth_permission')->where('id', $perm->id)->update($fields);
                $idMap[$menu['name']] = $perm->id;
            } else {
                $idMap[$menu['name']] = DB::table('auth_permission')->insertGetId(array_merge($fields, ['created_at' => now()]));
            }
        }

        $this->menuIds = $idMap;
    }

    /** 按钮权限节点 */
    private function registerButtons(): void
    {
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
            $pid = $this->menuIds[$btn['parent_name']] ?? 0;
            $perm = DB::table('auth_permission')->where('name', $btn['name'])->first();
            $fields = [
                'title' => $btn['title'],
                'name' => $btn['name'],
                'type' => 'button',
                'parent_id' => $pid,
                'path' => $btn['path'],
                'component' => null,
                'meta' => null,
                'sort' => $btn['sort'],
                'status' => 1,
                'updated_at' => now(),
            ];
            if ($perm) {
                DB::table('auth_permission')->where('id', $perm->id)->update($fields);
                $this->menuIds[$btn['name']] = $perm->id;
            } else {
                $this->menuIds[$btn['name']] = DB::table('auth_permission')->insertGetId(array_merge($fields, ['created_at' => now()]));
            }
        }
    }

    /** super_admin 角色默认授权全部 */
    private function grantToSuperAdmin(): void
    {
        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        if (! $superRoleId) {
            return;
        }
        foreach ($this->menuIds as $permissionId) {
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

    private array $menuIds = [];
}
