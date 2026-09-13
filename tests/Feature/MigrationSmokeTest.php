<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T2 全量迁移冒烟
 *
 * 在 SQLite 上从零跑 migrate:fresh + db:seed，保证新环境永远能建库。
 * 此前项目出过迁移语法错、重复索引、重复列三类硬伤，"生产库能跑"全靠 schema 历史悠久。
 * 该用例可进 CI：新环境克隆后跑一次 phpunit 即验证建库 + 种子全链路。
 */
class MigrationSmokeTest extends TestCase
{
    public function test_full_migrate_and_seed_from_scratch_on_sqlite(): void
    {
        // 1) 从零建库 + 全量种子，命令必须成功退出
        $exitCode = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->assertSame(0, $exitCode, "migrate:fresh --seed 失败：\n".Artisan::output());

        // 2) 二次 migrate 必须幂等（无 pending 迁移时静默成功）
        $secondRun = Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, $secondRun, "二次 migrate 失败（迁移可能不幂等）：\n".Artisan::output());

        // 3) 关键表全部存在（auth / system / 进销存全链路）
        $expectedTables = [
            // 认证
            'auth_user', 'auth_department', 'auth_role', 'auth_permission',
            'auth_user_role', 'auth_role_permission',
            // 系统
            'system_setting', 'system_log', 'system_dictionary', 'system_dictionary_item',
            'system_scheduled', 'system_scheduled_log', 'system_notification', 'system_attachment',
            'jobs',
            // 基础资料
            'units', 'product_categories', 'products', 'warehouses', 'customers', 'suppliers',
            'vehicles', 'routes', 'route_customers', 'employees', 'attendances', 'visit_logs',
            // 库存
            'stocks', 'stocks_history', 'stock_snapshots', 'liankai_stock_checks', 'liankai_stock_change_logs',
            // 交易单据
            'sales_orders', 'sales_order_items', 'purchase_orders', 'purchase_order_items',
            'stock_ins', 'stock_in_items', 'stock_outs', 'stock_out_items',
            'transfers', 'transfer_items', 'returns', 'return_items', 'deliveries', 'delivery_items',
            'receives', 'pays', 'expenses',
        ];
        foreach ($expectedTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "缺少数据表 {$table}");
        }

        // 4) 种子基线数据
        $admin = DB::table('auth_user')->where('username', 'admin')->first();
        $this->assertNotNull($admin, '种子用户 admin 不存在');
        $this->assertSame(1, (int) $admin->status, '种子用户 admin 未启用');

        $this->assertNotNull(
            DB::table('auth_user')->where('username', 'manager')->first(),
            '种子用户 manager 不存在'
        );

        $superAdminRole = DB::table('auth_role')->where('code', 'super_admin')->first();
        $this->assertNotNull($superAdminRole, 'super_admin 角色不存在');
        $this->assertSame(
            1,
            (int) DB::table('auth_user_role')->where('user_id', $admin->id)->where('role_id', $superAdminRole->id)->count(),
            'admin 用户未绑定 super_admin 角色'
        );

        $this->assertGreaterThan(
            0,
            DB::table('auth_permission')->where('type', 'menu')->where('status', 1)->count(),
            '没有可用的菜单权限'
        );

        $this->assertSame(2, (int) DB::table('warehouses')->count(), '种子仓库数应为 2');
        $this->assertSame(5, (int) DB::table('products')->count(), '种子商品数应为 5');
        $this->assertSame(6, (int) DB::table('units')->count(), '种子单位数应为 6');

        // 5) 基线：种子不产生任何库存行（库存只能通过业务动作产生）
        $this->assertSame(0, (int) DB::table('stocks')->count(), '种子不应预置库存行');
    }
}
