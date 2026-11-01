<?php

namespace Modules\Mall\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Permission;

/**
 * 「分销增值」(f5) 菜单权威定义。
 *
 * AuthSeeder 会 Permission::truncate() 整张 auth_permission，只在
 * modules/Mall/database/migrations/2026_11_05_000004_register_f5_menu.php
 * 里注册的这组菜单在 db:seed 后全部消失（同 PerformanceMenuSeeder 的说明）。
 * 字段照该迁移抄，不自行改数值。
 *
 * 幂等：firstOrCreate 按 name 判重，可反复执行。
 */
class MallMenuSeeder extends Seeder
{
    /** 顶级分组：component 空串 + path 指向第一个子路由（项目硬约定） */
    private const GROUP = [
        'name' => 'f5-center',
        'title' => '分销增值',
        'path' => '/f5/route-map',
        'icon' => 'ElIconShop',
        'sort' => 16,
    ];

    private const CHILDREN = [
        ['name' => 'f5.map', 'title' => '行程地图', 'path' => '/f5/route-map', 'component' => 'f5/route-map/index', 'sort' => 1],
        ['name' => 'f5.mall.shop', 'title' => '商城前台', 'path' => '/f5/mall/shop', 'component' => 'f5/mall/shop/index', 'sort' => 2],
        ['name' => 'f5.mall.manage', 'title' => '商城后台', 'path' => '/f5/mall/manage', 'component' => 'f5/mall/manage/index', 'sort' => 3],
        ['name' => 'f5.sms.template', 'title' => '短信模板', 'path' => '/f5/sms/template', 'component' => 'f5/sms/template/index', 'sort' => 4],
        ['name' => 'f5.sms.record', 'title' => '发送记录', 'path' => '/f5/sms/record', 'component' => 'f5/sms/record/index', 'sort' => 5],
    ];

    public function run(): void
    {
        Permission::firstOrCreate(['name' => self::GROUP['name']], [
            'title' => self::GROUP['title'],
            'type' => 'menu',
            'parent_id' => 0,
            'path' => self::GROUP['path'],
            'component' => '',
            'sort' => self::GROUP['sort'],
            'status' => 1,
            'meta' => ['icon' => self::GROUP['icon']],
        ]);

        $groupId = (int) Permission::where('name', self::GROUP['name'])->value('id');

        foreach (self::CHILDREN as $menu) {
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $groupId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 迁移是给「已持有 f5.% 的角色」补授权；截断后这批角色只剩 f5-center，
        // 等价于「把子菜单授给持有 f5-center 的角色」。
        $roleIds = DB::table('auth_role_permission')
            ->where('permission_id', $groupId)
            ->pluck('role_id');
        foreach (self::CHILDREN as $menu) {
            $permissionId = Permission::where('name', $menu['name'])->value('id');
            if (! $permissionId) {
                continue;
            }
            foreach ($roleIds as $roleId) {
                $granted = DB::table('auth_role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();
                if (! $granted) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
