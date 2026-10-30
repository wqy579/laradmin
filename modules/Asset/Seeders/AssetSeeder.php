<?php

namespace Modules\Asset\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 固定资产模块种子编排：菜单 + 权限节点 + super_admin 授权。
 *
 * 顺序约束：AuthSeeder 会 truncate auth_permission，所以本 seeder 必须排在
 * AuthSeeder 之后重建菜单与权限节点（与 CashSeeder / LedgerSeeder 同一约束）。
 */
class AssetSeeder extends Seeder
{
    private array $menuIds = [];

    public function run(): void
    {
        $this->registerMenus();
        $this->registerButtons();
        $this->grantToSuperAdmin();
    }

    /** 顶级 + 子菜单：与 2026_10_29_000003_register_asset_menu 保持一致 */
    private function registerMenus(): void
    {
        $menus = [
            ['name' => 'asset', 'title' => '固定资产', 'parent_name' => null, 'path' => '/asset/purchase', 'component' => null, 'sort' => 19, 'icon' => 'ElIconBox'],
            ['name' => 'asset.purchase', 'title' => '资产购置单', 'parent_name' => 'asset', 'path' => '/asset/purchase', 'component' => 'asset/purchase/index', 'sort' => 1, 'icon' => null],
            ['name' => 'asset.card', 'title' => '固定资产台账', 'parent_name' => 'asset', 'path' => '/asset/card', 'component' => 'asset/card/index', 'sort' => 2, 'icon' => null],
            ['name' => 'asset.depreciation', 'title' => '资产折旧单', 'parent_name' => 'asset', 'path' => '/asset/depreciation', 'component' => 'asset/depreciation/index', 'sort' => 3, 'icon' => null],
            ['name' => 'asset.sale', 'title' => '资产变卖单', 'parent_name' => 'asset', 'path' => '/asset/sale', 'component' => 'asset/sale/index', 'sort' => 4, 'icon' => null],
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

    /** 按钮权限节点；alias 对齐前端静态路由 meta.perm 的冒号写法 */
    private function registerButtons(): void
    {
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
