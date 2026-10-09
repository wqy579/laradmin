<?php

namespace Modules\Business\Seeders;

use Illuminate\Database\Seeder;
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

        // 报表管理子菜单
        $reportMenus = [
            ['name' => 'report.sales', 'title' => '销售报表', 'parent' => 'report', 'path' => '/business/report/sales', 'sort' => 1],
            ['name' => 'report.stock', 'title' => '库存报表', 'parent' => 'report', 'path' => '/business/report/stock', 'sort' => 2],
            ['name' => 'report.salesman', 'title' => '业务员报表', 'parent' => 'report', 'path' => '/business/report/salesman', 'sort' => 3],
            ['name' => 'report.combined', 'title' => '综合报表', 'parent' => 'report', 'path' => '/business/report/combined', 'sort' => 4],
            ['name' => 'report.history', 'title' => '经营历程', 'parent' => 'report', 'path' => '/business/history', 'component' => 'business/history/index', 'sort' => 5],
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
