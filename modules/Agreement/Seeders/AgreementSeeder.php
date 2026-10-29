<?php

namespace Modules\Agreement\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 协议管理模块种子：菜单 + 权限节点 + super_admin 授权。
 *
 * 与 2026_11_01_000002_register_agr_menu 逐条一致（约定同 HrSeeder）。
 */
class AgreementSeeder extends Seeder
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
            ['name' => 'agr', 'title' => '协议管理', 'parent_name' => null, 'path' => '/agr/list', 'component' => null, 'sort' => 16, 'icon' => 'ElIconDocument'],
            ['name' => 'agr.list', 'title' => '协议档案', 'parent_name' => 'agr', 'path' => '/agr/list', 'component' => 'agr/list/index', 'sort' => 1, 'icon' => null],
            ['name' => 'agr.stats', 'title' => '陈列协议统计', 'parent_name' => 'agr', 'path' => '/agr/stats', 'component' => 'agr/stats/index', 'sort' => 2, 'icon' => null],
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
