<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F5 分销增值 —— 注册「分销增值」顶级菜单分组 + 5 个子菜单。
 *
 * 分组下挂五页：
 *   f5.map          行程地图      /f5/route-map
 *   f5.mall.shop    商城前台      /f5/mall/shop
 *   f5.mall.manage  商城后台      /f5/mall/manage
 *   f5.sms.template 短信模板      /f5/sms/template
 *   f5.sms.record   发送记录      /f5/sms/record
 *
 * 幂等：按 name 判重再插入；已拥有 f5 域菜单权限的角色自动补授权。
 * 权限点命名沿用库内点式约定（f5.*），前缀 f5。
 */
return new class extends Migration
{
    /** 子菜单定义：name/title/path/component/sort */
    private array $menus = [
        ['name' => 'f5.map', 'title' => '行程地图', 'path' => '/f5/route-map', 'component' => 'f5/route-map/index', 'sort' => 1],
        ['name' => 'f5.mall.shop', 'title' => '商城前台', 'path' => '/f5/mall/shop', 'component' => 'f5/mall/shop/index', 'sort' => 2],
        ['name' => 'f5.mall.manage', 'title' => '商城后台', 'path' => '/f5/mall/manage', 'component' => 'f5/mall/manage/index', 'sort' => 3],
        ['name' => 'f5.sms.template', 'title' => '短信模板', 'path' => '/f5/sms/template', 'component' => 'f5/sms/template/index', 'sort' => 4],
        ['name' => 'f5.sms.record', 'title' => '发送记录', 'path' => '/f5/sms/record', 'component' => 'f5/sms/record/index', 'sort' => 5],
    ];

    public function up(): void
    {
        // 1. 顶级分组「分销增值」（sort=16，置于订货中心之后）
        if (! DB::table('auth_permission')->where('name', 'f5-center')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '分销增值',
                'name' => 'f5-center',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/f5/route-map',
                'component' => '',
                'meta' => '{"icon":"ElIconShop"}',
                'sort' => 16,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $groupId = (int) DB::table('auth_permission')->where('name', 'f5-center')->value('id');
        if (! $groupId) {
            return;
        }

        // 2. 子菜单
        foreach ($this->menus as $menu) {
            if (! DB::table('auth_permission')->where('name', $menu['name'])->exists()) {
                DB::table('auth_permission')->insert([
                    'title' => $menu['title'],
                    'name' => $menu['name'],
                    'type' => 'menu',
                    'parent_id' => $groupId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 3. 给已有 f5 域菜单权限的角色补授权
        $menuIds = collect($this->menus)
            ->map(fn ($m) => (int) DB::table('auth_permission')->where('name', $m['name'])->value('id'))
            ->filter()
            ->all();

        if ($menuIds) {
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where(function ($q) {
                    $q->where('p.name', 'f5-center')->orWhere('p.name', 'like', 'f5.%');
                })
                ->distinct()
                ->pluck('rp.role_id');

            foreach ($roleIds as $roleId) {
                foreach ($menuIds as $menuId) {
                    $exists = DB::table('auth_role_permission')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $menuId)
                        ->exists();
                    if (! $exists) {
                        DB::table('auth_role_permission')->insert([
                            'role_id' => $roleId,
                            'permission_id' => $menuId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // 删子菜单授权与记录
        foreach ($this->menus as $menu) {
            $id = DB::table('auth_permission')->where('name', $menu['name'])->value('id');
            if ($id) {
                DB::table('auth_role_permission')->where('permission_id', $id)->delete();
                DB::table('auth_permission')->where('id', $id)->delete();
            }
        }

        // 分组仅在无其余子菜单时一并删除
        $groupId = DB::table('auth_permission')->where('name', 'f5-center')->value('id');
        if ($groupId && ! DB::table('auth_permission')->where('parent_id', $groupId)->exists()) {
            DB::table('auth_role_permission')->where('permission_id', $groupId)->delete();
            DB::table('auth_permission')->where('id', $groupId)->delete();
        }
    }
};
