<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessHistoryController extends Controller
{
    use ResponseTrait;

    private const TYPE_LABELS = [
        'sales_order' => '销售订单',
        'delivery'    => '销售出库',
        'stock_in'    => '采购入库',
        'purchase_return' => '采购退货',
        'sales_return' => '销售退货',
        'receive'     => '收款单',
        'pay'         => '付款单',
        'expense'     => '现金费用',
        'stock_check' => '库存盘点',
        'assembly'    => '商品组装',
        'split'       => '商品拆分',
    ];

    private const TYPE_COLORS = [
        'sales_order' => 'primary',
        'delivery'    => 'success',
        'stock_in'    => 'success',
        'purchase_return' => 'warning',
        'sales_return' => 'warning',
        'receive'     => 'danger',
        'pay'         => 'warning',
        'expense'     => 'info',
        'stock_check' => 'danger',
        'assembly'    => 'info',
        'split'       => 'success',
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'types'      => 'nullable|array',
            'types.*'    => 'in:sales_order,delivery,stock_in,purchase_return,sales_return,receive,pay,expense,stock_check,assembly,split',
            'salesman_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'order_no'   => 'nullable|string|max:50',
            'page'       => 'nullable|integer|min:1',
            'page_size'  => 'nullable|integer|min:1|max:200',
        ]);

        $pageSize = (int) ($validated['page_size'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        // 构建每个子查询（带独立日期过滤，减少扫描量）
        $parts = [];

        // 1. 销售订单
        if (empty($validated['types']) || in_array('sales_order', $validated['types'])) {
            $q = DB::table('sales_orders')
                ->select(
                    DB::raw("'sales_order' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['sales_order'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['sales_order'] . "' as type_color"),
                    'order_date as date',
                    'order_no',
                    'customer_id',
                    DB::raw('NULL as supplier_id'),
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
                ->where(function ($q) use ($validated) {
                    $q->where('sales_orders.status', 'approved')
                      ->orWhere('sales_orders.status', 'completed');
                })
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('order_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('order_date', '<=', $d))
                ->when($validated['salesman_id'] ?? null, fn($q, $id) => $q->where('salesman_id', $id))
                ->when($validated['customer_id'] ?? null, fn($q, $id) => $q->where('customer_id', $id))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id));
            $parts[] = $q;
        }

        // 2. 销售出库
        if (empty($validated['types']) || in_array('delivery', $validated['types'])) {
            $q = DB::table('deliveries as d')
                ->select(
                    DB::raw("'delivery' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['delivery'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['delivery'] . "' as type_color"),
                    'd.delivery_date as date',
                    'd.delivery_no as order_no',
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
                ->where('d.status', '>=', 1)
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('delivery_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('delivery_date', '<=', $d))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id))
                ->when($validated['customer_id'] ?? null, fn($q, $id) => $q->where('d.customer_id', $id));
            $parts[] = $q;
        }

        // 3. 采购入库
        if (empty($validated['types']) || in_array('stock_in', $validated['types'])) {
            $q = DB::table('stock_ins')
                ->select(
                    DB::raw("'stock_in' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['stock_in'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['stock_in'] . "' as type_color"),
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
                ->leftJoin('auth_user as u', 'u.id', '=', 'stock_ins.created_by')
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('stock_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('stock_date', '<=', $d))
                ->when($validated['supplier_id'] ?? null, fn($q, $id) => $q->where('supplier_id', $id))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id));
            $parts[] = $q;
        }

        // 4. 采购退货
        if (empty($validated['types']) || in_array('purchase_return', $validated['types'])) {
            $q = DB::table('purchase_returns')
                ->select(
                    DB::raw("'purchase_return' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['purchase_return'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['purchase_return'] . "' as type_color"),
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
                ->where('purchase_returns.status', 'approved')
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('return_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('return_date', '<=', $d))
                ->when($validated['supplier_id'] ?? null, fn($q, $id) => $q->where('supplier_id', $id))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id));
            $parts[] = $q;
        }

        // 5. 销售退货
        if (empty($validated['types']) || in_array('sales_return', $validated['types'])) {
            $q = DB::table('sales_returns')
                ->select(
                    DB::raw("'sales_return' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['sales_return'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['sales_return'] . "' as type_color"),
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
                ->where('sales_returns.status', 'approved')
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('return_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('return_date', '<=', $d))
                ->when($validated['customer_id'] ?? null, fn($q, $id) => $q->where('customer_id', $id))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id));
            $parts[] = $q;
        }

        // 6. 收款单
        if (empty($validated['types']) || in_array('receive', $validated['types'])) {
            $q = DB::table('receives')
                ->select(
                    DB::raw("'receive' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['receive'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['receive'] . "' as type_color"),
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
                ->where('receives.status', 1)
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('receive_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('receive_date', '<=', $d))
                ->when($validated['customer_id'] ?? null, fn($q, $id) => $q->where('customer_id', $id))
                ->when($validated['salesman_id'] ?? null, fn($q, $id) => $q->where('handler_id', $id));
            $parts[] = $q;
        }

        // 7. 付款单
        if (empty($validated['types']) || in_array('pay', $validated['types'])) {
            $q = DB::table('pays')
                ->select(
                    DB::raw("'pay' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['pay'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['pay'] . "' as type_color"),
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
                ->where('pays.status', 1)
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('pay_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('pay_date', '<=', $d))
                ->when($validated['supplier_id'] ?? null, fn($q, $id) => $q->where('supplier_id', $id))
                ->when($validated['salesman_id'] ?? null, fn($q, $id) => $q->where('handler_id', $id));
            $parts[] = $q;
        }

        // 8. 费用单
        if (empty($validated['types']) || in_array('expense', $validated['types'])) {
            $q = DB::table('expenses')
                ->select(
                    DB::raw("'expense' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['expense'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['expense'] . "' as type_color"),
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
                ->where('expenses.status', 1)
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('expense_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('expense_date', '<=', $d))
                ->when($validated['salesman_id'] ?? null, fn($q, $id) => $q->where('handler_id', $id));
            $parts[] = $q;
        }

        // 9. 库存盘点
        if (empty($validated['types']) || in_array('stock_check', $validated['types'])) {
            $q = DB::table('stock_checks')
                ->select(
                    DB::raw("'stock_check' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['stock_check'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['stock_check'] . "' as type_color"),
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
                ->where('stock_checks.status', 'approved')
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('check_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('check_date', '<=', $d))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id))
                ->when($validated['salesman_id'] ?? null, fn($q, $id) => $q->where('created_by', $id));
            $parts[] = $q;
        }

        // 10. 组装单
        if (empty($validated['types']) || in_array('assembly', $validated['types'])) {
            $q = DB::table('assembly_orders')
                ->select(
                    DB::raw("'assembly' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['assembly'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['assembly'] . "' as type_color"),
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
                ->where('assembly_orders.status', 'approved')
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('assembly_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('assembly_date', '<=', $d))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id))
                ->when($validated['salesman_id'] ?? null, fn($q, $id) => $q->where('salesman_id', $id));
            $parts[] = $q;
        }

        // 11. 拆分单
        if (empty($validated['types']) || in_array('split', $validated['types'])) {
            $q = DB::table('split_orders')
                ->select(
                    DB::raw("'split' as type_key"),
                    DB::raw("'" . self::TYPE_LABELS['split'] . "' as type_label"),
                    DB::raw("'" . self::TYPE_COLORS['split'] . "' as type_color"),
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
                ->where('split_orders.status', 'approved')
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('split_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('split_date', '<=', $d))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id))
                ->when($validated['salesman_id'] ?? null, fn($q, $id) => $q->where('salesman_id', $id));
            $parts[] = $q;
        }

        if (empty($parts)) {
            return $this->paginated(collect([]), 0, $pageSize, $page);
        }

        // 合并 UNION ALL
        $unified = $parts[0];
        for ($i = 1; $i < count($parts); $i++) {
            $unified->unionAll($parts[$i]);
        }

        // 应用金额筛选（在 UNION 后）
        $mainQuery = DB::query()->fromSub($unified, 'unified')
            ->select('*');

        if (! empty($validated['min_amount'])) {
            $mainQuery->where(function ($q) use ($validated) {
                $q->where('income', '>=', (float) $validated['min_amount'])
                  ->orWhere('expense', '>=', (float) $validated['min_amount']);
            });
        }
        if (! empty($validated['max_amount'])) {
            $mainQuery->where(function ($q) use ($validated) {
                $q->where('income', '<=', (float) $validated['max_amount'])
                  ->orWhere('expense', '<=', (float) $validated['max_amount']);
            });
        }
        if (! empty($validated['order_no'])) {
            $mainQuery->where('order_no', 'like', '%' . $validated['order_no'] . '%');
        }

        // 总数
        $total = (clone $mainQuery)->count();

        // 分页
        $list = $mainQuery
            ->orderBy('date', 'desc')
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

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

    /** 汇总 */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'types'      => 'nullable|array',
            'types.*'    => 'in:sales_order,delivery,stock_in,purchase_return,sales_return,receive,pay,expense,stock_check,assembly,split',
            'salesman_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
        ]);

        // 复用 index 的 parts 构建逻辑（简化版）
        $parts = [];

        // 销售订单
        if (empty($validated['types']) || in_array('sales_order', $validated['types'])) {
            $parts[] = DB::table('sales_orders')
                ->select(
                    DB::raw('COALESCE(total_amount - discount_amount, 0) as income'),
                    DB::raw('0 as expense'),
                )
                ->where(function ($q) { $q->where('status', 'approved')->orWhere('status', 'completed'); })
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('order_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('order_date', '<=', $d))
                ->when($validated['salesman_id'] ?? null, fn($q, $id) => $q->where('salesman_id', $id))
                ->when($validated['customer_id'] ?? null, fn($q, $id) => $q->where('customer_id', $id));
        }

        // 收款单
        if (empty($validated['types']) || in_array('receive', $validated['types'])) {
            $parts[] = DB::table('receives')
                ->select(DB::raw('COALESCE(amount, 0) as income'), DB::raw('0 as expense'))
                ->where('status', 1)
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('receive_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('receive_date', '<=', $d))
                ->when($validated['customer_id'] ?? null, fn($q, $id) => $q->where('customer_id', $id));
        }

        // 采购入库（支出）
        if (empty($validated['types']) || in_array('stock_in', $validated['types'])) {
            $parts[] = DB::table('stock_ins')
                ->select(DB::raw('0 as income'), DB::raw('COALESCE(total_amount, 0) as expense'))
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('stock_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('stock_date', '<=', $d))
                ->when($validated['supplier_id'] ?? null, fn($q, $id) => $q->where('supplier_id', $id))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id));
        }

        // 付款单（支出）
        if (empty($validated['types']) || in_array('pay', $validated['types'])) {
            $parts[] = DB::table('pays')
                ->select(DB::raw('0 as income'), DB::raw('COALESCE(amount, 0) as expense'))
                ->where('status', 1)
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('pay_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('pay_date', '<=', $d))
                ->when($validated['supplier_id'] ?? null, fn($q, $id) => $q->where('supplier_id', $id));
        }

        // 组装单（支出）
        if (empty($validated['types']) || in_array('assembly', $validated['types'])) {
            $parts[] = DB::table('assembly_orders')
                ->select(DB::raw('0 as income'), DB::raw('COALESCE(total_cost, 0) as expense'))
                ->where('status', 'approved')
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('assembly_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('assembly_date', '<=', $d))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id));
        }

        // 拆分单（收入）
        if (empty($validated['types']) || in_array('split', $validated['types'])) {
            $parts[] = DB::table('split_orders')
                ->select(DB::raw('COALESCE(total_cost, 0) as income'), DB::raw('0 as expense'))
                ->where('status', 'approved')
                ->when($validated['start_date'] ?? null, fn($q, $d) => $q->where('split_date', '>=', $d))
                ->when($validated['end_date'] ?? null, fn($q, $d) => $q->where('split_date', '<=', $d))
                ->when($validated['warehouse_id'] ?? null, fn($q, $id) => $q->where('warehouse_id', $id));
        }

        if (empty($parts)) {
            return $this->success(['total_income' => 0, 'total_expense' => 0, 'net_amount' => 0, 'total_count' => 0]);
        }

        $union = $parts[0];
        for ($i = 1; $i < count($parts); $i++) {
            $union->unionAll($parts[$i]);
        }

        $stats = DB::query()->fromSub($union, 'u')
            ->selectRaw('
                COALESCE(SUM(income), 0) as total_income,
                COALESCE(SUM(expense), 0) as total_expense,
                COALESCE(SUM(income), 0) - COALESCE(SUM(expense), 0) as net_amount,
                COUNT(*) as total_count
            ')
            ->first();

        return $this->success([
            'total_income' => round((float) $stats->total_income, 2),
            'total_expense' => round((float) $stats->total_expense, 2),
            'net_amount' => round((float) $stats->net_amount, 2),
            'total_count' => (int) $stats->total_count,
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
            'supplier_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'order_no'   => 'nullable|string|max:50',
        ]);

        // 简化：取全部（不限分页）
        $result = $this->index($request->merge(['page_size' => 10000]));
        $list = $result->getData()->data->list ?? [];

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
                str_replace(["\n", "\r"], '', $row->remark ?? '')
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="经营历程_' . date('Ymd') . '.csv"',
        ]);
    }
}
