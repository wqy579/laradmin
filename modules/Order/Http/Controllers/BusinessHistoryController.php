<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 经营历程：UNION ALL 各业务表，统一展示资金流水。
 * 用原生 SQL 避免 Laravel unionAll + fromSub 嵌套子查询的性能问题。
 */
class BusinessHistoryController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'types'      => 'nullable|array',
            'salesman_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'order_no'   => 'nullable|string|max:50',
            'page'       => 'nullable|integer|min:1',
            'page_size'  => 'nullable|integer|min:1|max:200',
        ]);

        $pageSize = (int) ($validated['page_size'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        // 构建原生 SQL UNION ALL
        $sql = $this->buildUnionSQL($validated);
        $countSql = "SELECT COUNT(*) as cnt FROM ({$sql}) AS unified";

        $total = (int) DB::selectOne($countSql)->cnt ?? 0;

        $offset = ($page - 1) * $pageSize;
        $listSql = "SELECT * FROM ({$sql}) AS unified ORDER BY date DESC, id DESC LIMIT {$pageSize} OFFSET {$offset}";
        $list = DB::select($listSql);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'last_page' => (int) ceil($total / max($pageSize, 1)),
            ],
        ]);
    }

    public function summary(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'types'      => 'nullable|array',
            'salesman_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
        ]);

        $sql = $this->buildUnionSQL($validated);
        $stats = DB::selectOne("
            SELECT
                COALESCE(SUM(income), 0) as total_income,
                COALESCE(SUM(expense), 0) as total_expense,
                COALESCE(SUM(income) - SUM(expense), 0) as net_amount,
                COUNT(*) as total_count
            FROM ({$sql}) AS unified
        ");

        return $this->success([
            'total_income' => round((float) $stats->total_income, 2),
            'total_expense' => round((float) $stats->total_expense, 2),
            'net_amount' => round((float) $stats->net_amount, 2),
            'total_count' => (int) $stats->total_count,
        ]);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'types'      => 'nullable|array',
            'salesman_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'order_no'   => 'nullable|string|max:50',
        ]);

        $sql = $this->buildUnionSQL($validated);
        $list = DB::select("SELECT * FROM ({$sql}) AS unified ORDER BY date DESC");

        $csv = "\u{FEFF}";
        $csv .= "经营历程\n\n";
        $csv .= "日期,单据号,单据类型,往来单位,仓库,业务员,收入金额,支出金额,备注\n";
        foreach ($list as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $row->date ?? '',
                $row->order_no ?? '',
                $row->type_label ?? '',
                $row->partner_name ?? '',
                $row->warehouse_name ?? '',
                $row->salesman_name ?? '',
                $row->income > 0 ? number_format($row->income, 2) : '',
                $row->expense > 0 ? number_format($row->expense, 2) : '',
                str_replace(["\n", "\r", ','], ' ', $row->remark ?? '')
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="经营历程_' . date('Ymd') . '.csv"',
        ]);
    }

    /**
     * 构建原生 SQL UNION ALL 查询。
     * 各子查询独立加 WHERE 条件，减少扫描量。
     */
    private function buildUnionSQL(array $v): string
    {
        $types = $v['types'] ?? [];
        $allTypes = empty($types);

        $parts = [];
        $bindings = [];

        // 辅助：构建公共 WHERE 子句
        $dateCond = function (string $dateField) use ($v): string {
            $cond = '';
            if (! empty($v['start_date'])) $cond .= " AND {$dateField} >= '{$v['start_date']}'";
            if (! empty($v['end_date'])) $cond .= " AND {$dateField} <= '{$v['end_date']}'";
            return $cond;
        };

        // 1. 销售订单（收入）
        if ($allTypes || in_array('sales_order', $types)) {
            $parts[] = "
                SELECT 'sales_order' as type_key, '销售订单' as type_label, 'primary' as type_color,
                       order_date as `date`, order_no, customer_id, NULL as supplier_id,
                       warehouse_id, salesman_id,
                       COALESCE(c.name, '') as partner_name,
                       COALESCE(w.name, '') as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       COALESCE(total_amount - discount_amount, 0) as income,
                       0 as expense,
                       COALESCE(so.remark, '') as remark,
                       id
                FROM sales_orders so
                LEFT JOIN customers c ON c.id = so.customer_id
                LEFT JOIN warehouses w ON w.id = so.warehouse_id
                LEFT JOIN auth_user u ON u.id = so.salesman_id
                WHERE so.status IN ('approved', 'completed')
                {$dateCond('order_date')}"
                . ($v['salesman_id'] ?? null ? " AND salesman_id = {$v['salesman_id']}" : '')
                . ($v['customer_id'] ?? null ? " AND customer_id = {$v['customer_id']}" : '')
                . ($v['warehouse_id'] ?? null ? " AND warehouse_id = {$v['warehouse_id']}" : '');
        }

        // 2. 销售出库（收入，取 paid_amount）
        if ($allTypes || in_array('delivery', $types)) {
            $parts[] = "
                SELECT 'delivery' as type_key, '销售出库' as type_label, 'success' as type_color,
                       d.delivery_date as `date`, d.delivery_no as order_no,
                       d.customer_id, NULL as supplier_id,
                       d.warehouse_id, NULL as salesman_id,
                       COALESCE(c.name, '') as partner_name,
                       COALESCE(w.name, '') as warehouse_name,
                       COALESCE(so.salesman_name, '') as salesman_name,
                       COALESCE(d.paid_amount, 0) as income,
                       0 as expense,
                       COALESCE(d.remark, '') as remark,
                       d.id
                FROM deliveries d
                LEFT JOIN customers c ON c.id = d.customer_id
                LEFT JOIN warehouses w ON w.id = d.warehouse_id
                LEFT JOIN sales_orders so ON so.id = d.order_id
                WHERE d.status >= 1
                {$dateCond('delivery_date')}"
                . ($v['customer_id'] ?? null ? " AND d.customer_id = {$v['customer_id']}" : '')
                . ($v['warehouse_id'] ?? null ? " AND d.warehouse_id = {$v['warehouse_id']}" : '');
        }

        // 3. 采购入库（支出）
        if ($allTypes || in_array('stock_in', $types)) {
            $parts[] = "
                SELECT 'stock_in' as type_key, '采购入库' as type_label, 'success' as type_color,
                       stock_date as `date`, order_no,
                       NULL as customer_id, supplier_id,
                       warehouse_id, NULL as salesman_id,
                       COALESCE(s.name, '') as partner_name,
                       COALESCE(w.name, '') as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       0 as income,
                       COALESCE(total_amount, 0) as expense,
                       COALESCE(si.remark, '') as remark,
                       id
                FROM stock_ins si
                LEFT JOIN suppliers s ON s.id = si.supplier_id
                LEFT JOIN warehouses w ON w.id = si.warehouse_id
                LEFT JOIN auth_user u ON u.id = si.created_by
                WHERE 1=1
                {$dateCond('stock_date')}"
                . ($v['supplier_id'] ?? null ? " AND supplier_id = {$v['supplier_id']}" : '')
                . ($v['warehouse_id'] ?? null ? " AND warehouse_id = {$v['warehouse_id']}" : '');
        }

        // 4. 采购退货（收入）
        if ($allTypes || in_array('purchase_return', $types)) {
            $parts[] = "
                SELECT 'purchase_return' as type_key, '采购退货' as type_label, 'warning' as type_color,
                       return_date as `date`, return_no,
                       NULL as customer_id, supplier_id,
                       warehouse_id, NULL as salesman_id,
                       COALESCE(supplier_name, '') as partner_name,
                       COALESCE(w.name, '') as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       COALESCE(total_amount, 0) as income,
                       0 as expense,
                       COALESCE(pr.remark, '') as remark,
                       id
                FROM purchase_returns pr
                LEFT JOIN warehouses w ON w.id = pr.warehouse_id
                LEFT JOIN auth_user u ON u.id = pr.created_by
                WHERE pr.status = 'approved'
        }

        // 5. 销售退货（收入）
        if ($allTypes || in_array('sales_return', $types)) {
            $parts[] = "
                SELECT 'sales_return' as type_key, '销售退货' as type_label, 'warning' as type_color,
                       return_date as `date`, return_no,
                       customer_id, NULL as supplier_id,
                       warehouse_id, NULL as salesman_id,
                       COALESCE(customer_name, '') as partner_name,
                       COALESCE(w.name, '') as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       COALESCE(total_amount, 0) as income,
                       0 as expense,
                       COALESCE(sr.remark, '') as remark,
                       id
                FROM sales_returns sr
                LEFT JOIN warehouses w ON w.id = sr.warehouse_id
                LEFT JOIN auth_user u ON u.id = sr.created_by
                WHERE sr.status = 'approved'
                {$dateCond('return_date')}"
                . ($v['customer_id'] ?? null ? " AND customer_id = {$v['customer_id']}" : '')
                . ($v['warehouse_id'] ?? null ? " AND warehouse_id = {$v['warehouse_id']}" : '');
        }

        // 6. 收款单（收入）
        if ($allTypes || in_array('receive', $types)) {
            $parts[] = "
                SELECT 'receive' as type_key, '收款单' as type_label, 'danger' as type_color,
                       receive_date as `date`, receive_no as order_no,
                       customer_id, NULL as supplier_id,
                       NULL as warehouse_id, handler_id as salesman_id,
                       COALESCE(c.name, '') as partner_name,
                       '' as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       COALESCE(amount, 0) as income,
                       0 as expense,
                       COALESCE(rc.remark, '') as remark,
                       id
                FROM receives rc
                LEFT JOIN customers c ON c.id = rc.customer_id
                LEFT JOIN auth_user u ON u.id = rc.handler_id
                WHERE rc.status = 1
                {$dateCond('receive_date')}"
                . ($v['customer_id'] ?? null ? " AND customer_id = {$v['customer_id']}" : '')
                . ($v['salesman_id'] ?? null ? " AND handler_id = {$v['salesman_id']}" : '');
        }

        // 7. 付款单（支出）
        if ($allTypes || in_array('pay', $types)) {
            $parts[] = "
                SELECT 'pay' as type_key, '付款单' as type_label, 'warning' as type_color,
                       pay_date as `date`, pay_no as order_no,
                       NULL as customer_id, supplier_id,
                       NULL as warehouse_id, handler_id as salesman_id,
                       COALESCE(s.name, '') as partner_name,
                       '' as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       0 as income,
                       COALESCE(amount, 0) as expense,
                       COALESCE(py.remark, '') as remark,
                       id
                FROM pays py
                LEFT JOIN suppliers s ON s.id = py.supplier_id
                LEFT JOIN auth_user u ON u.id = py.handler_id
                WHERE py.status = 1
                {$dateCond('pay_date')}"
                . ($v['supplier_id'] ?? null ? " AND supplier_id = {$v['supplier_id']}" : '')
                . ($v['salesman_id'] ?? null ? " AND handler_id = {$v['salesman_id']}" : '');
        }

        // 8. 费用单（支出）
        if ($allTypes || in_array('expense', $types)) {
            $parts[] = "
                SELECT 'expense' as type_key, '现金费用' as type_label, 'info' as type_color,
                       expense_date as `date`, expense_no as order_no,
                       NULL as customer_id, NULL as supplier_id,
                       NULL as warehouse_id, handler_id as salesman_id,
                       '' as partner_name,
                       '' as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       0 as income,
                       COALESCE(amount, 0) as expense,
                       CONCAT(COALESCE(expense_type, ''), '：', COALESCE(ex.remark, '')) as remark,
                       id
                FROM expenses ex
                LEFT JOIN auth_user u ON u.id = ex.handler_id
                WHERE ex.status = 1
                {$dateCond('expense_date')}"
                . ($v['salesman_id'] ?? null ? " AND handler_id = {$v['salesman_id']}" : '');
        }

        // 9. 库存盘点（盘盈=收入，盘亏=支出）
        if ($allTypes || in_array('stock_check', $types)) {
            $parts[] = "
                SELECT 'stock_check' as type_key, '库存盘点' as type_label, 'danger' as type_color,
                       check_date as `date`, check_no as order_no,
                       NULL as customer_id, NULL as supplier_id,
                       warehouse_id, created_by as salesman_id,
                       '' as partner_name,
                       COALESCE(w.name, '') as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       CASE WHEN profit_amount > 0 THEN profit_amount ELSE 0 END as income,
                       CASE WHEN loss_amount > 0 THEN loss_amount ELSE 0 END as expense,
                       CONCAT('盘点：', check_type) as remark,
                       id
                FROM stock_checks sc
                LEFT JOIN warehouses w ON w.id = sc.warehouse_id
                LEFT JOIN auth_user u ON u.id = sc.created_by
                WHERE sc.status = 'approved'
                {$dateCond('check_date')}"
                . ($v['warehouse_id'] ?? null ? " AND warehouse_id = {$v['warehouse_id']}" : '')
                . ($v['salesman_id'] ?? null ? " AND created_by = {$v['salesman_id']}" : '');
        }

        // 10. 组装单（支出）
        if ($allTypes || in_array('assembly', $types)) {
            $parts[] = "
                SELECT 'assembly' as type_key, '商品组装' as type_label, 'info' as type_color,
                       assembly_date as `date`, assembly_no as order_no,
                       NULL as customer_id, NULL as supplier_id,
                       warehouse_id, salesman_id,
                       '' as partner_name,
                       COALESCE(w.name, '') as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       0 as income,
                       COALESCE(total_cost, 0) as expense,
                       COALESCE(ac.remark, '') as remark,
                       id
                FROM assembly_orders ac
                LEFT JOIN warehouses w ON w.id = ac.warehouse_id
                LEFT JOIN auth_user u ON u.id = ac.salesman_id
                WHERE ac.status = 'approved'
                {$dateCond('assembly_date')}"
                . ($v['warehouse_id'] ?? null ? " AND warehouse_id = {$v['warehouse_id']}" : '')
                . ($v['salesman_id'] ?? null ? " AND salesman_id = {$v['salesman_id']}" : '');
        }

        // 11. 拆分单（收入）
        if ($allTypes || in_array('split', $types)) {
            $parts[] = "
                SELECT 'split' as type_key, '商品拆分' as type_label, 'success' as type_color,
                       split_date as `date`, split_no as order_no,
                       NULL as customer_id, NULL as supplier_id,
                       warehouse_id, salesman_id,
                       '' as partner_name,
                       COALESCE(w.name, '') as warehouse_name,
                       COALESCE(u.real_name, u.username, '') as salesman_name,
                       COALESCE(total_cost, 0) as income,
                       0 as expense,
                       COALESCE(sp.remark, '') as remark,
                       id
                FROM split_orders sp
                LEFT JOIN warehouses w ON w.id = sp.warehouse_id
                LEFT JOIN auth_user u ON u.id = sp.salesman_id
                WHERE sp.status = 'approved'
                {$dateCond('split_date')}"
                . ($v['warehouse_id'] ?? null ? " AND warehouse_id = {$v['warehouse_id']}" : '')
                . ($v['salesman_id'] ?? null ? " AND salesman_id = {$v['salesman_id']}" : '');
        }

        if (empty($parts)) {
            return "SELECT NULL as type_key, NULL as type_label, NULL as type_color, NULL as `date`, NULL as order_no, NULL as customer_id, NULL as supplier_id, NULL as warehouse_id, NULL as salesman_id, NULL as partner_name, NULL as warehouse_name, NULL as salesman_name, 0 as income, 0 as expense, NULL as remark, 0 as id WHERE 1=0";
        }

        $sql = implode(' UNION ALL ', $parts);

        // order_no 筛选（在外层 WHERE 应用）
        if (! empty($v['order_no'])) {
            $sql = "SELECT * FROM ({$sql}) AS sub WHERE order_no LIKE '%" . addslashes($v['order_no']) . "%'";
        }

        return $sql;
    }
}
