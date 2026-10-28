<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 已部署环境补丁（生产只跑 migrate、不跑 db:seed）：
 *
 * B8 资金看板上线。BusinessSeeder 的 $financeModuleMenus/$financeButtons
 * 已加对应行，但已部署库不会重跑 seeder，需迁移回填：
 *
 *  1) 「财务管理」顶级菜单下插入 finance.fund-dashboard（/finance/fund-dashboard，sort=25）；
 *  2) 插入按钮权限 fin:fund-dashboard:view（前端路由 meta.perm 依赖）；
 *  3) 两者授权给 super_admin。
 *
 * 幂等：存在即跳过/覆盖式更新，重复跑无副作用。
 */
return new class extends Migration
{
    public function up(): void
    {
        $top = DB::table('auth_permission')->where('name', 'finance')->where('type', 'menu')->first();
        if (! $top) {
            // 全新库走 BusinessSeeder 即可，无需回填
            return;
        }

        $menuId = DB::table('auth_permission')->where('name', 'finance.fund-dashboard')->value('id');
        if (! $menuId) {
            $menuId = DB::table('auth_permission')->insertGetId([
                'title' => '资金看板',
                'name' => 'finance.fund-dashboard',
                'type' => 'menu',
                'parent_id' => $top->id,
                'path' => '/finance/fund-dashboard',
                'component' => 'finance/fund-dashboard/index',
                'meta' => null,
                'sort' => 25,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $btnId = DB::table('auth_permission')->where('name', 'fin:fund-dashboard:view')->value('id');
        if (! $btnId) {
            $btnId = DB::table('auth_permission')->insertGetId([
                'title' => '查看资金看板',
                'name' => 'fin:fund-dashboard:view',
                'type' => 'button',
                'parent_id' => $menuId,
                'path' => 'fin:fund-dashboard:view',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        if ($superRoleId) {
            foreach ([$menuId, $btnId] as $permId) {
                DB::table('auth_role_permission')->updateOrInsert(
                    ['role_id' => $superRoleId, 'permission_id' => $permId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        // 不回滚：菜单补登是幂等的「补数据」，down 保持 no-op
    }
};
