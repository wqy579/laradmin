<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 报表中心：连凯对标四核心报表的菜单与组件路径
 *
 * 现有「报表管理」下是 销售报表 / 库存报表 / 业务员报表 / 综合报表 四个通用
 * 维度报表。本次补上连凯的固定版式四件套：报表中心首页 + 销售日报 + 销售月报 +
 * 利润报表（销售利润，区别于财务「月度利润」的收入/成本口径）。
 *
 * 菜单结构：
 *   报表管理(/business/report，无组件 → 自动跳首个子菜单)
 *     ├─ 报表中心  /business/report/center   business/report/index
 *     ├─ 销售日报  /business/report/daily    business/report/daily/index
 *     ├─ 销售月报  /business/report/monthly  business/report/monthly/index
 *     ├─ 库存报表  /business/report/stock    business/report/stock/index（已存在，仅确认 component）
 *     ├─ 利润报表  /business/report/profit   business/report/profit/index
 *     └─ 销售报表 / 业务员报表 / 综合报表 / 经营历程（保留，顺延 sort）
 *
 * 用 upsert 而不是 UPDATE 的理由同 register_blank_module_menus：迁移与 seeder
 * 先后不固定，全新环境先跑迁移时菜单还不存在，纯 UPDATE 会静默失效。
 *
 * 授权沿用 grantToParentRoles：拥有「报表管理」的角色自动获得新页面权限，
 * 不用每次加报表都手工配角色。
 */
return new class extends Migration
{
    /** 本次新建的菜单（down 时删除） */
    private const NEW_MENUS = ['report.center', 'report.daily', 'report.monthly', 'report.profit'];

    public function up(): void
    {
        // 父菜单改为报表中心落地：无组件 + path ≠ 首个子菜单 path，
        // 前端 transformMenusToRoutes 会自动加 redirect 跳到报表中心。
        $parentId = DB::table('auth_permission')->where('name', 'report')->value('id');
        if ($parentId) {
            DB::table('auth_permission')->where('id', $parentId)->update([
                'path' => '/business/report',
                'updated_at' => now(),
            ]);
        }

        $menus = [
            ['name' => 'report.center', 'title' => '报表中心', 'parent' => 'report', 'path' => '/business/report/center', 'component' => 'business/report/index', 'sort' => 0],
            ['name' => 'report.daily', 'title' => '销售日报', 'parent' => 'report', 'path' => '/business/report/daily', 'component' => 'business/report/daily/index', 'sort' => 1],
            ['name' => 'report.monthly', 'title' => '销售月报', 'parent' => 'report', 'path' => '/business/report/monthly', 'component' => 'business/report/monthly/index', 'sort' => 2],
            ['name' => 'report.stock', 'title' => '库存报表', 'parent' => 'report', 'path' => '/business/report/stock', 'component' => 'business/report/stock/index', 'sort' => 3],
            ['name' => 'report.profit', 'title' => '利润报表', 'parent' => 'report', 'path' => '/business/report/profit', 'component' => 'business/report/profit/index', 'sort' => 4],
            ['name' => 'report.sales', 'title' => '销售报表', 'parent' => 'report', 'path' => '/business/report/sales', 'component' => 'business/report/sales/index', 'sort' => 5],
            ['name' => 'report.salesman', 'title' => '业务员报表', 'parent' => 'report', 'path' => '/business/report/salesman', 'component' => 'business/report/salesman/index', 'sort' => 6],
            ['name' => 'report.combined', 'title' => '综合报表', 'parent' => 'report', 'path' => '/business/report/combined', 'component' => 'business/report/combined/index', 'sort' => 7],
        ];

        foreach ($menus as $menu) {
            $this->upsertMenu($menu);
            $this->grantToParentRoles($menu['name'], 'report');
        }
    }

    public function down(): void
    {
        // 本次新建的菜单直接删除（不是 seeder 资产，留着会是侧边栏死入口）
        DB::table('auth_role_permission')
            ->whereIn('permission_id', DB::table('auth_permission')->whereIn('name', self::NEW_MENUS)->pluck('id'))
            ->delete();
        DB::table('auth_permission')->whereIn('name', self::NEW_MENUS)->delete();

        // 父菜单 path 回滚到原有值
        DB::table('auth_permission')->where('name', 'report')->update([
            'path' => '/business/report/sales',
            'updated_at' => now(),
        ]);
    }

    private function upsertMenu(array $menu): void
    {
        $exists = DB::table('auth_permission')->where('name', $menu['name'])->first();

        if ($exists) {
            DB::table('auth_permission')->where('id', $exists->id)->update([
                'component' => $menu['component'],
                'path' => $menu['path'],
                'title' => $menu['title'],
                'sort' => $menu['sort'],
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
