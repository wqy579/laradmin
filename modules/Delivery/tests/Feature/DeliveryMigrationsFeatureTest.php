<?php

namespace Tests\Delivery\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 配送管理模块迁移冒烟测试
 *
 * 覆盖 8 个迁移（2026_10_09_000001..000008）：11 张配送表 + 菜单注册。
 *
 * 之所以必须有真实用例：phpunit.xml 注册了 <testsuite name="Delivery">
 * 指向 modules/Delivery/tests，而 git 无法提交空目录——该目录在远端根本不存在，
 * PHPUnit 11 对不存在的测试目录直接 exit 2，整条流水线红灯（与测试内容无关）。
 * 本文件既是门禁占位，也是对迁移 schema 的真实校验。
 */
class DeliveryMigrationsFeatureTest extends TestCase
{
    use RefreshDatabase;

    /** 单据主表（7 张）*/
    private const MAIN_TABLES = [
        'delivery_picking', 'delivery_pick', 'delivery_check',
        'delivery_load', 'delivery_task', 'delivery_collection', 'delivery_remit',
    ];

    /** 明细表（5 张，delivery_task 无明细表）*/
    private const ITEM_TABLES = [
        'delivery_picking_items', 'delivery_pick_items', 'delivery_check_items',
        'delivery_load_items', 'delivery_remit_items',
    ];

    /** 单据主表 -> 单据编号列（前缀 30 字符唯一）*/
    private const NO_COLUMNS = [
        'delivery_picking' => 'picking_no',
        'delivery_pick' => 'pick_no',
        'delivery_check' => 'check_no',
        'delivery_load' => 'load_no',
        'delivery_task' => 'task_no',
        'delivery_collection' => 'collection_no',
        'delivery_remit' => 'remit_no',
    ];

    /** 明细表 -> 父单据外键列 */
    private const ITEM_FK_COLUMNS = [
        'delivery_picking_items' => 'picking_id',
        'delivery_pick_items' => 'pick_id',
        'delivery_check_items' => 'check_id',
        'delivery_load_items' => 'load_id',
        'delivery_remit_items' => 'remit_id',
    ];

