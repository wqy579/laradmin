<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 菜单顺序调整 - 按库存菜单排序，不新增菜单
     */
    public function up(): void
    {
        // 库存菜单顺序映射（仅包含新系统已有的菜单）
        // 新系统菜单名称 -> 旧系统菜单名称映射
        $nameMapping = [
            // 首页
            'home' => '首页',
            
            // 资料管理
            'data.product' => '商品档案',
            'data.customer' => '客户档案',
            'data.supplier' => '供应商档案',
            'data.warehouse' => '仓库档案',
            'data.employee' => '员工档案',
            'data.vehicle' => '车辆档案',
            'data.company' => '公司档案',
            'data.department' => '事业部档案',
            
            // 价格管理
            'price.cost' => '成本价格',
            'price.recent' => '最近价格',
            'price.promotion' => '促销',
            'price.special' => '特价',
            'price.plan' => '价格方案',
            
            // 库存管理
            'inventory.purchase' => '采购申请',
            'inventory.stock-in' => '进货录单',
            'inventory.stock-out' => '出库录单',
            'inventory.transfer' => '调拨录单',
            'inventory.return' => '退货录单',
            'inventory.scrap' => '报废录单',
            'inventory.check' => '盘点单',
            'inventory.query' => '库存核对',
            'inventory.purchase-query' => '采购查询',
            'inventory.stock-in-query' => '入库查询',
            'inventory.stock-out-query' => '出库查询',
            'inventory.transfer-query' => '调拨查询',
            'inventory.return-query' => '退货查询',
            'inventory.scrap-query' => '报废查询',
            'inventory.product-disassembly' => '商品拆装',
            'inventory.product-disassembly-query' => '商品拆装查询',
            'inventory.order' => '订货录单',
            'inventory.order-query' => '订货查询',
            'inventory.adjust' => '订货调整',
            
            // 订单管理
            'order.sales' => '订单申报',
            'order.sales-manage' => '订单管理',
            'order.delivery' => '发货收款',
            'order.dispatch' => '配送单',
            'order.transfer' => '订单移库',
            'order.sales-return' => '退货录单',
            'order.sales-return-query' => '退货查询',
            'order.print' => '订单打印',
            'order.summary' => '业务员订单汇总',
            
            // 财务管理
            'finance.payment' => '收款单',
            'finance.supplier-payment' => '付款单',
            'finance.expense' => '现金费用单',
            'finance.profit' => '月度利润',
            'finance.balance' => '余额查询',
            'finance.expense-item' => '一般费用单',
            'finance.other-income' => '其他收入单',
            'finance.balance-overview' => '往来对账',
            'finance.receivable' => '应收款明细表',
            'finance.payable' => '供应商余额增加',
            'finance.cash-flow' => '现金收支',
            'finance.journal' => '日记账',
            'finance.voucher' => '财务凭证',
            'finance.monthly' => '财务月结',
            
            // 报表管理
            'report.sales' => '销售报表',
            'report.stock' => '库存明细表',
            'report.salesman' => '业务员销售汇总',
            'report.combined' => '综合报表',
            'report.sales-analysis' => '销售分析',
            'report.stock-analysis' => '库存分析',
            'report.supplier' => '供应商余额一览表',
            'report.customer' => '客户余额一览表',
            'report.price' => '商品价格一览表',
            
            // 办公管理
            'office.mail' => '内部邮件',
            'office.notice' => '公司公告',
            
            // 拜访管理
            'visit.route' => '线路档案',
            'visit.visit' => '拜访明细查询',
            'visit.attendance' => '业务员考勤',
            'visit.achievement' => '访店达成率',
            'visit.detail' => '拜访明细',
            'visit.schedule' => '业务员行程',
            'visit.route-analysis' => '行程路线分析',
            
            // 小程序管理
            'miniapp.setting' => '小程序设置',
        ];
        
        // 按库存菜单顺序重新排序
        // 库存菜单顺序（从旧系统导出，仅保留与新系统有映射的项）
        $stockOrder = [
            'home' => 1,
            'data.product' => 1, 'data.customer' => 2, 'data.supplier' => 3,
            'data.warehouse' => 4, 'data.employee' => 5, 'data.vehicle' => 6,
            'data.company' => 7, 'data.department' => 8,
            'price.plan' => 1, 'price.recent' => 2, 'price.promotion' => 3,
            'price.special' => 4, 'price.cost' => 5,
            'inventory.purchase' => 1, 'inventory.purchase-query' => 2,
            'inventory.stock-in' => 3, 'inventory.stock-in-query' => 4,
            'inventory.stock-out' => 5, 'inventory.stock-out-query' => 6,
            'inventory.transfer' => 7, 'inventory.transfer-query' => 8,
            'inventory.return' => 9, 'inventory.return-query' => 10,
            'inventory.scrap' => 11, 'inventory.scrap-query' => 12,
            'inventory.order' => 13, 'inventory.order-query' => 14,
            'inventory.adjust' => 15, 'inventory.product-disassembly' => 16,
            'inventory.product-disassembly-query' => 17,
            'inventory.check' => 18, 'inventory.query' => 19,
            'order.sales' => 1, 'order.sales-manage' => 2,
            'order.delivery' => 3, 'order.dispatch' => 4,
            'order.transfer' => 5, 'order.sales-return' => 6,
            'order.sales-return-query' => 7, 'order.print' => 8,
            'order.summary' => 9,
            'finance.payment' => 1, 'finance.receivable' => 2,
            'finance.supplier-payment' => 3, 'finance.payable' => 4,
            'finance.expense' => 5, 'finance.expense-item' => 6,
            'finance.other-income' => 7, 'finance.journal' => 8,
            'finance.profit' => 9, 'finance.balance' => 10,
            'finance.balance-overview' => 11, 'finance.cash-flow' => 12,
            'finance.voucher' => 13, 'finance.monthly' => 14,
            'report.sales' => 1, 'report.stock' => 2,
            'report.sales-analysis' => 3, 'report.stock-analysis' => 4,
            'report.salesman' => 5, 'report.combined' => 6,
            'report.customer' => 7, 'report.supplier' => 8,
            'report.price' => 9,
            'office.mail' => 1, 'office.notice' => 2,
            'visit.route' => 1, 'visit.route-analysis' => 2,
            'visit.visit' => 3, 'visit.attendance' => 4,
            'visit.achievement' => 5, 'visit.detail' => 6,
            'visit.schedule' => 7,
            'miniapp.setting' => 1,
        ];
        
        // 更新顶级菜单排序
        $topMenuOrder = [
            'home' => 1,
            'data' => 2,
            'price' => 3,
            'inventory' => 4,
            'order' => 5,
            'finance' => 6,
            'report' => 7,
            'office' => 8,
            'visit' => 9,
            'miniapp' => 10,
        ];
        
        foreach ($topMenuOrder as $name => $sort) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->where('parent_id', 0)
                ->update(['sort' => $sort]);
        }
        
        // 更新子菜单排序
        foreach ($stockOrder as $menuName => $sort) {
            $parentId = DB::table('auth_permission')->where('name', $menuName)->value('parent_id');
            if ($parentId) {
                DB::table('auth_permission')
                    ->where('name', $menuName)
                    ->update(['sort' => $sort]);
            }
        }
        
        // 更新菜标题（与旧系统保持一致）
        $titleUpdates = [
            'home' => ['title' => '首页'],
            'data.product' => ['title' => '商品档案'],
            'data.customer' => ['title' => '客户档案'],
            'data.supplier' => ['title' => '供应商档案'],
            'data.warehouse' => ['title' => '仓库档案'],
            'data.employee' => ['title' => '员工档案'],
            'data.vehicle' => ['title' => '车辆档案'],
            'price.cost' => ['title' => '成本价格'],
            'price.recent' => ['title' => '最近价格'],
            'inventory.purchase' => ['title' => '采购申请'],
            'inventory.stock-in' => ['title' => '进货录单'],
            'inventory.stock-out' => ['title' => '出库录单'],
            'inventory.transfer' => ['title' => '调拨录单'],
            'inventory.return' => ['title' => '退货录单'],
            'inventory.scrap' => ['title' => '报废录单'],
            'inventory.check' => ['title' => '盘点单'],
            'inventory.query' => ['title' => '库存核对'],
            'order.sales' => ['title' => '订单申报'],
            'order.delivery' => ['title' => '发货收款'],
            'finance.payment' => ['title' => '收款单'],
            'finance.supplier-payment' => ['title' => '付款单'],
            'finance.expense' => ['title' => '现金费用单'],
            'finance.profit' => ['title' => '月度利润'],
            'finance.balance' => ['title' => '余额查询'],
            'office.mail' => ['title' => '内部邮件'],
            'office.notice' => ['title' => '公司公告'],
            'visit.route' => ['title' => '线路档案'],
            'visit.visit' => ['title' => '拜访明细查询'],
            'miniapp.setting' => ['title' => '小程序设置'],
        ];
        
        foreach ($titleUpdates as $name => $data) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->update($data);
        }
    }

    public function down(): void
    {
        // 恢复默认排序
        $topMenus = [
            'home' => 1, 'data' => 2, 'price' => 3, 'inventory' => 4,
            'order' => 5, 'finance' => 6, 'report' => 7, 'office' => 8,
            'visit' => 9, 'miniapp' => 10,
        ];
        foreach ($topMenus as $name => $sort) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->where('parent_id', 0)
                ->update(['sort' => $sort]);
        }
        
        // 恢复子菜单默认排序
        $childDefaults = [
            'data.product' => 1, 'data.customer' => 2, 'data.supplier' => 3,
            'data.warehouse' => 4, 'data.employee' => 5, 'data.vehicle' => 6,
            'price.cost' => 1, 'price.recent' => 2,
            'inventory.purchase' => 1, 'inventory.stock-in' => 2,
            'inventory.stock-out' => 3, 'inventory.transfer' => 4,
            'inventory.return' => 5, 'inventory.scrap' => 6,
            'inventory.check' => 7, 'inventory.query' => 8,
            'order.sales' => 1, 'order.delivery' => 2,
            'order.dispatch' => 3, 'order.transfer' => 4,
            'order.sales-return' => 5,
            'finance.payment' => 1, 'finance.supplier-payment' => 2,
            'finance.expense' => 3, 'finance.profit' => 4,
            'finance.balance' => 5,
            'report.sales' => 1, 'report.stock' => 2,
            'report.salesman' => 3, 'report.combined' => 4,
            'office.mail' => 1, 'office.notice' => 2,
            'visit.route' => 1, 'visit.visit' => 2,
            'visit.attendance' => 3, 'visit.achievement' => 4,
            'visit.detail' => 5, 'visit.schedule' => 6,
            'miniapp.setting' => 1,
        ];
        
        foreach ($childDefaults as $name => $sort) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->update(['sort' => $sort]);
        }
    }
};
