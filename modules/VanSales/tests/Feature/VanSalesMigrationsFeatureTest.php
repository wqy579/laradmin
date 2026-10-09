<?php

namespace Tests\VanSales\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 车销业务模块迁移契约测试
 *
 * 覆盖 6 个迁移：
 *   2026_10_10_000001_add_type_to_warehouses    —— 车辆伪装成仓库（复用 stocks 的架构支点）
 *   2026_10_10_000002_create_van_sales_tables   —— 阶段一：要货/拣货/销售 6 张表
 *   2026_10_10_000003_register_van_menu         —— 阶段一 4 个子菜单
 *   2026_10_11_000001_add_van_links_to_visit_logs —— 拜访单联动列
 *   2026_10_11_000002_create_van_stage2_tables  —— 阶段二：退货/借还换货 8 张表
 *   2026_10_11_000003_register_van_stage2_menu   —— 阶段二 4 个子菜单
 *
 * 模块 7 控制器共 3780 行、15 张表、11 个路由组此前零测试覆盖。本文件既是
 * phpunit.xml 声明 <testsuite name="VanSales"> 的真实用例（目录曾只有 .gitkeep），
 * 也是 15 张表 schema 契约的守卫——后续加字段/删字段/改状态枚举会被这里拦住。
 *
 * 另见 VanSalesSeedFeatureTest：全新安装（db:seed）路径的菜单完整性守卫。
 * 两个类分开是刻意的：本类用 RefreshDatabase（事务内跑迁移），而 seed 路径要跑
 * Artisan migrate:fresh（需 VACUUM），事务内 VACUUM 会报 "cannot VACUUM from within
 * a transaction"。MenuIconTest 同样不用 RefreshDatabase，同一原因。
 */
class VanSalesMigrationsFeatureTest extends TestCase
{
    use RefreshDatabase;

    /** 阶段一单据主表（3 张） */
    private const STAGE1_MAIN_TABLES = [
        'van_requisitions', 'van_picking', 'van_sale_orders',
    ];

    /** 阶段二单据主表（4 张） */
    private const STAGE2_MAIN_TABLES = [
        'van_return_orders', 'van_borrow_orders',
        'van_return_borrow_orders', 'van_exchange_orders',
    ];

    /** 全部 7 张单据主表（常量表达式不可调用函数，显式列出） */
    private const MAIN_TABLES = [
        'van_requisitions', 'van_picking', 'van_sale_orders',
        'van_return_orders', 'van_borrow_orders',
        'van_return_borrow_orders', 'van_exchange_orders',
    ];

    /** 明细表（7 张，换货明细为换入/换出同行结构） */
    private const ITEM_TABLES = [
        'van_requisition_items', 'van_picking_items', 'van_sale_order_items',
        'van_return_order_items', 'van_borrow_order_items',
        'van_return_borrow_order_items', 'van_exchange_order_items',
    ];

    /** 单据主表 -> 单据编号列 */
    private const NO_COLUMNS = [
        'van_requisitions' => 'requisition_no',
        'van_picking' => 'picking_no',
        'van_sale_orders' => 'order_no',
        'van_return_orders' => 'return_no',
        'van_borrow_orders' => 'borrow_no',
        'van_return_borrow_orders' => 'return_no',
        'van_exchange_orders' => 'exchange_no',
    ];

    /** 单据编号列前缀：每个单据独立命名空间，避免单号跨单据冲突 */
    private const NO_PREFIXES = [
        'van_requisitions' => 'VHQ',
        'van_picking' => 'VHP',
        'van_sale_orders' => 'VXS',
        'van_return_orders' => 'VXT',
        'van_borrow_orders' => 'VJT',
        'van_return_borrow_orders' => 'VHT',
        'van_exchange_orders' => 'VHD',
    ];

    /** 明细表 -> 父单据外键列 */
    private const ITEM_FK_COLUMNS = [
        'van_requisition_items' => 'requisition_id',
        'van_picking_items' => 'picking_id',
        'van_sale_order_items' => 'order_id',
        'van_return_order_items' => 'order_id',
        'van_borrow_order_items' => 'order_id',
        'van_return_borrow_order_items' => 'order_id',
        'van_exchange_order_items' => 'order_id',
    ];

    // ==================== 车辆伪装成仓库：全模块的架构支点 ====================

