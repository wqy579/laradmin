<?php

namespace Modules\Cash\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 出纳资金模块种子编排：菜单 + 权限节点 + super_admin 授权。
 *
 * 顺序约束：AuthSeeder 会 Permission::truncate() auth_permission，所以本 seeder
 * 必须排在 AuthSeeder 之后重建菜单与权限节点（与 LedgerSeeder 同一约束）。
 */
class CashSeeder extends Seeder
{
    private array $menuIds = [];

    public function run(): void
    {
        $this->registerMenus();
        $this->registerButtons();
        $this->grantToSuperAdmin();
    }

    /** 顶级 + 子菜单：与 2026_10_28_000004_register_cash_menu 保持一致 */
    private function registerMenus(): void
    {
        $menus = [
            ['name' => 'cash', 'title' => '出纳管理', 'parent_name' => null, 'path' => '/cash/account-flow', 'component' => null, 'sort' => 14, 'icon' => 'ElIconMoney'],
            ['name' => 'cash.account-flow', 'title' => '现金账户', 'parent_name' => 'cash', 'path' => '/cash/account-flow', 'component' => 'cash/account-flow/index', 'sort' => 1, 'icon' => null],
            ['name' => 'cash.card', 'title' => '刷卡记录', 'parent_name' => 'cash', 'path' => '/cashier/card', 'component' => 'cashier/card/index', 'sort' => 2, 'icon' => null],
            ['name' => 'cash.handover', 'title' => '收入上交款', 'parent_name' => 'cash', 'path' => '/cash/handover', 'component' => 'cash/handover/index', 'sort' => 3, 'icon' => null],
            ['name' => 'cash.handover-query', 'title' => '上交款查询', 'parent_name' => 'cash', 'path' => '/cash/handover-query', 'component' => 'cash/handover-query/index', 'sort' => 4, 'icon' => null],
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

    /** 按钮权限节点；alias 对齐静态路由 meta.perm 的冒号写法 */
    private function registerButtons(): void
    {
        $buttons = [
            ['name' => 'cash.account-flow.view', 'alias' => 'cash:query', 'title' => '查询账户收支', 'parent_name' => 'cash.account-flow', 'sort' => 1],
            ['name' => 'cash.account-flow.export', 'alias' => null, 'title' => '导出账户收支', 'parent_name' => 'cash.account-flow', 'sort' => 2],
            ['name' => 'cash.card.view', 'alias' => 'cash:card:view', 'title' => '查看刷卡记录', 'parent_name' => 'cash.card', 'sort' => 1],
            ['name' => 'cash.card.refund', 'alias' => null, 'title' => '刷卡退款', 'parent_name' => 'cash.card', 'sort' => 2],
            ['name' => 'cash.card.export', 'alias' => null, 'title' => '导出刷卡记录', 'parent_name' => 'cash.card', 'sort' => 3],
            ['name' => 'cash.handover.view', 'alias' => 'cash:handover:view', 'title' => '查询上交款单', 'parent_name' => 'cash.handover', 'sort' => 1],
            ['name' => 'cash.handover.create', 'alias' => 'cash:handover:create', 'title' => '新建上交款单', 'parent_name' => 'cash.handover', 'sort' => 2],
            ['name' => 'cash.handover.confirm', 'alias' => 'cash:handover:confirm', 'title' => '确认上交款单', 'parent_name' => 'cash.handover-query', 'sort' => 3],
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
}
