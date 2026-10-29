<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 已部署环境补丁（生产只跑 migrate、不跑 db:seed）：
 *
 * E6 采购/费用报表上线。BusinessSeeder 的报表子菜单已加 report.procurement 行，
 * 但已部署库不会重跑 seeder，需迁移回填。
 *
 * 同时补登 E2–E5 已合并但只进了 seeder 的报表菜单（customer / sales-analysis /
 * price-rank / stock-analysis）——历史上 E2–E5 只改了 seeder，而部署只跑 migrate，
 * 这四个菜单在已部署库里一直缺失。
 *
 * 幂等：存在即跳过，重复跑无副作用。全新库走 BusinessSeeder 即可，本迁移只在
 * 已部署库补数据；授权给 super_admin 与 B8 菜单迁移同款做法。
 */
return new class extends Migration
{
    /** E2–E5 已合并但部署库缺失的报表菜单（sort 与 seeder 保持一致） */
    private const BACKFILL_MENUS = [
        ['name' => 'report.customer', 'title' => '客户报表', 'path' => '/business/report/customer', 'component' => 'business/report/customer/index', 'sort' => 5],
        ['name' => 'report.sales-analysis', 'title' => '销售分析', 'path' => '/business/report/sales-analysis', 'component' => 'business/report/sales-analysis/index', 'sort' => 7],
        ['name' => 'report.price-rank', 'title' => '排行/价格', 'path' => '/business/report/price-rank', 'component' => 'business/report/price-rank/index', 'sort' => 8],
        ['name' => 'report.stock-analysis', 'title' => '库存分析', 'path' => '/business/report/stock-analysis', 'component' => 'business/report/stock-analysis/index', 'sort' => 9],
    ];

    private const E6_MENU = ['name' => 'report.procurement', 'title' => '采购/费用', 'path' => '/business/report/procurement', 'component' => 'business/report/procurement/index', 'sort' => 10];

    public function up(): void
    {
        $top = DB::table('auth_permission')->where('name', 'report')->where('type', 'menu')->first();
        if (! $top) {
            // 全新库走 BusinessSeeder 即可，无需回填
            return;
        }

        foreach ([...self::BACKFILL_MENUS, self::E6_MENU] as $menu) {
            $menuId = DB::table('auth_permission')->where('name', $menu['name'])->value('id');
            if (! $menuId) {
                $menuId = DB::table('auth_permission')->insertGetId([
                    'title' => $menu['title'],
                    'name' => $menu['name'],
                    'type' => 'menu',
                    'parent_id' => $top->id,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'meta' => null,
                    'sort' => $menu['sort'],
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
    }

    public function down(): void
    {
        // 不回滚：菜单补登是幂等的「补数据」，down 保持 no-op
    }
};
