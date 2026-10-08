<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 给 10 个「空白模块」菜单补上前端组件路径。
 *
 * 这 10 个菜单早在 BusinessSeeder 里就注册了（侧边栏一直能看到入口），
 * 但 component 字段一直是 NULL——点了是白屏。本次把它们的前端页面补齐后，
 * 在这里把 component 落库。
 *
 * 为什么用 upsert 而不是 UPDATE：迁移与 seeder 的先后不固定。
 * 全新环境 migrate:fresh 时先跑迁移、此时菜单还没被 seeder 创建，
 * 纯 UPDATE 会静默失效（菜单存在但没 component，页面依旧白屏）。
 * 因此：存在就 UPDATE component，不存在就按父菜单 name 插一条。
 *
 * 补授权沿用 register_purchase_application_menu 的模式：
 * 已经拥有父菜单权限的角色自动获得新页面权限，避免每次加页面都要手工配角色。
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            // 模块一：最近价格（价格管理）
            ['name' => 'price.recent', 'title' => '最近价格', 'parent' => 'price', 'path' => '/business/recent-prices', 'component' => 'business/recent-prices/index', 'sort' => 2],
            // 模块二 ~ 五：报表管理
            ['name' => 'report.sales', 'title' => '销售报表', 'parent' => 'report', 'path' => '/business/report/sales', 'component' => 'business/report/sales/index', 'sort' => 1],
            ['name' => 'report.stock', 'title' => '库存报表', 'parent' => 'report', 'path' => '/business/report/stock', 'component' => 'business/report/stock/index', 'sort' => 2],
            ['name' => 'report.salesman', 'title' => '业务员报表', 'parent' => 'report', 'path' => '/business/report/salesman', 'component' => 'business/report/salesman/index', 'sort' => 3],
            ['name' => 'report.combined', 'title' => '综合报表', 'parent' => 'report', 'path' => '/business/report/combined', 'component' => 'business/report/combined/index', 'sort' => 4],
            // 模块六 ~ 七：办公管理
            ['name' => 'office.mail', 'title' => '内部邮件', 'parent' => 'office', 'path' => '/business/mail', 'component' => 'business/mail/index', 'sort' => 1],
            ['name' => 'office.notice', 'title' => '公司公告', 'parent' => 'office', 'path' => '/business/notice', 'component' => 'business/notice/index', 'sort' => 2],
            // 模块八 ~ 九：拜访管理（页面文件此前已存在，只是菜单没挂 component）
            ['name' => 'visit.achievement', 'title' => '访店达成率', 'parent' => 'visit', 'path' => '/business/visit/achievement', 'component' => 'business/visit/achievement', 'sort' => 4],
            ['name' => 'visit.schedule', 'title' => '业务员行程', 'parent' => 'visit', 'path' => '/business/visit/schedule', 'component' => 'business/visit/schedule', 'sort' => 6],
            // 模块十：小程序管理
            ['name' => 'miniapp.setting', 'title' => '小程序设置', 'parent' => 'miniapp', 'path' => '/business/mini-program-settings', 'component' => 'business/mini-program-settings/index', 'sort' => 1],
        ];

        foreach ($menus as $menu) {
            $this->upsertMenu($menu);
            $this->grantToParentRoles($menu['name'], $menu['parent']);
        }
    }

    public function down(): void
    {
        // 只摘 component，不删菜单：菜单本身是 seeder 的资产，删除会让侧边栏凭空少入口
        foreach (['price.recent', 'report.sales', 'report.stock', 'report.salesman', 'report.combined', 'office.mail', 'office.notice', 'visit.achievement', 'visit.schedule', 'miniapp.setting'] as $name) {
            DB::table('auth_permission')->where('name', $name)->update(['component' => null]);
        }
    }

    private function upsertMenu(array $menu): void
    {
        $exists = DB::table('auth_permission')->where('name', $menu['name'])->first();

        if ($exists) {
            DB::table('auth_permission')->where('id', $exists->id)->update([
                'component' => $menu['component'],
                'path' => $menu['path'],
                'title' => $menu['title'],
                'updated_at' => now(),
            ]);

            return;
        }

        $parentId = DB::table('auth_permission')->where('name', $menu['parent'])->value('id');
        if (! $parentId) {
            return;
        }

        DB::table('auth_permission')->insert([
            'title' => $menu['title'],
            'name' => $menu['name'],
            'type' => 'menu',
            'parent_id' => $parentId,
            'path' => $menu['path'],
            'component' => $menu['component'],
            'sort' => $menu['sort'],
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantToParentRoles(string $menuName, string $parentName): void
    {
        $menuId = DB::table('auth_permission')->where('name', $menuName)->value('id');
        if (! $menuId) {
            return;
        }

        $roleIds = DB::table('auth_role_permission as rp')
            ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
            ->where(function ($q) use ($parentName) {
                $q->where('p.name', $parentName)->orWhere('p.name', 'like', $parentName.'.%');
            })
            ->pluck('rp.role_id');

        foreach ($roleIds as $roleId) {
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
};
