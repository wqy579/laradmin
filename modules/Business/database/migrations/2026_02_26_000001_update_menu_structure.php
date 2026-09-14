<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 第一步：插入10个新顶级菜单
        $topMenus = [
            ['name' => 'home', 'title' => '首页', 'parent_id' => 0, 'path' => '/', 'component' => null, 'sort' => 1, 'status' => 1],
            ['name' => 'data', 'title' => '资料管理', 'parent_id' => 0, 'path' => '/business/product', 'component' => null, 'sort' => 2, 'status' => 1, 'meta' => ['icon' => 'ElIconDataAnalysis']],
            ['name' => 'price', 'title' => '价格管理', 'parent_id' => 0, 'path' => '/business/cost-prices', 'component' => null, 'sort' => 3, 'status' => 1],
            ['name' => 'inventory', 'title' => '库存管理', 'parent_id' => 0, 'path' => '/business/purchase-order', 'component' => null, 'sort' => 4, 'status' => 1],
            ['name' => 'order', 'title' => '订单管理', 'parent_id' => 0, 'path' => '/business/sales-order', 'component' => null, 'sort' => 5, 'status' => 1],
            ['name' => 'finance', 'title' => '财务管理', 'parent_id' => 0, 'path' => '/business/payment', 'component' => null, 'sort' => 6, 'status' => 1],
            ['name' => 'report', 'title' => '报表管理', 'parent_id' => 0, 'path' => '/business/report/sales', 'component' => null, 'sort' => 7, 'status' => 1],
            ['name' => 'office', 'title' => '办公管理', 'parent_id' => 0, 'path' => '/business/mail', 'component' => null, 'sort' => 8, 'status' => 1],
            ['name' => 'visit', 'title' => '拜访管理', 'parent_id' => 0, 'path' => '/business/route', 'component' => null, 'sort' => 9, 'status' => 1],
            ['name' => 'miniapp', 'title' => '小程序管理', 'parent_id' => 0, 'path' => '/mp-icons', 'component' => null, 'sort' => 10, 'status' => 1],
        ];

        foreach ($topMenus as &$menu) {
            if (isset($menu['meta']) && is_array($menu['meta'])) {
                $menu['meta'] = json_encode($menu['meta']);
            }
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                $menu
            );
        }
        unset($menu);

        // 第二步：插入资料管理子菜单
        $dataMenus = [
            ['name' => 'data.product', 'title' => '商品档案', 'parent_id' => 'data', 'path' => '/business/product', 'component' => 'business/product/index', 'sort' => 1],
            ['name' => 'data.customer', 'title' => '客户档案', 'parent_id' => 'data', 'path' => '/business/customer', 'component' => 'business/customer/index', 'sort' => 2],
            ['name' => 'data.supplier', 'title' => '供应商档案', 'parent_id' => 'data', 'path' => '/business/supplier', 'component' => 'business/supplier/index', 'sort' => 3],
            ['name' => 'data.warehouse', 'title' => '仓库管理', 'parent_id' => 'data', 'path' => '/business/warehouse', 'component' => 'business/warehouse/index', 'sort' => 4],
            ['name' => 'data.employee', 'title' => '员工档案', 'parent_id' => 'data', 'path' => '/business/employee', 'component' => 'business/employee/index', 'sort' => 5],
            ['name' => 'data.vehicle', 'title' => '车辆档案', 'parent_id' => 'data', 'path' => '/business/vehicle', 'component' => 'business/vehicle/index', 'sort' => 6],
        ];

        foreach ($dataMenus as $menu) {
            $parentId = DB::table('auth_permission')->where('name', $menu['parent_id'])->value('id');
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                [
                    'title' => $menu['title'],
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                ]
            );
        }

        // 第三步：插入价格管理子菜单
        $priceMenus = [
            ['name' => 'price.cost', 'title' => '成本价格', 'parent_id' => 'price', 'path' => '/business/cost-prices', 'component' => null, 'sort' => 1],
            ['name' => 'price.recent', 'title' => '最近价格', 'parent_id' => 'price', 'path' => '/business/recent-prices', 'component' => null, 'sort' => 2],
        ];

        foreach ($priceMenus as $menu) {
            $parentId = DB::table('auth_permission')->where('name', $menu['parent_id'])->value('id');
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                [
                    'title' => $menu['title'],
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                ]
            );
        }

        // 第四步：插入库存管理子菜单
        $inventoryMenus = [
            ['name' => 'inventory.purchase', 'title' => '采购管理', 'parent_id' => 'inventory', 'path' => '/business/purchase-order', 'component' => 'business/purchase-order/index', 'sort' => 1],
            ['name' => 'inventory.stock-in', 'title' => '入库管理', 'parent_id' => 'inventory', 'path' => '/business/stock-in', 'component' => null, 'sort' => 2],
            ['name' => 'inventory.stock-out', 'title' => '出库管理', 'parent_id' => 'inventory', 'path' => '/business/stock-out', 'component' => null, 'sort' => 3],
            ['name' => 'inventory.transfer', 'title' => '调拨管理', 'parent_id' => 'inventory', 'path' => '/business/transfer', 'component' => null, 'sort' => 4],
            ['name' => 'inventory.return', 'title' => '退货管理', 'parent_id' => 'inventory', 'path' => '/business/return', 'component' => null, 'sort' => 5],
            ['name' => 'inventory.scrap', 'title' => '报废管理', 'parent_id' => 'inventory', 'path' => '/business/scrap', 'component' => null, 'sort' => 6],
            ['name' => 'inventory.check', 'title' => '盘点管理', 'parent_id' => 'inventory', 'path' => '/business/inventory-check', 'component' => null, 'sort' => 7],
            ['name' => 'inventory.query', 'title' => '库存查询', 'parent_id' => 'inventory', 'path' => '/business/stock', 'component' => 'business/stock/index', 'sort' => 8],
        ];

        foreach ($inventoryMenus as $menu) {
            $parentId = DB::table('auth_permission')->where('name', $menu['parent_id'])->value('id');
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                [
                    'title' => $menu['title'],
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                ]
            );
        }

        // 第五步：插入订单管理子菜单
        $orderMenus = [
            ['name' => 'order.sales', 'title' => '销售订单', 'parent_id' => 'order', 'path' => '/business/sales-order', 'component' => 'business/sales-order/index', 'sort' => 1],
            ['name' => 'order.delivery', 'title' => '发货收款', 'parent_id' => 'order', 'path' => '/business/delivery', 'component' => null, 'sort' => 2],
            ['name' => 'order.dispatch', 'title' => '配送管理', 'parent_id' => 'order', 'path' => '/business/dispatch', 'component' => null, 'sort' => 3],
            ['name' => 'order.transfer', 'title' => '订单移库', 'parent_id' => 'order', 'path' => '/business/order-transfer', 'component' => null, 'sort' => 4],
            ['name' => 'order.sales-return', 'title' => '退货订单', 'parent_id' => 'order', 'path' => '/business/sales-return', 'component' => null, 'sort' => 5],
        ];

        foreach ($orderMenus as $menu) {
            $parentId = DB::table('auth_permission')->where('name', $menu['parent_id'])->value('id');
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                [
                    'title' => $menu['title'],
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                ]
            );
        }

        // 第六步：插入财务管理子菜单
        $financeMenus = [
            ['name' => 'finance.payment', 'title' => '收款单', 'parent_id' => 'finance', 'path' => '/business/payment', 'component' => null, 'sort' => 1],
            ['name' => 'finance.supplier-payment', 'title' => '付款单', 'parent_id' => 'finance', 'path' => '/business/supplier-payment', 'component' => null, 'sort' => 2],
            ['name' => 'finance.expense', 'title' => '费用管理', 'parent_id' => 'finance', 'path' => '/business/expense', 'component' => null, 'sort' => 3],
            ['name' => 'finance.profit', 'title' => '利润查询', 'parent_id' => 'finance', 'path' => '/business/profit', 'component' => null, 'sort' => 4],
            ['name' => 'finance.balance', 'title' => '余额查询', 'parent_id' => 'finance', 'path' => '/business/balance', 'component' => null, 'sort' => 5],
            ['name' => 'finance.expense-item', 'title' => '费用单', 'parent_id' => 'finance', 'path' => '/business/expenses', 'component' => null, 'sort' => 6],
            ['name' => 'finance.other-income', 'title' => '其他收入', 'parent_id' => 'finance', 'path' => '/business/other-incomes', 'component' => null, 'sort' => 7],
            ['name' => 'finance.balance-overview', 'title' => '余额总览', 'parent_id' => 'finance', 'path' => '/business/balance-overview', 'component' => null, 'sort' => 8],
            ['name' => 'finance.receivable', 'title' => '应收账款', 'parent_id' => 'finance', 'path' => '/business/receivable', 'component' => null, 'sort' => 9],
            ['name' => 'finance.payable', 'title' => '应付账款', 'parent_id' => 'finance', 'path' => '/business/payable', 'component' => null, 'sort' => 10],
            ['name' => 'finance.cash-flow', 'title' => '现金流水', 'parent_id' => 'finance', 'path' => '/business/cash-flow', 'component' => null, 'sort' => 11],
        ];

        foreach ($financeMenus as $menu) {
            $parentId = DB::table('auth_permission')->where('name', $menu['parent_id'])->value('id');
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                [
                    'title' => $menu['title'],
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                ]
            );
        }

        // 第七步：插入报表管理子菜单
        $reportMenus = [
            ['name' => 'report.sales', 'title' => '销售报表', 'parent_id' => 'report', 'path' => '/business/report/sales', 'component' => null, 'sort' => 1],
            ['name' => 'report.stock', 'title' => '库存报表', 'parent_id' => 'report', 'path' => '/business/report/stock', 'component' => null, 'sort' => 2],
            ['name' => 'report.salesman', 'title' => '业务员报表', 'parent_id' => 'report', 'path' => '/business/report/salesman', 'component' => null, 'sort' => 3],
            ['name' => 'report.combined', 'title' => '综合报表', 'parent_id' => 'report', 'path' => '/business/report/combined', 'component' => null, 'sort' => 4],
        ];

        foreach ($reportMenus as $menu) {
            $parentId = DB::table('auth_permission')->where('name', $menu['parent_id'])->value('id');
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                [
                    'title' => $menu['title'],
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                ]
            );
        }

        // 第八步：插入办公管理子菜单
        $officeMenus = [
            ['name' => 'office.mail', 'title' => '内部邮件', 'parent_id' => 'office', 'path' => '/business/mail', 'component' => null, 'sort' => 1],
            ['name' => 'office.notice', 'title' => '公司公告', 'parent_id' => 'office', 'path' => '/business/notice', 'component' => null, 'sort' => 2],
        ];

        foreach ($officeMenus as $menu) {
            $parentId = DB::table('auth_permission')->where('name', $menu['parent_id'])->value('id');
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                [
                    'title' => $menu['title'],
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                ]
            );
        }

        // 第九步：插入拜访管理子菜单
        $visitMenus = [
            ['name' => 'visit.route', 'title' => '线路档案', 'parent_id' => 'visit', 'path' => '/business/route', 'component' => 'business/route/index', 'sort' => 1],
            ['name' => 'visit.visit', 'title' => '拜访管理', 'parent_id' => 'visit', 'path' => '/business/visit', 'component' => null, 'sort' => 2],
            ['name' => 'visit.attendance', 'title' => '考勤管理', 'parent_id' => 'visit', 'path' => '/business/attendance', 'component' => 'business/attendance/index', 'sort' => 3],
            ['name' => 'visit.achievement', 'title' => '访店达成率', 'parent_id' => 'visit', 'path' => '/business/visit/achievement', 'component' => null, 'sort' => 4],
            ['name' => 'visit.detail', 'title' => '拜访明细', 'parent_id' => 'visit', 'path' => '/business/visit/detail', 'component' => null, 'sort' => 5],
            ['name' => 'visit.schedule', 'title' => '业务员行程', 'parent_id' => 'visit', 'path' => '/business/visit/schedule', 'component' => null, 'sort' => 6],
        ];

        foreach ($visitMenus as $menu) {
            $parentId = DB::table('auth_permission')->where('name', $menu['parent_id'])->value('id');
            DB::table('auth_permission')->updateOrInsert(
                ['name' => $menu['name']],
                [
                    'title' => $menu['title'],
                    'parent_id' => $parentId,
                    'path' => $menu['path'],
                    'component' => $menu['component'],
                    'sort' => $menu['sort'],
                ]
            );
        }

        // 第十步：插入小程序管理子菜单
        DB::table('auth_permission')->updateOrInsert(
            ['name' => 'miniapp.setting'],
            [
                'title' => '小程序设置',
                'parent_id' => (int)DB::table('auth_permission')->where('name', 'miniapp')->value('id'),
                'path' => '/business/mini-program-settings',
                'component' => null,
                'sort' => 1,
            ]
        );

        // 第十一步：隐藏原业务管理顶级菜单
        DB::table('auth_permission')->where('name', 'business')->update(['status' => 0, 'sort' => 20]);

        // 第十二步：更新现有业务菜单的parent_id
        $parentUpdates = [
            'business.product' => ['parent_id' => 'data', 'sort' => 101],
            'business.customer' => ['parent_id' => 'data', 'sort' => 102],
            'business.supplier' => ['parent_id' => 'data', 'sort' => 103],
            'business.warehouse' => ['parent_id' => 'data', 'sort' => 104],
            'business.employee' => ['parent_id' => 'data', 'sort' => 105],
            'business.vehicle' => ['parent_id' => 'data', 'sort' => 106],
            'business.route' => ['parent_id' => 'visit', 'sort' => 107],
            'business.sales-order' => ['parent_id' => 'order', 'sort' => 108],
            'business.purchase-order' => ['parent_id' => 'inventory', 'sort' => 109],
            'business.stock' => ['parent_id' => 'inventory', 'sort' => 110],
            'business.attendance' => ['parent_id' => 'visit', 'sort' => 111],
        ];

        foreach ($parentUpdates as $name => $updates) {
            $parentId = DB::table('auth_permission')->where('name', $updates['parent_id'])->value('id');
            DB::table('auth_permission')->where('name', $name)->update([
                'parent_id' => $parentId,
                'sort' => $updates['sort'],
            ]);
        }
    }

    public function down(): void
    {
        // 恢复原状：将business子菜单parent_id改回business
        $parentIds = [
            'business.product' => 'business',
            'business.customer' => 'business',
            'business.supplier' => 'business',
            'business.warehouse' => 'business',
            'business.employee' => 'business',
            'business.vehicle' => 'business',
            'business.route' => 'business',
            'business.sales-order' => 'business',
            'business.purchase-order' => 'business',
            'business.stock' => 'business',
            'business.attendance' => 'business',
        ];

        foreach ($parentIds as $name => $parentIdName) {
            $parentId = DB::table('auth_permission')->where('name', $parentIdName)->value('id');
            DB::table('auth_permission')->where('name', $name)->update([
                'parent_id' => $parentId,
                'sort' => DB::table('auth_permission')->where('name', $name)->value('sort') - 100,
            ]);
        }

        // 恢复business菜单状态
        DB::table('auth_permission')->where('name', 'business')->update(['status' => 1, 'sort' => 3]);

        // 删除新增的菜单
        DB::table('auth_permission')->whereNotIn('name', array_keys($parentIds) + ['business'])->whereIn('name', [
            'home', 'data', 'price', 'inventory', 'order', 'finance', 'report', 'office', 'visit', 'miniapp',
            'data.product', 'data.customer', 'data.supplier', 'data.warehouse', 'data.employee', 'data.vehicle',
            'price.cost', 'price.recent',
            'inventory.purchase', 'inventory.stock-in', 'inventory.stock-out', 'inventory.transfer',
            'inventory.return', 'inventory.scrap', 'inventory.check', 'inventory.query',
            'order.sales', 'order.delivery', 'order.dispatch', 'order.transfer', 'order.sales-return',
            'finance.payment', 'finance.supplier-payment', 'finance.expense', 'finance.profit',
            'finance.balance', 'finance.expense-item', 'finance.other-income', 'finance.balance-overview',
            'finance.receivable', 'finance.payable', 'finance.cash-flow',
            'report.sales', 'report.stock', 'report.salesman', 'report.combined',
            'office.mail', 'office.notice',
            'visit.route', 'visit.visit', 'visit.attendance', 'visit.achievement', 'visit.detail', 'visit.schedule',
            'miniapp.setting',
        ])->delete();
    }
};