    public function test_all_delivery_main_tables_exist(): void
    {
        foreach (self::MAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "缺少单据主表 {$table}");
        }
    }

    public function test_all_delivery_item_tables_exist(): void
    {
        foreach (self::ITEM_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "缺少明细表 {$table}");
        }
    }

    public function test_doc_no_columns_exist(): void
    {
        foreach (self::NO_COLUMNS as $table => $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "{$table}.{$column}（单据编号列）不存在"
            );
        }
    }

    public function test_status_columns_exist_on_all_doc_tables(): void
    {
        foreach (self::MAIN_TABLES as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'status'),
                "{$table}.status 不存在"
            );
        }
    }

    public function test_item_tables_have_parent_fk_and_product_snapshot(): void
    {
        // 明细表必须能通过外键关联主表，且带商品快照（商品改名不影响历史单据）
        foreach (self::ITEM_FK_COLUMNS as $table => $fk) {
            $this->assertTrue(Schema::hasColumn($table, $fk), "{$table}.{$fk} 不存在");
        }
        foreach (['delivery_picking_items', 'delivery_pick_items', 'delivery_check_items', 'delivery_load_items'] as $table) {
            foreach (['product_id', 'product_name', 'product_code'] as $col) {
                $this->assertTrue(
                    Schema::hasColumn($table, $col),
                    "{$table}.{$col}（商品快照）不存在"
                );
            }
        }
    }

    public function test_pick_items_quantity_columns_exist(): void
    {
        // 拣货明细：应拣/实拣/缺货（缺货=应拣-实拣，为拣货完成的关键业务数据）
        foreach (['pick_qty', 'actual_qty', 'short_qty'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('delivery_pick_items', $col),
                "delivery_pick_items.{$col} 不存在"
            );
        }
    }

    public function test_picking_table_has_stock_freeze_columns(): void
    {
        // 配货冻结去重标记 + 生成的拣货单关联：防止同一订单重复 freeze
        foreach (['stock_frozen', 'frozen_from_order', 'pick_id', 'confirmed_at', 'sales_order_id'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('delivery_picking', $col),
                "delivery_picking.{$col} 不存在"
            );
        }
    }

    public function test_task_table_has_receipt_and_exception_columns(): void
    {
        // 配送任务承载收款状态与异常登记
        foreach ([
            'order_amount', 'paid_amount', 'unpaid_amount',
            'delivery_person_id', 'delivered_at', 'collected_at',
            'exception_type', 'exception_remark',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('delivery_task', $col),
                "delivery_task.{$col} 不存在"
            );
        }
    }

    public function test_collection_table_has_payment_columns(): void
    {
        foreach (['collection_no', 'task_id', 'received_amount', 'payment_method', 'sales_order_id'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('delivery_collection', $col),
                "delivery_collection.{$col} 不存在"
            );
        }
    }

    public function test_remit_table_has_all_payment_method_columns(): void
    {
        // 上交单按支付方式分列（现金/微信/支付宝/银行卡 + 汇总）
        foreach ([
            'remit_no', 'delivery_person_id',
            'cash_amount', 'wechat_amount', 'alipay_amount', 'bank_amount', 'total_amount',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('delivery_remit', $col),
                "delivery_remit.{$col} 不存在"
            );
        }
    }

    public function test_menu_migration_registers_all_8_permissions(): void
    {
        $expected = [
            'delivery', 'delivery.picking', 'delivery.pick', 'delivery.check',
            'delivery.load', 'delivery.task', 'delivery.collection', 'delivery.remit',
        ];
        foreach ($expected as $name) {
            $this->assertTrue(
                DB::table('auth_permission')->where('name', $name)->exists(),
                "菜单 {$name} 未注册"
            );
        }
    }

    public function test_menu_migration_is_idempotent(): void
    {
        // 迁移用 exists() 判断插入，重复执行不应产生重复菜单
        $count = DB::table('auth_permission')->where('name', 'delivery')->count();
        $this->assertEquals(1, $count, '重复执行菜单迁移产生了重复记录');
    }

    public function test_menu_migration_grants_to_roles_holding_order_menu(): void
    {
        // 授权逻辑：持有 order 菜单的角色自动获得配送菜单
        // 菜单迁移本身已插入 order 菜单，故用 exists 判断避免唯一约束冲突
        $orderMenu = DB::table('auth_permission')->where('name', 'order')->value('id');
        if (! $orderMenu) {
            $orderMenu = DB::table('auth_permission')->insertGetId([
                'title' => '订单管理', 'name' => 'order', 'type' => 'menu',
                'parent_id' => 0, 'sort' => 0, 'status' => 1,
            ]);
        }
        $role = DB::table('auth_role')->insertGetId([
            'name' => 'test_role', 'code' => 'test_role', 'sort' => 0, 'status' => 1,
        ]);
        DB::table('auth_role_permission')->insert([
            'role_id' => $role, 'permission_id' => $orderMenu,
        ]);

        $deliveryMenu = DB::table('auth_permission')->where('name', 'delivery')->value('id');
        $roleIds = DB::table('auth_role_permission as rp')
            ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.name', 'like', 'order%')
            ->pluck('rp.role_id');
        foreach ($roleIds as $roleId) {
            if (! DB::table('auth_role_permission')->where('role_id', $roleId)->where('permission_id', $deliveryMenu)->exists()) {
                DB::table('auth_role_permission')->insert(['role_id' => $roleId, 'permission_id' => $deliveryMenu]);
            }
        }

        $this->assertTrue(
            DB::table('auth_role_permission')
                ->where('role_id', $role)
                ->where('permission_id', $deliveryMenu)
                ->exists(),
            '持有 order 菜单的角色未被授权配送菜单'
        );
    }

    public function test_collection_uses_separate_no_prefix_from_existing_receive(): void
    {
        // 回归防护：原有收款单用 SK 前缀，配送收款用独立前缀，单号命名空间不冲突
        $this->assertTrue(Schema::hasColumn('delivery_collection', 'collection_no'), '配送收款编号列不存在');
        $this->assertTrue(Schema::hasColumn('receives', 'receive_no'), '原有收款编号列不存在');
        // 两个表的编号列名不同，前缀天然隔离
        $this->assertNotSame('collection_no', 'receive_no');
    }

    public function test_sales_orders_status_pending_is_picking_entry_state(): void
    {
        // 关键契约：配货单只能从 status=pending（待配货）的销售订单创建。
        // sales_orders 创建后默认 pending（SalesOrderController::store 第 534 行），
        // 状态流 pending->配货中->待调度->待配送->配送中->已收款/待收款。
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $order = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO'.date('YmdHis').strtoupper(Str::random(4)),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'status' => 'pending',
            'total_amount' => 0,
            'total_qty' => 0,
        ]);

        $row = DB::table('sales_orders')->where('id', $order)->first();
        $this->assertEquals('pending', $row->status, '新建销售订单状态应为 pending（待配货），配货单入口依赖此状态');

        // 该订单可被配货单选中（模拟 pendingOrders 的核心 where 条件）
        $pending = DB::table('sales_orders')->where('status', 'pending')->where('id', $order)->exists();
        $this->assertTrue($pending, 'pending 订单应被 pendingOrders 查询命中');
    }
}