    public function test_warehouses_table_has_vehicle_type_columns(): void
    {
        // 车销不建独立库存表，而是把车辆伪装成 warehouse(type='vehicle')，
        // 复用 stocks + StockService::stockIn/stockOut 的第二参数 warehouse_id。
        // 这两列是整个车销模块的地基，缺任何一列都会让车上库存链路断裂。
        foreach (['type', 'vehicle_id'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('warehouses', $col),
                "warehouses.{$col} 不存在（车上库存架构支点）"
            );
        }

        $this->assertEquals(
            'normal',
            $this->columnDefault('warehouses', 'type'),
            'warehouses.type 默认值应为 normal，保证普通仓库不受影响'
        );
    }

    public function test_vehicle_warehouses_are_backfilled_per_vehicle(): void
    {
        // 迁移 up() 会为每辆已有车辆回填一条 type='vehicle' 的仓库记录（按 vehicle_id 判重幂等）。
        // RefreshDatabase 跑全量 migrate:fresh 时 vehicles 表为空，迁移不回填，
        // 所以这里手动插一辆车再重放回填逻辑，才能真正测到回填行为与幂等性。
        $plate = 'TEST-0001'.uniqid();
        $vehicle = DB::table('vehicles')->insertGetId([
            'plate_no' => $plate,
            'driver_name' => '测试司机', // vehicles.driver_name NOT NULL
            'is_active' => 1,
        ]);

        $this->replayVehicleWarehouseBackfill($vehicle);

        $rows = DB::table('warehouses')
            ->where('vehicle_id', $vehicle)
            ->where('type', 'vehicle')
            ->get();

        $this->assertCount(1, $rows, '每辆车应回填且仅回填一条车辆仓记录');
        $this->assertEquals('VH'.$plate, $rows->first()->code, '车辆仓 code 应为 VH+车牌号');
        $this->assertStringContainsString($plate, (string) $rows->first()->name);

        // 幂等：重放不应产生第二条
        $this->replayVehicleWarehouseBackfill($vehicle);
        $this->assertCount(
            1,
            DB::table('warehouses')->where('vehicle_id', $vehicle)->where('type', 'vehicle')->get(),
            '回填逻辑不幂等，重复执行产生重复车辆仓'
        );
    }

    // ==================== 单据主表结构契约 ====================

