<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 修复生产菜单排序及缺失项
     * 1. 添加库存核对（库存管理子菜单 sort=9）
     * 2. 费用管理/付款管理/收款管理 归入财务管理（sort=3/4/5）
     * 3. 顶级菜单排序：首页→资料→价格→库存→订单→财务→报表→办公→拜访→小程序→权限→系统
     */
    public function up(): void
    {
        // 1. 添加库存核对（库存管理最后一级，sort=9）
        $inventoryId = DB::table('auth_permission')->where('name', 'inventory')->value('id');
        if ($inventoryId) {
            DB::table('auth_permission')->updateOrInsert(
                ['name' => 'inventory.stock-check'],
                [
                    'title' => '库存核对',
                    'name' => 'inventory.stock-check',
                    'type' => 'menu',
                    'parent_id' => $inventoryId,
                    'path' => '/business/stock-check',
                    'component' => 'business/stock-check/index',
                    'sort' => 9,
                    'status' => 1,
                ]
            );
        }

        // 2. 将费用管理/付款管理/收款管理 归入财务管理子菜单
        $financeId = DB::table('auth_permission')->where('name', 'finance')->value('id');
        if ($financeId) {
            DB::table('auth_permission')->where('name', 'finance.expense')->update(['parent_id' => $financeId]);
            DB::table('auth_permission')->where('name', 'finance.supplier-payment')->update(['parent_id' => $financeId]);
            DB::table('auth_permission')->where('name', 'finance.payment')->update(['parent_id' => $financeId]);
        }

        // 3. 修复顶级菜单排序（按库存菜单顺序）
        // 首页(1) 资料管理(2) 价格管理(3) 库存管理(4) 订单管理(5) 财务管理(6)
        // 报表管理(7) 办公管理(8) 拜访管理(9) 小程序管理(10) 权限(15) 系统(20)
        $topMenus = [
            'home'          => 1,
            'data'          => 2,
            'price'         => 3,
            'inventory'     => 4,
            'order'         => 5,
            'finance'       => 6,
            'report'        => 7,
            'office'        => 8,
            'visit'         => 9,
            'miniapp'       => 10,
            'auth'          => 15,
            'system'        => 20,
        ];
        foreach ($topMenus as $name => $sort) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->where('parent_id', 0)
                ->update(['sort' => $sort]);
        }

        // 4. 确保子菜单排序正确
        $childOrders = [
            'data.product'      => 1,
            'data.customer'     => 2,
            'data.supplier'     => 3,
            'data.warehouse'    => 4,
            'data.employee'     => 5,
            'data.vehicle'      => 6,
            'price.cost'        => 1,
            'price.recent'      => 2,
            'inventory.purchase'=> 1,
            'inventory.stock-in'=> 2,
            'inventory.stock-out'=> 3,
            'inventory.transfer'=> 4,
            'inventory.return'  => 5,
            'inventory.scrap'   => 6,
            'inventory.check'   => 7,
            'inventory.query'   => 8,
            'inventory.stock-check' => 9,
            'order.sales'       => 1,
            'order.delivery'    => 2,
            'order.dispatch'    => 3,
            'order.transfer'    => 4,
            'order.sales-return'=> 5,
            'finance.payment'         => 1,
            'finance.supplier-payment'=> 2,
            'finance.expense'         => 3,
            'finance.profit'          => 4,
            'finance.balance'         => 5,
            'finance.expense-item'    => 6,
            'finance.other-income'    => 7,
            'finance.balance-overview'=> 8,
            'finance.receivable'      => 9,
            'finance.payable'         => 10,
            'finance.cash-flow'       => 11,
            'report.sales'     => 1,
            'report.stock'     => 2,
            'report.salesman'  => 3,
            'report.combined'  => 4,
            'office.mail'      => 1,
            'office.notice'    => 2,
            'visit.route'      => 1,
            'visit.visit'      => 2,
            'visit.attendance' => 3,
            'visit.achievement'=> 4,
            'visit.detail'     => 5,
            'visit.schedule'   => 6,
            'miniapp.setting'  => 1,
        ];
        foreach ($childOrders as $name => $sort) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->update(['sort' => $sort]);
        }
    }

    public function down(): void
    {
        // 删除库存核对
        DB::table('auth_permission')->where('name', 'inventory.stock-check')->delete();

        // 恢复费用/付款/收款到顶级
        DB::table('auth_permission')->whereIn('name', [
            'finance.expense', 'finance.supplier-payment', 'finance.payment'
        ])->update(['parent_id' => 0]);

        // 恢复原顶级排序
        $restore = ['home'=>1, 'data'=>2, 'price'=>3, 'inventory'=>4, 'order'=>5,
                     'finance'=>6, 'report'=>7, 'office'=>8, 'visit'=>9, 'miniapp'=>10,
                     'auth'=>2, 'system'=>3];
        foreach ($restore as $name => $sort) {
            DB::table('auth_permission')->where('name', $name)->update(['sort' => $sort]);
        }
    }
};
