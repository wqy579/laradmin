<?php

namespace Modules\Hr\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 人事薪酬模块种子：菜单 + 权限节点 + super_admin 授权。
 *
 * 与 2026_11_01_000003_register_hr_menu 逐条一致；AuthSeeder 会 truncate
 * auth_permission，所以本 seeder 必须排在 AuthSeeder 之后重建（与 CashSeeder 同一约束）。
 */
class HrSeeder extends Seeder
{
    private array $menuIds = [];

    public function run(): void
    {
        $this->registerMenus();
        $this->registerButtons();
        $this->grantToSuperAdmin();
    }

    private function registerMenus(): void
    {
        $menus = [
            ['name' => 'hr', 'title' => '薪酬管理', 'parent_name' => null, 'path' => '/hr/payroll', 'component' => null, 'sort' => 15, 'icon' => 'ElIconWallet'],
            ['name' => 'hr.payroll', 'title' => '工资核算', 'parent_name' => 'hr', 'path' => '/hr/payroll', 'component' => 'hr/payroll/index', 'sort' => 1, 'icon' => null],
            ['name' => 'hr.commission-flow', 'title' => '提成流水', 'parent_name' => 'hr', 'path' => '/hr/commission-flow', 'component' => 'hr/commission-flow/index', 'sort' => 2, 'icon' => null],
        ];

        foreach ($menus as $menu) {
            $pid = $menu['parent_name'] === null ? 0 : ($this->menuIds[$menu['parent_name']] ?? 0);
            $meta = array_merge(
                ['hidden' => false, 'hiddenBreadcrumb' => false],
                $menu['icon'] !== null ? ['icon' => $menu['icon']] : []
            );
            $this->upsert('menu', $menu['name'], $menu['title'], $pid, $menu['path'], $menu['component'], json_encode($meta, JSON_UNESCAPED_UNICODE), $menu['sort']);
        }
    }

    private function registerButtons(): void
    {
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
            $pid = $this->menuIds[$btn['parent_name']] ?? 0;
            foreach (array_filter([$btn['name'], $btn['alias']]) as $name) {
                $this->upsert('button', $name, $btn['title'], $pid, $name, null, null, $btn['sort']);
            }
        }
    }

    private function upsert(string $type, string $name, string $title, int $pid, ?string $path, ?string $component, ?string $meta, int $sort): void
    {
        $perm = DB::table('auth_permission')->where('name', $name)->first();
        $fields = [
            'title' => $title,
            'name' => $name,
            'type' => $type,
            'parent_id' => $pid,
            'path' => $path,
            'component' => $component,
            'meta' => $meta,
            'sort' => $sort,
            'status' => 1,
            'updated_at' => now(),
        ];
        if ($perm) {
            DB::table('auth_permission')->where('id', $perm->id)->update($fields);
            $this->menuIds[$name] = $perm->id;
        } else {
            $this->menuIds[$name] = DB::table('auth_permission')->insertGetId(array_merge($fields, ['created_at' => now()]));
        }
    }

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
}
