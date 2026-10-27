<?php

namespace Modules\Business\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Permission;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        // 10个顶级菜单
        //
        // 每一行都必须带 meta.icon，不能省略：AuthSeeder 在本 seeder 之前
        // Permission::truncate() 了整张 auth_permission，迁移阶段由
        // fill_missing_menu_icons 补上的图标会随之被清掉，重建完全依赖这里的定义。
        // 漏一行的后果是侧边栏渲染出空 <el-icon> 且不报错（见 MenuIconTest）。
        $topMenus = [
            ['name' => 'home', 'title' => '首页', 'parent_id' => 0, 'path' => '/', 'sort' => 1, 'status' => 1],
            ['name' => 'data', 'title' => '资料管理', 'parent_id' => 0, 'path' => '/business/product', 'sort' => 2, 'status' => 1, 'meta' => ['icon' => 'ElIconDataAnalysis']],
            ['name' => 'price', 'title' => '价格管理', 'parent_id' => 0, 'path' => '/business/cost-prices', 'sort' => 3, 'status' => 1, 'meta' => ['icon' => 'ElIconMoney']],
            ['name' => 'inventory', 'title' => '库存管理', 'parent_id' => 0, 'path' => '/business/stock-check', 'sort' => 4, 'status' => 1, 'meta' => ['icon' => 'ElIconBox']],
            ['name' => 'order', 'title' => '订单管理', 'parent_id' => 0, 'path' => '/business/sales-order', 'sort' => 5, 'status' => 1, 'meta' => ['icon' => 'ElIconDocument']],
            ['name' => 'finance', 'title' => '财务管理', 'parent_id' => 0, 'path' => '/business/payment', 'sort' => 6, 'status' => 1, 'meta' => ['icon' => 'ElIconWallet']],
            ['name' => 'report', 'title' => '报表管理', 'parent_id' => 0, 'path' => '/business/report/sales', 'sort' => 7, 'status' => 1, 'meta' => ['icon' => 'ElIconDataBoard']],
            ['name' => 'office', 'title' => '办公管理', 'parent_id' => 0, 'path' => '/business/mail', 'sort' => 8, 'status' => 1, 'meta' => ['icon' => 'ElIconMemo']],
            ['name' => 'visit', 'title' => '拜访管理', 'parent_id' => 0, 'path' => '/business/route', 'sort' => 9, 'status' => 1, 'meta' => ['icon' => 'ElIconLocation']],
            ['name' => 'miniapp', 'title' => '小程序管理', 'parent_id' => 0, 'path' => '/mp-icons', 'sort' => 10, 'status' => 1, 'meta' => ['icon' => 'ElIconPlatform']],
            ['name' => 'delivery', 'title' => '配送管理', 'parent_id' => 0, 'path' => '/business/delivery-picking', 'sort' => 11, 'status' => 1, 'meta' => ['icon' => 'ElIconVan']],
            // 车销业务：独立顶级，与配送共用 Van 图标（车辆/物流语义）。
            // 必须在 Seeder 里定义：AuthSeeder 会 Permission::truncate()，迁移阶段
            // 注册的 van 菜单连同图标会被清掉，重建完全依赖这里的定义。
            // 图标必须带 ElIcon 前缀（前端 boot.js 只注册 ElIcon*/AIcon*），
            // 存 'Van' 会静默渲染成空 <el-icon>（见 VanSalesSeedFeatureTest）。
            // path 必须指向真实子路由：前端 transformMenusToRoutes 用
            // filter(menu => menu && menu.path) 过滤，path 为空字符串的顶级菜单
            // 会连同整棵子树被丢弃（2026-10-09 生产实测：van 菜单授权已补全，
            // 但顶级 path='' 仍导致左侧无"车销业务"入口）。与 delivery 写法一致。
            ['name' => 'van', 'title' => '车销业务', 'parent_id' => 0, 'path' => '/business/van-requisition', 'sort' => 12, 'status' => 1, 'meta' => ['icon' => 'ElIconVan']],
        ];

        foreach ($topMenus as $menu) {
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $menu['parent_id'],
                'path' => $menu['path'],
                'component' => null,
                'sort' => $menu['sort'],
                'status' => $menu['status'],
                'meta' => $menu['meta'] ?? null,
            ]);
        }

        // 智慧大屏：单个页面的顶级菜单，必须自己带 component（顶级菜单的 component
        // 在上面统一置空，这里单独写回去），否则 path 没有组件可渲染，点进去是空白。
        // icon 必须带 ElIcon 前缀（boot.js 只注册 ElIcon*/AIcon*）。
        Permission::firstOrCreate(['name' => 'bigscreen'], [
            'title' => '智慧大屏',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/big-screen',
            'component' => 'dashboard/index',
            'sort' => 90,
            'status' => 1,
            'meta' => ['icon' => 'ElIconMonitor'],
        ]);

        // 借还货 / 换货：AuthSeeder 会 truncate auth_permission，迁移阶段注册的同名菜单
        // 会被清掉，这里负责全新安装路径的重建（同 van / delivery 的做法）。
        // 分组菜单只定 path（指向第一个子路由）+ component 空串，子路由才是真正的页面。
        foreach ([
            [
                'top' => ['name' => 'borrow-return', 'title' => '借还货管理', 'parent' => 'inventory', 'path' => '/business/borrow-order', 'sort' => 40, 'icon' => 'ElIconRefresh'],
                'children' => [
                    ['name' => 'borrow.order', 'title' => '借货单', 'path' => '/business/borrow-order', 'component' => 'business/borrow-return/borrow-order/index', 'sort' => 1],
                    ['name' => 'borrow.return', 'title' => '还货单', 'path' => '/business/return-order', 'component' => 'business/borrow-return/return-order/index', 'sort' => 2],
                    ['name' => 'borrow.summary', 'title' => '借还货查询', 'path' => '/business/borrow-summary', 'component' => 'business/borrow-return/borrow-summary/index', 'sort' => 3],
                ],
            ],
            [
                'top' => ['name' => 'exchange', 'title' => '换货管理', 'parent' => 'inventory', 'path' => '/business/exchange-order', 'sort' => 50, 'icon' => 'ElIconSort'],
                'children' => [
                    ['name' => 'exchange.order', 'title' => '换货单', 'path' => '/business/exchange-order', 'component' => 'business/exchange/exchange-order/index', 'sort' => 1],
                    ['name' => 'exchange.summary', 'title' => '换货查询', 'path' => '/business/exchange-summary', 'component' => 'business/exchange/exchange-summary/index', 'sort' => 2],
                ],
            ],
        ] as $group) {
            $top = $group['top'];
            $parentId = Permission::where('name', $top['parent'])->value('id');

            Permission::firstOrCreate(['name' => $top['name']], [
                'title' => $top['title'],
                'type' => 'menu',
                'parent_id' => $parentId ?? 0,
                'path' => $top['path'],
                'component' => '',
                'sort' => $top['sort'],
                'status' => 1,
                'meta' => ['icon' => $top['icon']],
            ]);

            $groupId = Permission::where('name', $top['name'])->value('id');

            foreach ($group['children'] as $child) {
                Permission::firstOrCreate(['name' => $child['name']], [
                    'title' => $child['title'],
                    'type' => 'menu',
                    'parent_id' => $groupId,
                    'path' => $child['path'],
                    'component' => $child['component'],
                    'sort' => $child['sort'],
                    'status' => 1,
                ]);
            }
        }

        // 会员管理：顶级分组菜单。AuthSeeder 会 truncate auth_permission，
        // 迁移阶段注册的 member 菜单会被清掉，这里负责全新安装路径的重建。
        // 分组菜单 component 空串 + path 指向第一个子路由（对齐借还货分组的硬约定）。
        Permission::firstOrCreate(['name' => 'member'], [
            'title' => '会员管理',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/business/member',
            'component' => '',
            'sort' => 13,
            'status' => 1,
            'meta' => ['icon' => 'ElIconUser'],
        ]);
        $memberTopId = Permission::where('name', 'member')->value('id');
        foreach ([
            ['name' => 'member.list', 'title' => '会员档案', 'path' => '/business/member', 'component' => 'business/member/index', 'sort' => 1],
            ['name' => 'member.level', 'title' => '会员等级', 'path' => '/business/member-level', 'component' => 'business/member-level/index', 'sort' => 2],
            ['name' => 'member.point-rule', 'title' => '积分规则', 'path' => '/business/point-rule', 'component' => 'business/point-rule/index', 'sort' => 3],
            ['name' => 'member.recharge', 'title' => '储值充值', 'path' => '/business/member-recharge', 'component' => 'business/member-recharge/index', 'sort' => 4],
            ['name' => 'member.exchange', 'title' => '积分兑换', 'path' => '/business/point-exchange', 'component' => 'business/point-exchange/index', 'sort' => 5],
            ['name' => 'member.consume-log', 'title' => '消费记录', 'path' => '/business/member-consume-log', 'component' => 'business/member-consume-log/index', 'sort' => 6],
        ] as $menu) {
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $memberTopId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 资料管理子菜单
        $dataMenus = [
            ['name' => 'data.product', 'title' => '商品档案', 'parent' => 'data', 'path' => '/business/product', 'component' => 'business/product/index', 'sort' => 1],
            ['name' => 'data.customer', 'title' => '客户档案', 'parent' => 'data', 'path' => '/business/customer', 'component' => 'business/customer/index', 'sort' => 2],
            ['name' => 'data.supplier', 'title' => '供应商档案', 'parent' => 'data', 'path' => '/business/supplier', 'component' => 'business/supplier/index', 'sort' => 3],
            ['name' => 'data.warehouse', 'title' => '仓库管理', 'parent' => 'data', 'path' => '/business/warehouse', 'component' => 'business/warehouse/index', 'sort' => 4],
            ['name' => 'data.employee', 'title' => '员工档案', 'parent' => 'data', 'path' => '/business/employee', 'component' => 'business/employee/index', 'sort' => 5],
            ['name' => 'data.vehicle', 'title' => '车辆档案', 'parent' => 'data', 'path' => '/business/vehicle', 'component' => 'business/vehicle/index', 'sort' => 6],
        ];

        foreach ($dataMenus as $menu) {
            $parentId = Permission::where('name', $menu['parent'])->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 价格管理子菜单
        $priceMenus = [
            ['name' => 'price.cost', 'title' => '成本价格', 'parent' => 'price', 'path' => '/business/cost-prices', 'sort' => 1],
            ['name' => 'price.recent', 'title' => '最近价格', 'parent' => 'price', 'path' => '/business/recent-prices', 'sort' => 2],
        ];

        foreach ($priceMenus as $menu) {
            $parentId = Permission::where('name', $menu['parent'])->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 库存管理子菜单
        //
        // 库存核对必须在这里定义，不能只靠迁移：AuthSeeder 在本 seeder 之前
        // Permission::truncate() 了整张 auth_permission，所以任何一次
        // db:seed / 换库 / 从备份恢复都会丢掉它。2026-09-16 线上就因为这个
        // 丢过一次（见 2026_09_16_000001_restore_inventory_stock_check_menu）。
        //
        // 菜单路径与路由前缀严格对应：path 是前端路由 path，component 是
        // ../views/{component}/index.vue。改 URL 必须前后端同步，
        // 回归由 tests/Feature/InventoryMenuTest.php 的三条用例兜底。
        //
        // scrap / inventory-check 没有任何后端路由和前端 view，点了是 404，
        // 按 status=0 入库；页面补上后改回 1 即可。
        $inventoryMenus = [
            ['name' => 'inventory.stock-history', 'title' => '库存流水', 'parent' => 'inventory', 'path' => '/business/stock-history', 'component' => 'business/stock-history/index', 'sort' => 1],
            ['name' => 'inventory.stock-in', 'title' => '入库管理', 'parent' => 'inventory', 'path' => '/business/stock-in', 'sort' => 2],
            ['name' => 'inventory.stock-out', 'title' => '出库管理', 'parent' => 'inventory', 'path' => '/business/stock-out', 'sort' => 3],
            ['name' => 'inventory.transfer', 'title' => '调拨管理', 'parent' => 'inventory', 'path' => '/business/transfer', 'sort' => 4],
            ['name' => 'inventory.return', 'title' => '退货管理', 'parent' => 'inventory', 'path' => '/business/return', 'sort' => 5],
            ['name' => 'inventory.scrap', 'title' => '报废管理', 'parent' => 'inventory', 'path' => '/business/scrap', 'sort' => 6, 'status' => 0],
            ['name' => 'inventory.check', 'title' => '盘点管理', 'parent' => 'inventory', 'path' => '/business/inventory-check', 'sort' => 7, 'status' => 0],
            ['name' => 'inventory.query', 'title' => '库存查询', 'parent' => 'inventory', 'path' => '/business/stock', 'component' => 'business/stock/index', 'sort' => 8],
            ['name' => 'inventory.stock-check', 'title' => '库存核对', 'parent' => 'inventory', 'path' => '/business/stock-check', 'component' => 'business/stock-check/index', 'sort' => 9],
        ];

        foreach ($inventoryMenus as $menu) {
            $parentId = Permission::where('name', $menu['parent'])->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'component' => $menu['component'] ?? null,
                'sort' => $menu['sort'],
                'status' => $menu['status'] ?? 1,
            ]);
        }

        // 订单管理子菜单
        $orderMenus = [
            ['name' => 'order.sales', 'title' => '销售订单', 'parent' => 'order', 'path' => '/business/sales-order', 'component' => 'business/sales-order/index', 'sort' => 1],
            ['name' => 'order.delivery', 'title' => '发货收款', 'parent' => 'order', 'path' => '/business/delivery', 'sort' => 2],
            ['name' => 'order.dispatch', 'title' => '配送管理', 'parent' => 'order', 'path' => '/business/dispatch', 'sort' => 3],

            ['name' => 'order.sales-return', 'title' => '退货订单', 'parent' => 'order', 'path' => '/business/sales-return', 'sort' => 5],
        ];

        foreach ($orderMenus as $menu) {
            $parentId = Permission::where('name', $menu['parent'])->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'component' => $menu['component'] ?? null,
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 配送管理（独立顶级，完整配送业务闭环）
        // 注：与 order.dispatch 旧壳页面并存；本组为新的配货→拣货→验货→装车→任务→收款→上交闭环入口。
        // 必须在 Seeder 里定义：AuthSeeder 会 truncate auth_permission，迁移阶段补的菜单会被清掉。
        Permission::firstOrCreate(['name' => 'delivery'], [
            'title' => '配送管理',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/business/delivery-picking',
            'component' => null,
            'meta' => ['icon' => 'ElIconVan'],
            'sort' => 6,
            'status' => 1,
        ]);
        $deliveryTopId = Permission::where('name', 'delivery')->value('id');
        $deliveryMenus = [
            ['name' => 'delivery.picking', 'title' => '配货单', 'path' => '/business/delivery-picking', 'component' => 'business/delivery/picking/index', 'sort' => 1],
            ['name' => 'delivery.pick', 'title' => '拣货单', 'path' => '/business/delivery-pick', 'component' => 'business/delivery/pick/index', 'sort' => 2],
            ['name' => 'delivery.check', 'title' => '验货单', 'path' => '/business/delivery-check', 'component' => 'business/delivery/check/index', 'sort' => 3],
            ['name' => 'delivery.load', 'title' => '装车单', 'path' => '/business/delivery-load', 'component' => 'business/delivery/load/index', 'sort' => 4],
            ['name' => 'delivery.task', 'title' => '配送任务', 'path' => '/business/delivery-task', 'component' => 'business/delivery/task/index', 'sort' => 5],
            ['name' => 'delivery.collection', 'title' => '配送收款', 'path' => '/business/delivery-collection', 'component' => 'business/delivery/collection/index', 'sort' => 6],
            ['name' => 'delivery.remit', 'title' => '上交货款', 'path' => '/business/delivery-remit', 'component' => 'business/delivery/remit/index', 'sort' => 7],
        ];
        foreach ($deliveryMenus as $menu) {
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $deliveryTopId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 车销业务子菜单（8 个页面，对应前端 systemRoutes.js 的 8 条 van 路由）
        // path / component 必须与前端路由严格对齐，否则菜单点进去 404 或白屏。
        // 迁移阶段（register_van_menu / register_van_stage2_menu）已注册同名记录，
        // 但 AuthSeeder 的 truncate 会清掉它们，这里负责全新安装路径的重建。
        $vanTopId = Permission::where('name', 'van')->value('id');
        $vanMenus = [
            ['name' => 'van.requisition', 'title' => '要货申请', 'path' => '/business/van-requisition', 'component' => 'business/van-requisition/index', 'sort' => 1],
            ['name' => 'van.picking', 'title' => '拣货验货', 'path' => '/business/van-picking', 'component' => 'business/van-picking/index', 'sort' => 2],
            ['name' => 'van.sale-order', 'title' => '车销销售单', 'path' => '/business/van-sale-order', 'component' => 'business/van-sale-order/index', 'sort' => 3],
            ['name' => 'van.stock', 'title' => '车上库存', 'path' => '/business/van-stock', 'component' => 'business/van-stock/index', 'sort' => 4],
            ['name' => 'van.return-order', 'title' => '车销退货单', 'path' => '/business/van-return-order', 'component' => 'business/van-return-order/index', 'sort' => 5],
            ['name' => 'van.borrow-order', 'title' => '车销借货单', 'path' => '/business/van-borrow-order', 'component' => 'business/van-borrow-order/index', 'sort' => 6],
            ['name' => 'van.return-borrow-order', 'title' => '车销还货单', 'path' => '/business/van-return-borrow-order', 'component' => 'business/van-return-borrow-order/index', 'sort' => 7],
            ['name' => 'van.exchange-order', 'title' => '车销换货单', 'path' => '/business/van-exchange-order', 'component' => 'business/van-exchange-order/index', 'sort' => 8],
            ['name' => 'van.return-to-warehouse', 'title' => '车上退仓', 'path' => '/business/van-return-to-warehouse', 'component' => 'business/van-return-to-warehouse/index', 'sort' => 9],
            ['name' => 'van.remit', 'title' => '上交货款', 'path' => '/business/van-remit', 'component' => 'business/van-remit/index', 'sort' => 10],
        ];
        foreach ($vanMenus as $menu) {
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $vanTopId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 财务管理子菜单
        $financeMenus = [
            ['name' => 'finance.payment', 'title' => '收款单', 'parent' => 'finance', 'path' => '/business/payment', 'sort' => 1],
            ['name' => 'finance.supplier-payment', 'title' => '付款单', 'parent' => 'finance', 'path' => '/business/supplier-payment', 'sort' => 2],
            ['name' => 'finance.expense', 'title' => '费用管理', 'parent' => 'finance', 'path' => '/business/expense', 'sort' => 3],
            ['name' => 'finance.profit', 'title' => '利润查询', 'parent' => 'finance', 'path' => '/business/profit', 'sort' => 4],
            ['name' => 'finance.balance', 'title' => '余额查询', 'parent' => 'finance', 'path' => '/business/balance', 'sort' => 5],
            ['name' => 'finance.expense-item', 'title' => '费用单', 'parent' => 'finance', 'path' => '/business/expenses', 'sort' => 6],
            ['name' => 'finance.other-income', 'title' => '其他收入', 'parent' => 'finance', 'path' => '/business/other-incomes', 'sort' => 7],
            ['name' => 'finance.balance-overview', 'title' => '余额总览', 'parent' => 'finance', 'path' => '/business/balance-overview', 'sort' => 8],
            ['name' => 'finance.receivable', 'title' => '应收账款', 'parent' => 'finance', 'path' => '/business/receivable', 'sort' => 9],
            ['name' => 'finance.payable', 'title' => '应付账款', 'parent' => 'finance', 'path' => '/business/payable', 'sort' => 10],
            ['name' => 'finance.cash-flow', 'title' => '现金流水', 'parent' => 'finance', 'path' => '/business/cash-flow', 'sort' => 11],
        ];

        foreach ($financeMenus as $menu) {
            $parentId = Permission::where('name', $menu['parent'])->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 财务管理 · 财务域 13 页（/finance/*，B5 菜单挂载）
        // 与上方业务资金 /business/* 子菜单并存：两者都属于「财务管理」顶级菜单。
        // name 用 fin: 冒号前缀（前端 v-permission / usePermission 按此匹配），
        // 且与业务子菜单的 finance.* 点号命名区分，保证全部唯一英文。
        // 组件指向真实 vue：前端 transformMenusToRoutes -> loadComponent 解析。
        $financeModuleMenus = [
            ['name' => 'finance.module-balance', 'title' => '余额查询', 'path' => '/finance/balance', 'component' => 'finance/balance/index', 'sort' => 12],
            ['name' => 'finance.journal', 'title' => '日记账', 'path' => '/finance/journal', 'component' => 'finance/journal/index', 'sort' => 13],
            ['name' => 'finance.month-close', 'title' => '财务月结', 'path' => '/finance/month-close', 'component' => 'finance/month-close/index', 'sort' => 14],
            ['name' => 'finance.month-close-query', 'title' => '月结查询', 'path' => '/finance/month-close/query', 'component' => 'finance/month-close-query/index', 'sort' => 15],
            ['name' => 'finance.statement', 'title' => '对账单', 'path' => '/finance/statement', 'component' => 'finance/statement/index', 'sort' => 16],
            ['name' => 'finance.subject-change', 'title' => '科目变化明细表', 'path' => '/finance/subject-change', 'component' => 'finance/subject-change/index', 'sort' => 17],
            ['name' => 'finance.arap-adjustment', 'title' => '应收应付调整', 'path' => '/finance/arap-adjustment', 'component' => 'finance/arap-adjustment/index', 'sort' => 18],
            ['name' => 'finance.borrow-repay', 'title' => '借款还款', 'path' => '/finance/borrow-repay', 'component' => 'finance/borrow-repay/index', 'sort' => 19],
            ['name' => 'finance.customer-balance-adjustment', 'title' => '客户余额调整', 'path' => '/finance/customer-balance-adjustment', 'component' => 'finance/customer-balance-adjustment/index', 'sort' => 20],
            ['name' => 'finance.fund-increase-decrease', 'title' => '资金增减', 'path' => '/finance/fund-increase-decrease', 'component' => 'finance/fund-increase-decrease/index', 'sort' => 21],
            ['name' => 'finance.iou-confirm', 'title' => '欠条确认', 'path' => '/finance/iou-confirm', 'component' => 'finance/iou-confirm/index', 'sort' => 22],
            ['name' => 'finance.supplier-balance-adjustment', 'title' => '供应商余额调整', 'path' => '/finance/supplier-balance-adjustment', 'component' => 'finance/supplier-balance-adjustment/index', 'sort' => 23],
            ['name' => 'finance.voucher', 'title' => '财务凭证', 'path' => '/finance/voucher', 'component' => 'finance/voucher/index', 'sort' => 24],
        ];

        foreach ($financeModuleMenus as $menu) {
            $parentId = Permission::where('name', 'finance')->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 财务管理 · 财务域按钮权限点（fin:*）
        // 前端各页 v-permission="'fin:xxx'" 与路由 meta.perm 依赖这些节点才能命中。
        // 挂在对应 /finance/* 菜单下，类型 button，并统一授权给 super_admin。
        $financeButtons = [
            // 财务凭证（查询视图）
            ['name' => 'fin:voucher:view', 'title' => '查看凭证', 'parent' => 'finance.voucher', 'sort' => 1],
            ['name' => 'fin:voucher:export', 'title' => '导出凭证', 'parent' => 'finance.voucher', 'sort' => 2],
            // 日记账
            ['name' => 'fin:journal:view', 'title' => '查看日记账', 'parent' => 'finance.journal', 'sort' => 1],
            // 月结向导 / 月结查询
            ['name' => 'fin:monthclose:view', 'title' => '查看月结', 'parent' => 'finance.month-close-query', 'sort' => 1],
            // 对账单
            ['name' => 'fin:statement:view', 'title' => '查看对账单', 'parent' => 'finance.statement', 'sort' => 1],
            // 科目变化明细
            ['name' => 'fin:subjectchange:view', 'title' => '查看科目变化', 'parent' => 'finance.subject-change', 'sort' => 1],
            // 应收应付调整
            ['name' => 'fin:arap:adjust', 'title' => '应收应付调整', 'parent' => 'finance.arap-adjustment', 'sort' => 1],
            // 借款还款
            ['name' => 'fin:borrow:manage', 'title' => '借款还款管理', 'parent' => 'finance.borrow-repay', 'sort' => 1],
            // 客户余额调整 / 供应商余额调整
            ['name' => 'fin:balance:adjust', 'title' => '余额调整', 'parent' => 'finance.customer-balance-adjustment', 'sort' => 1],
            // 资金增减
            ['name' => 'fin:fund:adjust', 'title' => '资金增减', 'parent' => 'finance.fund-increase-decrease', 'sort' => 1],
            // 欠条确认
            ['name' => 'fin:iou:confirm', 'title' => '欠条确认', 'parent' => 'finance.iou-confirm', 'sort' => 1],
        ];

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        foreach ($financeButtons as $btn) {
            $parentId = Permission::where('name', $btn['parent'])->value('id');
            $perm = Permission::firstOrCreate(['name' => $btn['name']], [
                'title' => $btn['title'],
                'type' => 'button',
                'parent_id' => $parentId,
                'path' => $btn['name'],
                'component' => null,
                'sort' => $btn['sort'],
                'status' => 1,
            ]);
            if ($superRoleId) {
                DB::table('auth_role_permission')->updateOrInsert(
                    ['role_id' => $superRoleId, 'permission_id' => $perm->id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
        }

        // 报表管理子菜单
        $reportMenus = [
            ['name' => 'report.sales', 'title' => '销售报表', 'parent' => 'report', 'path' => '/business/report/sales', 'sort' => 1],
            ['name' => 'report.stock', 'title' => '库存报表', 'parent' => 'report', 'path' => '/business/report/stock', 'sort' => 2],
            ['name' => 'report.salesman', 'title' => '业务员报表', 'parent' => 'report', 'path' => '/business/report/salesman', 'sort' => 3],
            ['name' => 'report.combined', 'title' => '综合报表', 'parent' => 'report', 'path' => '/business/report/combined', 'sort' => 4],
            ['name' => 'report.customer', 'title' => '客户报表', 'parent' => 'report', 'path' => '/business/report/customer', 'component' => 'business/report/customer/index', 'sort' => 5],
            ['name' => 'report.sales-analysis', 'title' => '销售分析', 'parent' => 'report', 'path' => '/business/report/sales-analysis', 'component' => 'business/report/sales-analysis/index', 'sort' => 7],
            ['name' => 'report.history', 'title' => '经营历程', 'parent' => 'report', 'path' => '/business/history', 'component' => 'business/history/index', 'sort' => 6],
        ];

        foreach ($reportMenus as $menu) {
            $parentId = Permission::where('name', $menu['parent'])->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 办公管理子菜单
        $officeMenus = [
            ['name' => 'office.mail', 'title' => '内部邮件', 'parent' => 'office', 'path' => '/business/mail', 'sort' => 1],
            ['name' => 'office.notice', 'title' => '公司公告', 'parent' => 'office', 'path' => '/business/notice', 'sort' => 2],
        ];

        foreach ($officeMenus as $menu) {
            $parentId = Permission::where('name', $menu['parent'])->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 拜访管理子菜单
        $visitMenus = [
            ['name' => 'visit.route', 'title' => '线路档案', 'parent' => 'visit', 'path' => '/business/route', 'component' => 'business/route/index', 'sort' => 1],
            ['name' => 'visit.visit', 'title' => '拜访管理', 'parent' => 'visit', 'path' => '/business/visit', 'sort' => 2],
            ['name' => 'visit.attendance', 'title' => '考勤管理', 'parent' => 'visit', 'path' => '/business/attendance', 'component' => 'business/attendance/index', 'sort' => 3],
            ['name' => 'visit.achievement', 'title' => '访店达成率', 'parent' => 'visit', 'path' => '/business/visit/achievement', 'sort' => 4],
            ['name' => 'visit.detail', 'title' => '拜访明细', 'parent' => 'visit', 'path' => '/business/visit/detail', 'sort' => 5],
            ['name' => 'visit.schedule', 'title' => '业务员行程', 'parent' => 'visit', 'path' => '/business/visit/schedule', 'sort' => 6],
        ];

        foreach ($visitMenus as $menu) {
            $parentId = Permission::where('name', $menu['parent'])->value('id');
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'component' => $menu['component'] ?? null,
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }

        // 小程序管理子菜单
        $miniappParentId = Permission::where('name', 'miniapp')->value('id');
        Permission::firstOrCreate(['name' => 'miniapp.setting'], [
            'title' => '小程序设置',
            'type' => 'menu',
            'parent_id' => $miniappParentId,
            'path' => '/business/mini-program-settings',
            'sort' => 1,
            'status' => 1,
        ]);

        // 隐藏原业务管理顶级菜单，保持兼容性
        Permission::where('name', 'business')->update(['status' => 0, 'sort' => 20]);

        // 更新现有业务菜单的parent_id
        $parentUpdates = [
            'business.product' => ['parent' => 'data', 'sort' => 101],
            'business.customer' => ['parent' => 'data', 'sort' => 102],
            'business.supplier' => ['parent' => 'data', 'sort' => 103],
            'business.warehouse' => ['parent' => 'data', 'sort' => 104],
            'business.employee' => ['parent' => 'data', 'sort' => 105],
            'business.vehicle' => ['parent' => 'data', 'sort' => 106],
            'business.route' => ['parent' => 'visit', 'sort' => 107],
            'business.sales-order' => ['parent' => 'order', 'sort' => 108],
            'business.stock-history' => ['parent' => 'inventory', 'sort' => 109],
            'business.stock' => ['parent' => 'inventory', 'sort' => 110],
            'business.attendance' => ['parent' => 'visit', 'sort' => 111],
        ];

        foreach ($parentUpdates as $name => $updates) {
            $parentId = Permission::where('name', $updates['parent'])->value('id');
            Permission::where('name', $name)->update([
                'parent_id' => $parentId,
                'sort' => $updates['sort'],
            ]);
        }
    }
}
