<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 已部署环境补丁（生产只跑 migrate、不跑 db:seed）：
 *
 * E7 应收/访店报表上线。BusinessSeeder 的报表子菜单已加 report.receivable 行，
 * 但已部署库不会重跑 seeder，需迁移回填。
 *
 * 幂等：存在即跳过，重复跑无副作用。全新库走 BusinessSeeder 即可，本迁移只在
 * 已部署库补数据；授权给 super_admin 与 B8/E6 菜单迁移同款做法。
 */
return new class extends Migration
{
    private const E7_MENU = ['name' => 'report.receivable', 'title' => '应收/访店', 'path' => '/business/report/receivable', 'component' => 'business/report/receivable/index', 'sort' => 11];

    public function up(): void
    {
        $top = DB::table('auth_permission')->where('name', 'report')->where('type', 'menu')->first();
        if (! $top) {
            // 全新库走 BusinessSeeder 即可，无需回填
            return;
        }

        $menuId = DB::table('auth_permission')->where('name', self::E7_MENU['name'])->value('id');
        if (! $menuId) {
            $menuId = DB::table('auth_permission')->insertGetId([
                'title' => self::E7_MENU['title'],
                'name' => self::E7_MENU['name'],
                'type' => 'menu',
                'parent_id' => $top->id,
                'path' => self::E7_MENU['path'],
                'component' => self::E7_MENU['component'],
                'meta' => null,
                'sort' => self::E7_MENU['sort'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
            if ($superRoleId) {
                DB::table('auth_role_permission')->updateOrInsert(
                    ['role_id' => $superRoleId, 'permission_id' => $menuId],
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
