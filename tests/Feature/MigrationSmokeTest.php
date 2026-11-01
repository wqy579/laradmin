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
    /**
     * 曾经只在迁移里注册、被 AuthSeeder 的 Permission::truncate() 抹掉的权限节点。
     *
     * 出处是 modules/{Order,Stock,Mall,Archive}/database/migrations 下的
     * register_*_menu 系列——它们跑在 migrate 阶段，而 db:seed 会清空整张
     * auth_permission，于是「先 migrate 再 seed」的库比「只 migrate」的库少了这批
     * 菜单与按钮（表现为菜单在页面上莫名消失，且每次重跑 seed 都复现）。
     * 现在由 modules/<Module>/Seeders/*MenuSeeder 在 AuthSeeder 之后补种，
     * 这里断言种回来了：seed 后的 name 集合必须 ⊇ 这批 name。
     *
     * 不含 inventory.purchase：迁移给它的 component business/purchase-order/index
     * 既无对应 .vue 也无任何前端/后端路由，是早于本次改动就存在的死节点，
     * 刻意不补种（见 StockMenuSeeder::run 的注释），故不纳入本断言。
     */
    private const MIGRATION_REGISTERED_PERMISSIONS = [
        // 订货中心（Order）
        'order-center',
        'order.check', 'order.visit-reminder', 'order.order-adjust', 'order.order-transfer',
        'order.purchase-return', 'order.transfer', 'order.promotion',
        // 库存 / 价格 / 品牌（Stock）
        'inventory.stocktaking', 'inventory.stock-adjust', 'inventory.purchase-application',
        'inventory.assembly', 'inventory.disassembly',
        'price.level', 'price.product', 'price.batch', 'price.plan', 'price.special',
        'data.brand',
        // 分销增值（Mall）
        'f5-center', 'f5.map', 'f5.mall.shop', 'f5.mall.manage', 'f5.sms.template', 'f5.sms.record',
        // 档案中心（Archive）：菜单
        'data.company', 'data.division', 'data.opening', 'data.help', 'data.download',
    ];

    /** 档案中心的按钮动作；迁移同款「点号名 + 冒号别名」双写 */
    private const MIGRATION_REGISTERED_BUTTON_ACTIONS = [
        'data.company.view', 'data.company.create', 'data.company.edit', 'data.company.delete', 'data.company.export',
        'data.division.view', 'data.division.create', 'data.division.edit', 'data.division.delete', 'data.division.export',
        'data.opening.view', 'data.opening.edit', 'data.opening.import', 'data.opening.lock', 'data.opening.unlock',
        'data.help.view', 'data.help.create', 'data.help.edit', 'data.help.delete',
        'data.download.view', 'data.download.create', 'data.download.edit', 'data.download.delete', 'data.download.download',
    ];

    public function test_full_migrate_and_seed_from_scratch_on_sqlite(): void
    {
        // 只在 SQLite 上跑，不是省事的限定，而是隔离约束：
        // 本用例要在测试进程内执行 migrate:fresh，即 drop 掉所有表。SQLite 用的是
        // phpunit.xml 里的 :memory:，每连接一份私有副本，跑完不影响同批用例；
        // MySQL 在 CI 里是全 job 共享的同一个库，这里一跑就会抹掉其它用例的 schema。
        //
        // MySQL 侧的建库覆盖由 CI 的「Migration smoke test」步骤负责（php artisan
        // migrate:fresh --force）。但注意那一步不带 --seed，所以 seeders 里
        // 「非 sqlite 则 SET FOREIGN_KEY_CHECKS=0」那条分支在 CI 中没有直接覆盖——
        // 改 seeder 时值得在 MySQL 上手动跑一次 migrate:fresh --seed。
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped(
                '本用例会 drop 全部表，需要 SQLite :memory: 的隔离；当前驱动 '.DB::getDriverName()
            );
        }

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
            'stocks', 'stocks_history', 'stock_snapshots',
            // 交易单据（purchase_orders/purchase_order_items 已由迁移移除）
            'sales_orders', 'sales_order_items',
            'stock_ins', 'stock_in_items', 'stock_outs', 'stock_out_items',
            'transfers', 'transfer_items', 'returns', 'return_items', 'deliveries', 'delivery_items',
            'receives', 'pays', 'expenses',
            // 总账管理
            'ledger_subjects', 'ledger_opening_balances', 'ledger_vouchers', 'ledger_voucher_entries',
            'ledger_periods', 'ledger_voucher_templates',
            // E8 仪表盘用户布局偏好
            'dashboard_user_preference',
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

        // 原先这里只断言「菜单数 > 0」，正好放过本用例要拦的那类回归：
        // AuthSeeder truncate 掉整张 auth_permission 后，只在迁移里注册的菜单
        // 没人补种，库里仍有几百条别的菜单，count 依旧 > 0。改成集合包含断言。
        $seededNames = DB::table('auth_permission')->pluck('name')->all();
        $expected = self::MIGRATION_REGISTERED_PERMISSIONS;
        foreach (self::MIGRATION_REGISTERED_BUTTON_ACTIONS as $action) {
            $expected[] = $action;
            $expected[] = str_replace('.', ':', $action);
        }
        $missing = array_values(array_diff($expected, $seededNames));
        $this->assertSame(
            [],
            $missing,
            'db:seed 后丢失了迁移期注册的权限节点：'.implode(', ', $missing)
        );
        // 原断言 assertGreaterThan(0, 菜单数) 是这条包含断言的弱化子集，删掉：
        // 上面 78 个节点在库里，菜单数必然 > 0。

        $this->assertSame(2, (int) DB::table('warehouses')->count(), '种子仓库数应为 2');
        $this->assertSame(5, (int) DB::table('products')->count(), '种子商品数应为 5');
        $this->assertSame(6, (int) DB::table('units')->count(), '种子单位数应为 6');

        // 5) 基线：种子不产生任何库存行（库存只能通过业务动作产生）
        $this->assertSame(0, (int) DB::table('stocks')->count(), '种子不应预置库存行');
    }
}