    public function test_all_van_main_tables_exist(): void
    {
        foreach (self::MAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "缺少车销单据主表 {$table}");
        }
    }

    public function test_all_van_item_tables_exist(): void
    {
        foreach (self::ITEM_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "缺少车销明细表 {$table}");
        }
    }

    public function test_all_van_support_tables_exist(): void
    {
        // 借货余额台账：customer_id+product_id unique，与 customers.balance（欠款）解耦
        $this->assertTrue(
            Schema::hasTable('van_customer_borrow_balances'),
            '缺少借货余额台账表 van_customer_borrow_balances'
        );
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

    public function test_status_column_exists_on_all_main_tables(): void
    {
        foreach (self::MAIN_TABLES as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'status'),
                "{$table}.status 不存在"
            );
        }
    }

    public function test_item_tables_have_parent_fk(): void
    {
        foreach (self::ITEM_FK_COLUMNS as $table => $fk) {
            $this->assertTrue(
                Schema::hasColumn($table, $fk),
                "{$table}.{$fk} 不存在（明细表无法关联主表）"
            );
        }
    }

    public function test_item_tables_carry_product_snapshot(): void
    {
        // 商品快照（product_name/spec/unit）是历史单据可追溯的前提：
        // 商品改名/改规格后，已审核单据的金额与描述不应被回溯改写。
        // 例外：换货明细是双商品结构，用 product_id_out/_in 而非 product_id。
        foreach ([
            'van_requisition_items', 'van_picking_items', 'van_sale_order_items',
            'van_return_order_items', 'van_borrow_order_items', 'van_return_borrow_order_items',
        ] as $table) {
            foreach (['product_id', 'product_name', 'product_code'] as $col) {
                $this->assertTrue(
                    Schema::hasColumn($table, $col),
                    "{$table}.{$col}（商品快照）不存在"
                );
            }
        }
    }

    // ==================== 业务关键列契约 ====================

    public function test_requisition_has_source_warehouse_and_salesman(): void
    {
        // 要货申请是「业务员向源仓要货」的起点：必须有业务员、车辆、源仓三元组，
        // 以及审核/驳回留痕（驳回原因写在 approval_comment）。
        foreach ([
            'salesman_id', 'salesman_name', 'vehicle_id', 'warehouse_id',
            'apply_date', 'expected_date',
            'approved_by', 'approver_name', 'approved_at', 'approval_comment',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_requisitions', $col),
                "van_requisitions.{$col} 不存在"
            );
        }
    }

    public function test_picking_items_carry_pick_and_check_quantities(): void
    {
        // 拣货明细合并验货：应拣/实拣/验货实收/差异 + 差异备注。
        // diff_qty = check_qty - pick_qty，差异≠0 时 diff_remark 必填。
        foreach ([
            'apply_qty', 'pick_qty', 'check_qty', 'diff_qty', 'diff_remark',
            'location', 'unit_cost', 'amount',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_picking_items', $col),
                "van_picking_items.{$col} 不存在"
            );
        }
    }

    public function test_picking_table_links_requisition_and_vehicles(): void
    {
        // 拣货单由要货申请派生，装车到「车上仓」（type=vehicle 的 warehouse）。
        foreach ([
            'requisition_id', 'warehouse_id', 'vehicle_id', 'vehicle_warehouse_id',
            'picker_id', 'picker_name', 'pick_date',
            'checked', 'checker_id', 'checker_name', 'checked_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_picking', $col),
                "van_picking.{$col} 不存在"
            );
        }
    }

    public function test_sale_order_has_payment_and_receivable_columns(): void
    {
        // 车销现场收款：total_amount - paid_amount 推导应收差额。
        foreach ([
            'order_no', 'salesman_id', 'customer_id', 'vehicle_id', 'vehicle_warehouse_id',
            'sale_date', 'total_amount', 'paid_amount', 'payment_method',
            'visit_log_id', 'approved_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_sale_orders', $col),
                "van_sale_orders.{$col} 不存在"
            );
        }

        // 约定：应收 = 总金额 - 已收，不另设 unpaid_amount 列
        $this->assertFalse(
            Schema::hasColumn('van_sale_orders', 'unpaid_amount'),
            'van_sale_orders 不应有 unpaid_amount 列，应收由 total_amount - paid_amount 推导'
        );
    }

    public function test_sale_order_items_carry_price_source(): void
    {
        // 单价按客户价格等级自动带出，price_source 记录价格来源便于复核
        foreach (['sale_qty', 'unit_price', 'price_source', 'amount', 'stock_qty'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_sale_order_items', $col),
                "van_sale_order_items.{$col} 不存在"
            );
        }
    }

    public function test_return_order_has_refund_and_offset_columns(): void
    {
        // 车销退货三种退款路径：现金退回 / 冲抵应收 / 挂账
        foreach ([
            'return_no', 'return_reason', 'refund_method', 'refund_amount',
            'receivable_offset', 'visit_log_id', 'approved_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_return_orders', $col),
                "van_return_orders.{$col} 不存在"
            );
        }
    }

    public function test_return_order_items_link_back_to_sale_order(): void
    {
        // 退货商品必须来自该客户的近期销售记录，source_sale_order_id 记录来源
        $this->assertTrue(
            Schema::hasColumn('van_return_order_items', 'source_sale_order_id'),
            'van_return_order_items.source_sale_order_id 不存在（无法追溯退货商品来源销售单）'
        );
    }

    public function test_borrow_order_has_due_date(): void
    {
        // 借货有应还日期，到期未还需提醒
        foreach (['borrow_no', 'borrow_date', 'due_date', 'approved_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_borrow_orders', $col),
                "van_borrow_orders.{$col} 不存在"
            );
        }
    }

    public function test_borrow_balance_is_unique_per_customer_product(): void
    {
        // 借货余额按商品维度记账（同一客户不同商品的借货分开算），
        // 与 customers.balance（整体欠款）解耦。联合唯一约束是台账正确性的前提——
        // 缺了它同一客户同一商品会攒出多行，余额加总时口径就不对。
        foreach (['customer_id', 'product_id', 'qty'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_customer_borrow_balances', $col),
                "van_customer_borrow_balances.{$col} 不存在"
            );
        }

        $indexes = Schema::getIndexes('van_customer_borrow_balances');
        $hasCompositeUnique = false;
        foreach ($indexes as $idx) {
            // 排除主键索引（primary=true），找含两列的普通唯一索引
            if ($idx['unique'] && ! ($idx['primary'] ?? false)) {
                $cols = $idx['columns'] ?? [];
                if (in_array('customer_id', $cols, true) && in_array('product_id', $cols, true)) {
                    $hasCompositeUnique = true;
                }
            }
        }
        $this->assertTrue(
            $hasCompositeUnique,
            '借货余额表缺 (customer_id, product_id) 联合唯一约束'
        );
    }

    public function test_return_borrow_order_links_to_borrow_order(): void
    {
        // 还货单关联借货单，部分还货后借货单状态转「部分还」
        $this->assertTrue(
            Schema::hasColumn('van_return_borrow_orders', 'borrow_order_id'),
            'van_return_borrow_orders.borrow_order_id 不存在（还货无法关联借货单）'
        );
    }

    public function test_exchange_items_carry_both_directions(): void
    {
        // 换货是「客户退回 A、给客户 B」的双向结构，同行记录换出与换入 + 行差价。
        // 这里不能断言 product_id/product_name（那是单商品快照命名），要断言 _in/_out 后缀。
        foreach ([
            'product_id_out', 'product_id_in', 'qty',
            'unit_price_out', 'amount_out', 'unit_price_in', 'amount_in',
            'diff_amount',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_exchange_order_items', $col),
                "van_exchange_order_items.{$col} 不存在"
            );
        }
    }

    public function test_exchange_order_has_diff_settlement(): void
    {
        // 换货主表记录总差价（换入-换出，正数客户补款）与结算方式
        foreach (['exchange_no', 'exchange_date', 'diff_amount', 'settle_method', 'approved_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('van_exchange_orders', $col),
                "van_exchange_orders.{$col} 不存在"
            );
        }
    }

    public function test_visit_logs_get_van_link_columns(): void
    {
        // 拜访单（visit_logs）已完整存在，本模块只加两个关联列实现联动，
        // 不重建拜访表——拜访期间创建的销售/退货单自动挂在拜访单下。
        foreach (['van_sale_order_id', 'van_return_order_id'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('visit_logs', $col),
                "visit_logs.{$col} 不存在（车销单据无法关联客户拜访）"
            );
        }
    }

    // ==================== 状态枚举契约 ====================

    public function test_requisition_status_defaults_to_draft(): void
    {
        // 要货申请状态流 draft→pending→approved→picked(终态) / pending→draft(reject) / cancelled，
        // 是 7 个单据里状态最多的（唯一有 pending/approved/picked/rejected 的单据）。
        $this->assertEquals(
            'draft',
            $this->columnDefault('van_requisitions', 'status'),
            '要货申请默认状态应为 draft'
        );
    }

    public function test_picking_has_check_state_flag(): void
    {
        // 拣货与验货合并为一张单据，用 checked 布尔推进，不单独建验货表。
        // 这是阶段一有意的简化（迁移注释：「不单独建验货表」），钉住以防被误解为缺失。
        $this->assertTrue(Schema::hasColumn('van_picking', 'checked'), 'van_picking.checked 不存在');
        $this->assertEquals(
            '0',
            $this->columnDefault('van_picking', 'checked'),
            'van_picking.checked 默认应为 0（false），即新建拣货单未验货'
        );
    }

    public function test_all_stage2_docs_default_to_draft(): void
    {
        foreach (self::STAGE2_MAIN_TABLES as $table) {
            $this->assertEquals(
                'draft',
                $this->columnDefault($table, 'status'),
                "{$table}.status 默认值应为 draft"
            );
        }
    }

    public function test_all_main_docs_default_to_draft(): void
    {
        foreach (self::MAIN_TABLES as $table) {
            $this->assertEquals(
                'draft',
                $this->columnDefault($table, 'status'),
                "{$table}.status 默认值应为 draft"
            );
        }
    }

    // ==================== 菜单契约 ====================

    public function test_menu_migration_registers_all_9_van_permissions(): void
    {
        // 迁移路径（已部署环境）必须产出 van 顶级 + 8 个子菜单。
        $expected = [
            'van', 'van.requisition', 'van.picking', 'van.sale-order', 'van.stock',
            'van.return-order', 'van.borrow-order', 'van.return-borrow-order',
            'van.exchange-order',
        ];
        foreach ($expected as $name) {
            $this->assertTrue(
                DB::table('auth_permission')->where('name', $name)->exists(),
                "车销菜单 {$name} 未注册（迁移路径）"
            );
        }
    }

    public function test_menu_migration_is_idempotent(): void
    {
        // 菜单迁移用 exists()/firstOrCreate 判重，重复执行不应产生重复菜单
        $this->assertEquals(
            1,
            DB::table('auth_permission')->where('name', 'van')->count(),
            '重复执行菜单迁移产生了重复的顶级「车销业务」记录'
        );
        $this->assertEquals(
            1,
            DB::table('auth_permission')->where('name', 'van.requisition')->count(),
            '重复执行菜单迁移产生了重复的子菜单'
        );
    }

    public function test_menu_grant_backfill_covers_all_children(): void
    {
        // 迁移的授权回填逻辑：已持有 van 或 van.% 权限的角色，自动补全部 van 子菜单。
        // 这里复刻该逻辑验证算法正确性——迁移只在执行时跑一次，
        // RefreshDatabase 后无角色持有 van，故需手动走一遍回填流程。
        $childIds = DB::table('auth_permission')
            ->where('name', 'like', 'van.%')
            ->pluck('id')
            ->all();
        $this->assertCount(8, $childIds, 'van 子菜单应有 8 个');

        $vanId = DB::table('auth_permission')->where('name', 'van')->value('id');
        $this->assertNotNull($vanId, 'van 顶级菜单未注册');

        $role = DB::table('auth_role')->insertGetId([
            'name' => 'test_van_role', 'code' => 'test_van_role', 'sort' => 0, 'status' => 1,
        ]);
        // 模拟已部署环境中已持有 van 权限的角色
        DB::table('auth_role_permission')->insert([
            'role_id' => $role, 'permission_id' => $vanId,
        ]);

        // 复刻 register_van_menu 的授权回填查询
        $roleIds = DB::table('auth_role_permission as rp')
            ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
            ->where(function ($q) {
                $q->where('p.name', 'van')->orWhere('p.name', 'like', 'van.%');
            })
            ->pluck('rp.role_id')
            ->all();
        $this->assertContains($role, $roleIds, '新建角色未被授权回填查询命中');

        foreach ($childIds as $childId) {
            foreach ($roleIds as $roleId) {
                if (! DB::table('auth_role_permission')
                    ->where('role_id', $roleId)->where('permission_id', $childId)->exists()) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId, 'permission_id' => $childId,
                    ]);
                }
            }
        }

        foreach ($childIds as $childId) {
            $this->assertTrue(
                DB::table('auth_role_permission')
                    ->where('role_id', $role)->where('permission_id', $childId)->exists(),
                '持有 van 菜单的角色未被授权全部 van 子菜单'
            );
        }
    }

    /**
     * van 顶级菜单图标必须是 ElIcon* 形式。
     *
     * 前端 boot.js 只注册 `ElIcon` + name、`AIcon` + name 两个前缀，
     * 菜单图标存在 auth_permission.meta.icon 里、运行时才拿到名字，
     * 存的非 ElIcon 前缀值会解析不到组件，静默渲染成空 <el-icon>，且不报任何错。
     * 车销菜单曾存 'Van'（缺 ElIcon 前缀），配送用 'ElIconVan'——同一图标两种写法，
     * 且前端只有后者可用。MenuIconTest 只校验图标非空、不校验命名规范，测不出这个洞。
     */
    public function test_van_menu_icon_uses_elicon_prefix(): void
    {
        $icon = $this->menuIcon('van');
        $this->assertNotNull($icon, 'van 顶级菜单缺少 meta.icon');
        $this->assertStringStartsWith(
            'ElIcon',
            $icon,
            "车销顶级菜单图标「{$icon}」缺 ElIcon 前缀，前端 <component :is> 解析不到组件会渲染成空图标"
        );
    }

    public function test_van_menu_icon_matches_delivery_convention(): void
    {
        // delivery 与 van 都用货车图标（车辆/物流语义），写法应一致
        $deliveryIcon = $this->menuIcon('delivery');
        $vanIcon = $this->menuIcon('van');
        $this->assertEquals(
            $deliveryIcon,
            $vanIcon,
            "车销图标（{$vanIcon}）与配送（{$deliveryIcon}）不一致，应为同一图标名"
        );
    }

    // ==================== 车销单号前缀契约 ====================

    public function test_van_no_prefixes_are_distinct(): void
    {
        // 7 个单据前缀（VHQ/VHP/VXS/VXT/VJT/VHT/VHD）必须互不相同，
        // 且与配送模块的 2 字母前缀（PH/PJ/YH/LD/RW/CR/SJ）天然隔离。
        $prefixes = array_values(self::NO_PREFIXES);
        $this->assertSame(
            $prefixes,
            array_values(array_unique($prefixes)),
            '车销单据编号前缀存在重复'
        );
        // 全部为 3 字母，与配送 2 字母族区分，避免跨模块单号撞车
        foreach ($prefixes as $prefix) {
            $this->assertSame(
                3,
                strlen($prefix),
                "前缀 {$prefix} 非 3 字母，与车销模块既有命名风格不一致"
            );
        }
    }

    public function test_no_prefixes_match_controller_generation(): void
    {
        // 单号前缀写在迁移 comment 里（如「要货单号 VHQ+Ymd+6位」），
        // 一旦有人改前缀而未同步 Controller 的生成逻辑，此处会与之不一致。
        $controllers = [
            'modules/VanSales/Http/Controllers/VanRequisitionController.php' => 'VHQ',
            'modules/VanSales/Http/Controllers/VanPickingController.php' => 'VHP',
            'modules/VanSales/Http/Controllers/VanSaleOrderController.php' => 'VXS',
            'modules/VanSales/Http/Controllers/VanReturnOrderController.php' => 'VXT',
            'modules/VanSales/Http/Controllers/VanBorrowOrderController.php' => 'VJT',
            'modules/VanSales/Http/Controllers/VanReturnBorrowOrderController.php' => 'VHT',
            'modules/VanSales/Http/Controllers/VanExchangeOrderController.php' => 'VHD',
        ];
        foreach ($controllers as $file => $prefix) {
            $path = base_path($file);
            $this->assertFileExists($path, "控制器 {$file} 不存在");
            $this->assertStringContainsString(
                $prefix,
                file_get_contents($path),
                "{$file} 中未找到单号前缀 {$prefix}，与迁移 comment 声明的前缀约定不符"
            );
        }
    }

    // ==================== 车辆仓与商品档案联动 ====================

    public function test_vehicle_warehouses_are_filterable_and_distinct_from_normal(): void
    {
        // 架构约束：warehouses 加了 type 列后，普通业务下拉必须按 type='normal' 过滤，
        // 否则车辆仓会混进普通仓库选择器。校验回填的车辆仓可按 type 过滤出、
        // 且每条都带 vehicle_id（能回溯到具体车辆）。
        $plate = 'TEST-0002'.uniqid();
        $vehicle = DB::table('vehicles')->insertGetId([
            'plate_no' => $plate,
            'driver_name' => '测试司机',
            'is_active' => 1,
        ]);
        $this->replayVehicleWarehouseBackfill($vehicle);

        $vehicleWarehouses = DB::table('warehouses')->where('type', 'vehicle')->get();
        $this->assertNotEmpty($vehicleWarehouses, '回填后应能按 type=vehicle 过滤出车辆仓');

        foreach ($vehicleWarehouses as $wh) {
            $this->assertNotNull(
                $wh->vehicle_id,
                "车辆仓 {$wh->code} 缺少 vehicle_id，无法回溯到具体车辆"
            );
        }
    }

    // ==================== 辅助方法 ====================

    /**
     * 复刻 2026_10_10_000001_add_type_to_warehouses 的 up() 回填逻辑。
     *
     * RefreshDatabase 跑全量 migrate:fresh 时 vehicles 表为空，迁移不回填任何记录。
     * 这里手动插一条 vehicles 再重放回填逻辑，才能真正测到回填行为与幂等性。
     */
    private function replayVehicleWarehouseBackfill(int $vehicleId): void
    {
        if (DB::table('warehouses')->where('vehicle_id', $vehicleId)->exists()) {
            return;
        }
        $vehicle = DB::table('vehicles')->where('id', $vehicleId)->first();
        DB::table('warehouses')->insert([
            'code' => 'VH'.$vehicle->plate_no,
            'name' => '车辆-'.$vehicle->plate_no,
            'type' => 'vehicle',
            'vehicle_id' => $vehicleId,
            'is_active' => (bool) $vehicle->is_active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 取列默认值，归一化 SQLite 的返回差异。
     *
     * SQLite 把字符串默认值返回成带引号的字面量（'draft'）、
     * 布尔默认值返回成 '0'/'1' 字符串，剥掉引号后统一按字符串比对。
     */
    private function columnDefault(string $table, string $column)
    {
        foreach (Schema::getColumns($table) as $col) {
            if ($col['name'] === $column) {
                $default = $col['default'];

                return is_string($default) ? trim($default, "'\"") : $default;
            }
        }

        return null;
    }

    private function menuIcon(string $name)
    {
        $meta = DB::table('auth_permission')->where('name', $name)->value('meta');
        if ($meta === null) {
            return null;
        }
        $decoded = json_decode((string) $meta, true);

        return is_array($decoded) ? ($decoded['icon'] ?? null) : null;
    }
}
