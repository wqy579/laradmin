<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 经营历程：从各业务表 UNION ALL 查询，统一展示所有单据的资金流水。
 * 收入 = 正数（收款、销售订单、销售退货、盘点盘盈、拆分），支出 = 负数（付款、费用、采购入库、采购退货、组装）。
 */
class BusinessHistoryController extends Controller
{
    use ResponseTrait;

    /** 类型 → 标签映射 */
    private const TYPE_LABELS = [
        'sales_order'     => '销售订单',
        'delivery'        => '销售出库',
        'stock_in'        => '采购入库',
        'purchase_return' => '采购退货',
        'sales_return'    => '销售退货',
        'receive'         => '收款单',
        'pay'             => '付款单',
        'expense'         => '现金费用',
        'stock_check'     => '库存盘点',
        'assembly'        => '商品组装',
        'split'           => '商品拆分',
        'transfer'        => '库存调拨',
    ];

    /** 类型 → 颜色标签 */
    private const TYPE_COLORS = [
        'sales_order'     => 'primary',
        'delivery'        => 'success',
        'stock_in'        => 'success',
        'purchase_return' => 'warning',
        'sales_return'    => 'warning',
        'receive'         => 'danger',
        'pay'             => 'warning',
        'expense'         => 'info',
        'stock_check'     => 'danger',
        'assembly'        => 'info',
        'split'           => 'success',
        'transfer'        => 'info',
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'start_date'    => 'nullable|date',
            'end_date'      => 'nullable|date',
            'types'         => 'nullable|array',
            'types.*'       => 'in:sales_order,delivery,stock_in,purchase_return,sales_return,receive,pay,expense,stock_check,assembly,split,transfer',
            'salesman_id'   => 'nullable|integer',
            'customer_id'   => 'nullable|integer',
            'supplier_id'   => 'nullable|integer',
            'warehouse_id'  => 'nullable|integer',
            'min_amount'    => 'nullable|numeric',
            'max_amount'    => 'nullable|numeric|gte:min_amount',
            'order_no'      => 'nullable|string|max:50',
            'page'          => 'nullable|integer|min:1',
            'page_size'     => 'nullable|integer|min:1|max:200',
        ]);

        $pageSize = (int) ($validated['page_size'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        // 构建 UNION ALL 子查询
        $parts = [];

        // 1. 销售订单（收入）
        $parts[] = $this->buildSalesOrderQuery($validated);
        // 2. 销售出库/发货（收入，金额=已收款）
        $parts[] = $this->buildDeliveryQuery($validated);
        // 3. 采购入库（支出）
        $parts[] = $this->buildStockInQuery($validated);
        // 4. 采购退货（收入）
        $parts[] = $this->buildPurchaseReturnQuery($validated);
        // 5. 销售退货（收入）
        $parts[] = $this->buildSalesReturnQuery($validated);
        // 6. 收款单（收入）
        $parts[] = $this->buildReceiveQuery($validated);
        // 7. 付款单（支出）
        $parts[] = $this->buildPayQuery($validated);
        // 8. 费用单（支出）
        $parts[] = $this->buildExpenseQuery($validated);
        // 9. 库存盘点（盘盈=收入，盘亏=支出）
        $parts[] = $this->buildStockCheckQuery($validated);
        // 10. 组装单（支出）
        $parts[] = $this->buildAssemblyQuery($validated);
        // 11. 拆分单（收入）
        $parts[] = $this->buildSplitQuery($validated);
        // 12. 库存调拨（不涉及资金，跳过）

        // 合并子查询并应用筛选
        $mainQuery = DB::query()->fromSub(
            $this->buildUnionAll($parts, $validated),
            'unified'
        );

        // 应用额外筛选条件
        if (! empty($validated['min_amount'])) {
            $mainQuery->where('abs_amount', '>=', (float) $validated['min_amount']);
        }
        if (! empty($validated['max_amount'])) {
            $mainQuery->where('abs_amount', '<=', (float) $validated['max_amount']);
        }
        if (! empty($validated['order_no'])) {
            $mainQuery->where('order_no', 'like', '%'.$validated['order_no'].'%');
        }

        // 获取总数
        $total = (clone $mainQuery)->count();

        // 分页 + 排序
        $list = $mainQuery
            ->orderBy('date', 'desc')
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        return $this->paginated($list, $total, $pageSize, $page);
    }

    /** 汇总数据：收入合计、支出合计、净额 */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'types'      => 'nullable|array',
            'types.*'    => 'in:sales_order,delivery,stock_in,purchase_return,sales_return,receive,pay,expense,stock_check,assembly,split',
            'salesman_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
        ]);

        $unified = $this->buildUnionAll(
            $this->buildAllParts($validated),
            $validated
        );

        $stats = DB::query()->fromSub($unified, 'u')
            ->selectRaw('
                SUM(CASE WHEN income > 0 THEN income ELSE 0 END) as total_income,
                SUM(CASE WHEN expense > 0 THEN expense ELSE 0 END) as total_expense,
                SUM(income - expense) as net_amount,
                COUNT(*) as total_count
            ')
            ->first();

        return $this->success([
            'total_income'  => round((float) ($stats->total_income ?? 0), 2),
            'total_expense' => round((float) ($stats->total_expense ?? 0), 2),
            'net_amount'    => round((float) ($stats->net_amount ?? 0), 2),
            'total_count'   => (int) ($stats->total_count ?? 0),
        ]);
    }

    /** CSV 导出 */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'types'      => 'nullable|array',
            'types.*'    => 'in:sales_order,delivery,stock_in,purchase_return,sales_return,receive,pay,expense,stock_check,assembly,split',
            'salesman_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'order_no'   => 'nullable|string|max:50',
        ]);

        $unified = $this->buildUnionAll(
            $this->buildAllParts($validated),
            $validated
        );
        if (! empty($validated['order_no'])) {
            $unified->where('order_no', 'like', '%'.$validated['order_no'].'%');
        }
        $list = $unified->orderBy('date', 'desc')->get();

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
                $row->remark ?? ''
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="经营历程_'.date('Ymd').'.csv"',
        ]);
    }

    // ========================================================================
    // 各业务表子查询构建
    // ========================================================================

    private function buildAllParts(array $v): array
    {
        return [
            $this->buildSalesOrderQuery($v),
            $this->buildDeliveryQuery($v),
            $this->buildStockInQuery($v),
            $this->buildPurchaseReturnQuery($v),
            $this->buildSalesReturnQuery($v),
            $this->buildReceiveQuery($v),
            $this->buildPayQuery($v),
            $this->buildExpenseQuery($v),
            $this->buildStockCheckQuery($v),
            $this->buildAssemblyQuery($v),
            $this->buildSplitQuery($v),
        ];
    }

    private function buildUnionAll(array $parts, array $v): \Illuminate\Database\Query\Builder
    {
        $base = DB::query()->fromSub($parts[0], 'part');
        for ($i = 1; $i < count($parts); $i++) {
            $base->unionAll(DB::query()->fromSub($parts[$i], 'part_'.$i));
        }
        return $base;
    }

    /** 公共筛选条件：日期、业务员、客户/供应商、仓库 */
    private function applyFilters(\Illuminate\Database\Query\Builder $q, array $v): void
    {
        if (! empty($v['start_date'])) {
            $q->where('date', '>=', $v['start_date']);
        }
        if (! empty($v['end_date'])) {
            $q->where('date', '<=', $v['end_date']);
        }
        if (! empty($v['salesman_id'])) {
            $q->where('salesman_id', $v['salesman_id']);
        }
        if (! empty($v['customer_id'])) {
            $q->where('customer_id', $v['customer_id']);
        }
        if (! empty($v['supplier_id'])) {
            $q->where('supplier_id', $v['supplier_id']);
        }
        if (! empty($v['warehouse_id'])) {
            $q->where('warehouse_id', $v['warehouse_id']);
        }
    }

    // ---------- 1. 销售订单 ----------
    private function buildSalesOrderQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('sales_orders')
            ->select(
                DB::raw("'sales_order' as type_key"),
                DB::raw(self::TYPE_LABELS['sales_order'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['sales_order'] . ' as type_color'),
                'order_date as date',
                'order_no',
                'customer_id',
                DB::raw("NULL as supplier_id"),
                'warehouse_id',
                'salesman_id',
                DB::raw('COALESCE(c.name, "") as partner_name'),
                DB::raw('COALESCE(w.name, "") as warehouse_name'),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('COALESCE(total_amount - discount_amount, 0) as income'),
                DB::raw('0 as expense'),
                DB::raw('COALESCE(remark, "") as remark'),
            )
            ->leftJoin('customers as c', 'c.id', '=', 'sales_orders.customer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sales_orders.warehouse_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'sales_orders.salesman_id')
            ->where('sales_orders.status', 'approved')
            ->orWhere('sales_orders.status', 'completed');

        $this->applyFilters($q, $v);

        // 类型筛选
        if (! empty($v['types']) && ! in_array('sales_order', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 2. 销售出库/发货 ----------
    private function buildDeliveryQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('deliveries')
            ->select(
                DB::raw("'delivery' as type_key"),
                DB::raw(self::TYPE_LABELS['delivery'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['delivery'] . ' as type_color'),
                'delivery_date as date',
                'delivery_no as order_no',
                'd.customer_id',
                DB::raw('NULL as supplier_id'),
                'd.warehouse_id',
                DB::raw('NULL as salesman_id'),
                DB::raw('COALESCE(c.name, "") as partner_name'),
                DB::raw('COALESCE(w.name, "") as warehouse_name'),
                DB::raw('COALESCE(so.salesman_name, "") as salesman_name'),
                DB::raw('COALESCE(d.paid_amount, 0) as income'),
                DB::raw('0 as expense'),
                DB::raw('COALESCE(d.remark, "") as remark'),
            )
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'd.order_id')
            ->where('d.status', '>=', 1); // 已发货或已完成

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('delivery', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 3. 采购入库 ----------
    private function buildStockInQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('stock_ins')
            ->select(
                DB::raw("'stock_in' as type_key"),
                DB::raw(self::TYPE_LABELS['stock_in'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['stock_in'] . ' as type_color'),
                'stock_date as date',
                'order_no',
                DB::raw('NULL as customer_id'),
                'supplier_id',
                'warehouse_id',
                DB::raw('NULL as salesman_id'),
                DB::raw('COALESCE(s.name, "") as partner_name'),
                DB::raw('COALESCE(w.name, "") as warehouse_name'),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('0 as income'),
                DB::raw('COALESCE(total_amount, 0) as expense'),
                DB::raw('COALESCE(remark, "") as remark'),
            )
            ->leftJoin('suppliers as s', 's.id', '=', 'stock_ins.supplier_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'stock_ins.warehouse_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'stock_ins.created_by');

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('stock_in', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 4. 采购退货 ----------
    private function buildPurchaseReturnQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('purchase_returns')
            ->select(
                DB::raw("'purchase_return' as type_key"),
                DB::raw(self::TYPE_LABELS['purchase_return'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['purchase_return'] . ' as type_color'),
                'return_date as date',
                'return_no',
                DB::raw('NULL as customer_id'),
                'supplier_id',
                'warehouse_id',
                DB::raw('NULL as salesman_id'),
                DB::raw('COALESCE(supplier_name, "") as partner_name'),
                DB::raw('COALESCE(w.name, "") as warehouse_name'),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('COALESCE(total_amount, 0) as income'),
                DB::raw('0 as expense'),
                DB::raw('COALESCE(remark, "") as remark'),
            )
            ->leftJoin('warehouses as w', 'w.id', '=', 'purchase_returns.warehouse_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'purchase_returns.created_by')
            ->where('purchase_returns.status', 'approved');

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('purchase_return', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 5. 销售退货 ----------
    private function buildSalesReturnQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('sales_returns')
            ->select(
                DB::raw("'sales_return' as type_key"),
                DB::raw(self::TYPE_LABELS['sales_return'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['sales_return'] . ' as type_color'),
                'return_date as date',
                'return_no',
                'customer_id',
                DB::raw('NULL as supplier_id'),
                'warehouse_id',
                DB::raw('NULL as salesman_id'),
                DB::raw('COALESCE(customer_name, "") as partner_name'),
                DB::raw('COALESCE(w.name, "") as warehouse_name'),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('COALESCE(total_amount, 0) as income'),
                DB::raw('0 as expense'),
                DB::raw('COALESCE(remark, "") as remark'),
            )
            ->leftJoin('warehouses as w', 'w.id', '=', 'sales_returns.warehouse_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'sales_returns.created_by')
            ->where('sales_returns.status', 'approved');

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('sales_return', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 6. 收款单 ----------
    private function buildReceiveQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('receives')
            ->select(
                DB::raw("'receive' as type_key"),
                DB::raw(self::TYPE_LABELS['receive'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['receive'] . ' as type_color'),
                'receive_date as date',
                'receive_no as order_no',
                'customer_id',
                DB::raw('NULL as supplier_id'),
                DB::raw('NULL as warehouse_id'),
                'handler_id as salesman_id',
                DB::raw('COALESCE(c.name, "") as partner_name'),
                DB::raw("'' as warehouse_name"),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('COALESCE(amount, 0) as income'),
                DB::raw('0 as expense'),
                DB::raw('COALESCE(remark, "") as remark'),
            )
            ->leftJoin('customers as c', 'c.id', '=', 'receives.customer_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'receives.handler_id')
            ->where('receives.status', 1);

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('receive', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 7. 付款单 ----------
    private function buildPayQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('pays')
            ->select(
                DB::raw("'pay' as type_key"),
                DB::raw(self::TYPE_LABELS['pay'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['pay'] . ' as type_color'),
                'pay_date as date',
                'pay_no as order_no',
                DB::raw('NULL as customer_id'),
                'supplier_id',
                DB::raw('NULL as warehouse_id'),
                'handler_id as salesman_id',
                DB::raw('COALESCE(s.name, "") as partner_name'),
                DB::raw("'' as warehouse_name"),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('0 as income'),
                DB::raw('COALESCE(amount, 0) as expense'),
                DB::raw('COALESCE(remark, "") as remark'),
            )
            ->leftJoin('suppliers as s', 's.id', '=', 'pays.supplier_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'pays.handler_id')
            ->where('pays.status', 1);

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('pay', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 8. 费用单 ----------
    private function buildExpenseQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('expenses')
            ->select(
                DB::raw("'expense' as type_key"),
                DB::raw(self::TYPE_LABELS['expense'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['expense'] . ' as type_color'),
                'expense_date as date',
                'expense_no as order_no',
                DB::raw('NULL as customer_id'),
                DB::raw('NULL as supplier_id'),
                DB::raw('NULL as warehouse_id'),
                'handler_id as salesman_id',
                DB::raw("'' as partner_name"),
                DB::raw("'' as warehouse_name"),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('0 as income'),
                DB::raw('COALESCE(amount, 0) as expense'),
                DB::raw('COALESCE(CONCAT(expense_type, "："), "") . COALESCE(remark, "") as remark'),
            )
            ->leftJoin('auth_user as u', 'u.id', '=', 'expenses.handler_id')
            ->where('expenses.status', 1);

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('expense', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 9. 库存盘点 ----------
    private function buildStockCheckQuery(array $v): \Illuminate\Database\Query\Builder
    {
        // 盘盈 = 收入，盘亏 = 支出，分成两条记录
        $q = DB::table('stock_checks')
            ->select(
                DB::raw("'stock_check' as type_key"),
                DB::raw(self::TYPE_LABELS['stock_check'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['stock_check'] . ' as type_color'),
                'check_date as date',
                'check_no as order_no',
                DB::raw('NULL as customer_id'),
                DB::raw('NULL as supplier_id'),
                'warehouse_id',
                'created_by as salesman_id',
                DB::raw("'' as partner_name"),
                DB::raw('COALESCE(w.name, "") as warehouse_name'),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('CASE WHEN profit_amount > 0 THEN profit_amount ELSE 0 END as income'),
                DB::raw('CASE WHEN loss_amount > 0 THEN loss_amount ELSE 0 END as expense'),
                DB::raw("CONCAT('盘点：', check_type, IF(profit_amount > 0, CONCAT('盘盈', profit_amount), ''), IF(loss_amount > 0, CONCAT('盘亏', loss_amount), '')) as remark"),
            )
            ->leftJoin('warehouses as w', 'w.id', '=', 'stock_checks.warehouse_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'stock_checks.created_by')
            ->where('stock_checks.status', 'approved');

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('stock_check', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 10. 组装单 ----------
    private function buildAssemblyQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('assembly_orders')
            ->select(
                DB::raw("'assembly' as type_key"),
                DB::raw(self::TYPE_LABELS['assembly'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['assembly'] . ' as type_color'),
                'assembly_date as date',
                'assembly_no as order_no',
                DB::raw('NULL as customer_id'),
                DB::raw('NULL as supplier_id'),
                'warehouse_id',
                'salesman_id',
                DB::raw("'' as partner_name"),
                DB::raw('COALESCE(w.name, "") as warehouse_name'),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('0 as income'),
                DB::raw('COALESCE(total_cost, 0) as expense'),
                DB::raw('COALESCE(remark, "") as remark'),
            )
            ->leftJoin('warehouses as w', 'w.id', '=', 'assembly_orders.warehouse_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'assembly_orders.salesman_id')
            ->where('assembly_orders.status', 'approved');

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('assembly', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    // ---------- 11. 拆分单 ----------
    private function buildSplitQuery(array $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('split_orders')
            ->select(
                DB::raw("'split' as type_key"),
                DB::raw(self::TYPE_LABELS['split'] . ' as type_label'),
                DB::raw(self::TYPE_COLORS['split'] . ' as type_color'),
                'split_date as date',
                'split_no as order_no',
                DB::raw('NULL as customer_id'),
                DB::raw('NULL as supplier_id'),
                'warehouse_id',
                'salesman_id',
                DB::raw("'' as partner_name"),
                DB::raw('COALESCE(w.name, "") as warehouse_name'),
                DB::raw('COALESCE(u.real_name, u.username, "") as salesman_name'),
                DB::raw('COALESCE(total_cost, 0) as income'),
                DB::raw('0 as expense'),
                DB::raw('COALESCE(remark, "") as remark'),
            )
            ->leftJoin('warehouses as w', 'w.id', '=', 'split_orders.warehouse_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'split_orders.salesman_id')
            ->where('split_orders.status', 'approved');

        $this->applyFilters($q, $v);

        if (! empty($v['types']) && ! in_array('split', $v['types'])) {
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    /** 分页响应封装 */
    private function paginated($list, int $total, int $pageSize, int $page): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'last_page' => (int) ceil($total / $pageSize),
            ],
        ]);
    }
}
