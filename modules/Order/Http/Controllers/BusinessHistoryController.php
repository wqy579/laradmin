<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 经营历程：UNION ALL 各业务表，统一展示资金流水。
 * 用原生 SQL 避免 Laravel unionAll + fromSub 嵌套子查询的性能问题。
 *
 * ⚠️ 为什么这里到处在探测列是否存在，而不是直接写死列名：
 *
 * 生产库是历史遗留库，schema 与 modules/*\/database/migrations 下的定义**并不一致**。
 * 典型例子：2026_08_31_000002_create_transaction_tables 里的 stock_ins 建表语句后来
 * 被就地补上了 `remark` 列，但那条迁移在线上早就执行过，`php artisan migrate` 不会
 * 重跑它，于是新列永远补不到老库。查询一旦写死 `si.remark`，线上就是
 * "Unknown column 'si.remark' in 'field list'" 的 500。
 *
 * 这类漂移不是个例：多张业务表都可能缺列、缺表。所以本控制器在**运行时**读取
 * information_schema（Laravel 的 Schema::hasColumn/hasTable，带请求内静态缓存，
 * 每张表每列只查一次），缺列就退化为常量、缺表就整段跳过，保证任何库结构下都能出结果。
 *
 * 代价是 SQL 拼装略啰嗦，收益是「生产库缺列」这一类问题不会再打 500。
 */
class BusinessHistoryController extends Controller
{
    use ResponseTrait;

    /** @var array<string,bool> 列是否存在，键为 "table.column" */
    private static array $columnCache = [];

    /** @var array<string,bool> 表是否存在，键为 table */
    private static array $tableCache = [];

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

        $sql = $this->buildUnionSQL($validated);

        $total = (int) (DB::selectOne("SELECT COUNT(*) as cnt FROM ({$sql}) AS unified")->cnt ?? 0);

        $offset = ($page - 1) * $pageSize;
        $list = DB::select(
            "SELECT * FROM ({$sql}) AS unified ORDER BY `date` DESC, id DESC LIMIT {$pageSize} OFFSET {$offset}"
        );

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
        $list = DB::select("SELECT * FROM ({$sql}) AS unified ORDER BY `date` DESC");

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

    // ---------------------------------------------------------------------
    // schema 探测辅助
    // ---------------------------------------------------------------------

    private function hasTable(string $table): bool
    {
        if (! array_key_exists($table, self::$tableCache)) {
            self::$tableCache[$table] = Schema::hasTable($table);
        }

        return self::$tableCache[$table];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;
        if (! array_key_exists($key, self::$columnCache)) {
            self::$columnCache[$key] = $this->hasTable($table) && Schema::hasColumn($table, $column);
        }

        return self::$columnCache[$key];
    }

    /** 可空外键列：存在则取别名.列，否则常量 NULL */
    private function idExpr(string $alias, string $table, string $column): string
    {
        return $this->hasColumn($table, $column) ? "{$alias}.{$column}" : 'NULL';
    }

    /**
     * 关联表名称列：仅当本表的关联列存在（即该 JOIN 会真的拼上）时才引用别名，
     * 否则退化为空串。漏掉这层判断就会在 JOIN 被跳过时留下悬空的表别名，
     * 变成 "Unknown column 'u.real_name'"。
     */
    private function refNameExpr(string $table, string $joinColumn, string $nameAlias, string $nameColumn = 'name'): string
    {
        return $this->hasColumn($table, $joinColumn) ? "COALESCE({$nameAlias}.{$nameColumn}, '')" : "''";
    }

    /** 业务员姓名：u 别名固定，取 real_name 回落 username */
    private function userNameExpr(string $table, string $joinColumn): string
    {
        return $this->hasColumn($table, $joinColumn) ? "COALESCE(u.real_name, u.username, '')" : "''";
    }

    /**
     * 销售出库的业务员：姓名快照在关联的销售订单上，需 so JOIN 真的拼上才能引用。
     * 两个前提缺一不可——deliveries.order_id 存在（JOIN 才会加）、
     * sales_orders.salesman_name 存在（列才可用）。
     */
    private function deliverySalesmanExpr(): string
    {
        if ($this->hasColumn('deliveries', 'order_id')
            && $this->hasTable('sales_orders')
            && $this->hasColumn('sales_orders', 'salesman_name')) {
            return "COALESCE(so.salesman_name, '')";
        }

        return "''";
    }

    /** 金额列：存在则 COALESCE(别名.列, 0)，否则常量 0 */
    private function numExpr(string $alias, string $table, string $column): string
    {
        return $this->hasColumn($table, $column) ? "COALESCE({$alias}.{$column}, 0)" : '0';
    }

    /** 文本列：存在则别名.列，否则空串 */
    private function strExpr(string $alias, string $table, string $column): string
    {
        return $this->hasColumn($table, $column) ? "{$alias}.{$column}" : "''";
    }

    /** 日期列：优先指定列，其次 created_at，都没有则 NULL */
    private function dateExpr(string $alias, string $table, string $column): string
    {
        if ($this->hasColumn($table, $column)) {
            return "{$alias}.{$column}";
        }

        return $this->hasColumn($table, 'created_at') ? "DATE({$alias}.created_at)" : 'NULL';
    }

    /** 业务员列：优先 salesman_id，其次 created_by / handler_id，都没有则 NULL */
    private function salesmanExpr(string $alias, string $table, array $candidates): string
    {
        foreach ($candidates as $column) {
            if ($this->hasColumn($table, $column)) {
                return "{$alias}.{$column}";
            }
        }

        return 'NULL';
    }

    /** 可选等值过滤：列不存在或值为空时返回空串（该条件不参与过滤） */
    private function filter(?int $value, string $alias, string $table, string $column): string
    {
        if (empty($value) || ! $this->hasColumn($table, $column)) {
            return '';
        }

        return " AND {$alias}.{$column} = ".(int) $value;
    }

    /** 可选 LEFT JOIN：关联列不存在时返回空串，避免 Unknown column */
    private function join(string $sql, string $table, string $column): string
    {
        return $this->hasColumn($table, $column) ? $sql : '';
    }

    /** 日期范围条件（子查询内用） */
    private function dateCond(array $v, string $alias, string $table, string $column): string
    {
        $expr = $this->dateExpr($alias, $table, $column);
        if ($expr === 'NULL') {
            return '';
        }

        $cond = '';
        if (! empty($v['start_date'])) {
            $cond .= " AND {$expr} >= '{$v['start_date']}'";
        }
        if (! empty($v['end_date'])) {
            $cond .= " AND {$expr} <= '{$v['end_date']}'";
        }

        return $cond;
    }

    // ---------------------------------------------------------------------
    // UNION ALL 主体
    // ---------------------------------------------------------------------

    /**
     * 构建原生 SQL UNION ALL 查询。
     * 每个子查询 16 列，顺序固定：type_key, type_label, type_color, `date`,
     * order_no, customer_id, supplier_id, warehouse_id, salesman_id,
     * partner_name, warehouse_name, salesman_name, income, expense, remark, id
     */
    private function buildUnionSQL(array $v): string
    {
        $types = $v['types'] ?? [];
        $allTypes = empty($types);
        $want = fn (string $key): bool => $allTypes || in_array($key, $types, true);

        $parts = [];

        // 1. 销售订单（收入）
        if ($want('sales_order') && $this->hasTable('sales_orders')) {
            $income = $this->hasColumn('sales_orders', 'discount_amount')
                ? 'COALESCE(so.total_amount - so.discount_amount, 0)'
                : $this->numExpr('so', 'sales_orders', 'total_amount');

            $parts[] = "
                SELECT 'sales_order' as type_key, '销售订单' as type_label, 'primary' as type_color,
                       {$this->dateExpr('so', 'sales_orders', 'order_date')} as `date`,
                       {$this->strExpr('so', 'sales_orders', 'order_no')} as order_no,
                       {$this->idExpr('so', 'sales_orders', 'customer_id')} as customer_id,
                       NULL as supplier_id,
                       {$this->idExpr('so', 'sales_orders', 'warehouse_id')} as warehouse_id,
                       {$this->idExpr('so', 'sales_orders', 'salesman_id')} as salesman_id,
                       {$this->refNameExpr('sales_orders', 'customer_id', 'c')} as partner_name,
                       {$this->refNameExpr('sales_orders', 'warehouse_id', 'w')} as warehouse_name,
                       {$this->userNameExpr('sales_orders', 'salesman_id')} as salesman_name,
                       {$income} as income,
                       0 as expense,
                       {$this->strExpr('so', 'sales_orders', 'remark')} as remark,
                       so.id
                FROM sales_orders so
                ".$this->join('LEFT JOIN customers c ON c.id = so.customer_id', 'sales_orders', 'customer_id')."
                ".$this->join('LEFT JOIN warehouses w ON w.id = so.warehouse_id', 'sales_orders', 'warehouse_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = so.salesman_id', 'sales_orders', 'salesman_id')."
                WHERE ".($this->hasColumn('sales_orders', 'status')
                    ? "so.status IN ('approved', 'completed')"
                    : '1=1')."
                {$this->dateCond($v, 'so', 'sales_orders', 'order_date')}"
                .$this->filter($v['salesman_id'] ?? null, 'so', 'sales_orders', 'salesman_id')
                .$this->filter($v['customer_id'] ?? null, 'so', 'sales_orders', 'customer_id')
                .$this->filter($v['warehouse_id'] ?? null, 'so', 'sales_orders', 'warehouse_id');
        }

        // 2. 销售出库（收入，取 paid_amount）
        if ($want('delivery') && $this->hasTable('deliveries')) {
            $parts[] = "
                SELECT 'delivery' as type_key, '销售出库' as type_label, 'success' as type_color,
                       {$this->dateExpr('d', 'deliveries', 'delivery_date')} as `date`,
                       {$this->strExpr('d', 'deliveries', 'delivery_no')} as order_no,
                       {$this->idExpr('d', 'deliveries', 'customer_id')} as customer_id,
                       NULL as supplier_id,
                       {$this->idExpr('d', 'deliveries', 'warehouse_id')} as warehouse_id,
                       NULL as salesman_id,
                       {$this->refNameExpr('deliveries', 'customer_id', 'c')} as partner_name,
                       {$this->refNameExpr('deliveries', 'warehouse_id', 'w')} as warehouse_name,
                       {$this->deliverySalesmanExpr()} as salesman_name,
                       {$this->numExpr('d', 'deliveries', 'paid_amount')} as income,
                       0 as expense,
                       {$this->strExpr('d', 'deliveries', 'remark')} as remark,
                       d.id
                FROM deliveries d
                ".$this->join('LEFT JOIN customers c ON c.id = d.customer_id', 'deliveries', 'customer_id')."
                ".$this->join('LEFT JOIN warehouses w ON w.id = d.warehouse_id', 'deliveries', 'warehouse_id')."
                ".($this->hasTable('sales_orders') ? $this->join('LEFT JOIN sales_orders so ON so.id = d.order_id', 'deliveries', 'order_id') : '')."
                WHERE ".($this->hasColumn('deliveries', 'status') ? 'd.status >= 1' : '1=1')."
                {$this->dateCond($v, 'd', 'deliveries', 'delivery_date')}"
                .$this->filter($v['customer_id'] ?? null, 'd', 'deliveries', 'customer_id')
                .$this->filter($v['warehouse_id'] ?? null, 'd', 'deliveries', 'warehouse_id');
        }

        // 3. 采购入库（支出）
        if ($want('stock_in') && $this->hasTable('stock_ins')) {
            $parts[] = "
                SELECT 'stock_in' as type_key, '采购入库' as type_label, 'success' as type_color,
                       {$this->dateExpr('si', 'stock_ins', 'stock_date')} as `date`,
                       {$this->strExpr('si', 'stock_ins', 'order_no')} as order_no,
                       NULL as customer_id,
                       {$this->idExpr('si', 'stock_ins', 'supplier_id')} as supplier_id,
                       {$this->idExpr('si', 'stock_ins', 'warehouse_id')} as warehouse_id,
                       NULL as salesman_id,
                       {$this->refNameExpr('stock_ins', 'supplier_id', 's')} as partner_name,
                       {$this->refNameExpr('stock_ins', 'warehouse_id', 'w')} as warehouse_name,
                       {$this->userNameExpr('stock_ins', 'created_by')} as salesman_name,
                       0 as income,
                       {$this->numExpr('si', 'stock_ins', 'total_amount')} as expense,
                       {$this->strExpr('si', 'stock_ins', 'remark')} as remark,
                       si.id
                FROM stock_ins si
                ".$this->join('LEFT JOIN suppliers s ON s.id = si.supplier_id', 'stock_ins', 'supplier_id')."
                ".$this->join('LEFT JOIN warehouses w ON w.id = si.warehouse_id', 'stock_ins', 'warehouse_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = si.created_by', 'stock_ins', 'created_by')."
                WHERE 1=1
                {$this->dateCond($v, 'si', 'stock_ins', 'stock_date')}"
                .$this->filter($v['supplier_id'] ?? null, 'si', 'stock_ins', 'supplier_id')
                .$this->filter($v['warehouse_id'] ?? null, 'si', 'stock_ins', 'warehouse_id');
        }

        // 4. 采购退货（收入）
        if ($want('purchase_return') && $this->hasTable('purchase_returns')) {
            $parts[] = "
                SELECT 'purchase_return' as type_key, '采购退货' as type_label, 'warning' as type_color,
                       {$this->dateExpr('pr', 'purchase_returns', 'return_date')} as `date`,
                       {$this->strExpr('pr', 'purchase_returns', 'return_no')} as order_no,
                       NULL as customer_id,
                       {$this->idExpr('pr', 'purchase_returns', 'supplier_id')} as supplier_id,
                       {$this->idExpr('pr', 'purchase_returns', 'warehouse_id')} as warehouse_id,
                       NULL as salesman_id,
                       {$this->strExpr('pr', 'purchase_returns', 'supplier_name')} as partner_name,
                       {$this->refNameExpr('purchase_returns', 'warehouse_id', 'w')} as warehouse_name,
                       {$this->userNameExpr('purchase_returns', 'created_by')} as salesman_name,
                       {$this->numExpr('pr', 'purchase_returns', 'total_amount')} as income,
                       0 as expense,
                       {$this->strExpr('pr', 'purchase_returns', 'remark')} as remark,
                       pr.id
                FROM purchase_returns pr
                ".$this->join('LEFT JOIN warehouses w ON w.id = pr.warehouse_id', 'purchase_returns', 'warehouse_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = pr.created_by', 'purchase_returns', 'created_by')."
                WHERE ".($this->hasColumn('purchase_returns', 'status') ? "pr.status = 'approved'" : '1=1')."
                {$this->dateCond($v, 'pr', 'purchase_returns', 'return_date')}"
                .$this->filter($v['supplier_id'] ?? null, 'pr', 'purchase_returns', 'supplier_id')
                .$this->filter($v['warehouse_id'] ?? null, 'pr', 'purchase_returns', 'warehouse_id');
        }

        // 5. 销售退货（收入）
        if ($want('sales_return') && $this->hasTable('sales_returns')) {
            $parts[] = "
                SELECT 'sales_return' as type_key, '销售退货' as type_label, 'warning' as type_color,
                       {$this->dateExpr('sr', 'sales_returns', 'return_date')} as `date`,
                       {$this->strExpr('sr', 'sales_returns', 'return_no')} as order_no,
                       {$this->idExpr('sr', 'sales_returns', 'customer_id')} as customer_id,
                       NULL as supplier_id,
                       {$this->idExpr('sr', 'sales_returns', 'warehouse_id')} as warehouse_id,
                       NULL as salesman_id,
                       {$this->strExpr('sr', 'sales_returns', 'customer_name')} as partner_name,
                       {$this->refNameExpr('sales_returns', 'warehouse_id', 'w')} as warehouse_name,
                       {$this->userNameExpr('sales_returns', 'created_by')} as salesman_name,
                       {$this->numExpr('sr', 'sales_returns', 'total_amount')} as income,
                       0 as expense,
                       {$this->strExpr('sr', 'sales_returns', 'remark')} as remark,
                       sr.id
                FROM sales_returns sr
                ".$this->join('LEFT JOIN warehouses w ON w.id = sr.warehouse_id', 'sales_returns', 'warehouse_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = sr.created_by', 'sales_returns', 'created_by')."
                WHERE ".($this->hasColumn('sales_returns', 'status') ? "sr.status = 'approved'" : '1=1')."
                {$this->dateCond($v, 'sr', 'sales_returns', 'return_date')}"
                .$this->filter($v['customer_id'] ?? null, 'sr', 'sales_returns', 'customer_id')
                .$this->filter($v['warehouse_id'] ?? null, 'sr', 'sales_returns', 'warehouse_id');
        }

        // 6. 收款单（收入）
        if ($want('receive') && $this->hasTable('receives')) {
            $parts[] = "
                SELECT 'receive' as type_key, '收款单' as type_label, 'danger' as type_color,
                       {$this->dateExpr('rc', 'receives', 'receive_date')} as `date`,
                       {$this->strExpr('rc', 'receives', 'receive_no')} as order_no,
                       {$this->idExpr('rc', 'receives', 'customer_id')} as customer_id,
                       NULL as supplier_id,
                       NULL as warehouse_id,
                       {$this->idExpr('rc', 'receives', 'handler_id')} as salesman_id,
                       {$this->refNameExpr('receives', 'customer_id', 'c')} as partner_name,
                       '' as warehouse_name,
                       {$this->userNameExpr('receives', 'handler_id')} as salesman_name,
                       {$this->numExpr('rc', 'receives', 'amount')} as income,
                       0 as expense,
                       {$this->strExpr('rc', 'receives', 'remark')} as remark,
                       rc.id
                FROM receives rc
                ".$this->join('LEFT JOIN customers c ON c.id = rc.customer_id', 'receives', 'customer_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = rc.handler_id', 'receives', 'handler_id')."
                WHERE ".($this->hasColumn('receives', 'status') ? 'rc.status = 1' : '1=1')."
                {$this->dateCond($v, 'rc', 'receives', 'receive_date')}"
                .$this->filter($v['customer_id'] ?? null, 'rc', 'receives', 'customer_id')
                .$this->filter($v['salesman_id'] ?? null, 'rc', 'receives', 'handler_id');
        }

        // 7. 付款单（支出）
        if ($want('pay') && $this->hasTable('pays')) {
            $parts[] = "
                SELECT 'pay' as type_key, '付款单' as type_label, 'warning' as type_color,
                       {$this->dateExpr('py', 'pays', 'pay_date')} as `date`,
                       {$this->strExpr('py', 'pays', 'pay_no')} as order_no,
                       NULL as customer_id,
                       {$this->idExpr('py', 'pays', 'supplier_id')} as supplier_id,
                       NULL as warehouse_id,
                       {$this->idExpr('py', 'pays', 'handler_id')} as salesman_id,
                       {$this->refNameExpr('pays', 'supplier_id', 's')} as partner_name,
                       '' as warehouse_name,
                       {$this->userNameExpr('pays', 'handler_id')} as salesman_name,
                       0 as income,
                       {$this->numExpr('py', 'pays', 'amount')} as expense,
                       {$this->strExpr('py', 'pays', 'remark')} as remark,
                       py.id
                FROM pays py
                ".$this->join('LEFT JOIN suppliers s ON s.id = py.supplier_id', 'pays', 'supplier_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = py.handler_id', 'pays', 'handler_id')."
                WHERE ".($this->hasColumn('pays', 'status') ? 'py.status = 1' : '1=1')."
                {$this->dateCond($v, 'py', 'pays', 'pay_date')}"
                .$this->filter($v['supplier_id'] ?? null, 'py', 'pays', 'supplier_id')
                .$this->filter($v['salesman_id'] ?? null, 'py', 'pays', 'handler_id');
        }

        // 8. 费用单（支出）
        if ($want('expense') && $this->hasTable('expenses')) {
            $remark = $this->hasColumn('expenses', 'expense_type')
                ? "CONCAT(COALESCE(ex.expense_type, ''), '：', COALESCE(ex.remark, ''))"
                : $this->strExpr('ex', 'expenses', 'remark');

            $parts[] = "
                SELECT 'expense' as type_key, '现金费用' as type_label, 'info' as type_color,
                       {$this->dateExpr('ex', 'expenses', 'expense_date')} as `date`,
                       {$this->strExpr('ex', 'expenses', 'expense_no')} as order_no,
                       NULL as customer_id, NULL as supplier_id, NULL as warehouse_id,
                       {$this->idExpr('ex', 'expenses', 'handler_id')} as salesman_id,
                       '' as partner_name,
                       '' as warehouse_name,
                       {$this->userNameExpr('expenses', 'handler_id')} as salesman_name,
                       0 as income,
                       {$this->numExpr('ex', 'expenses', 'amount')} as expense,
                       {$remark} as remark,
                       ex.id
                FROM expenses ex
                ".$this->join('LEFT JOIN auth_user u ON u.id = ex.handler_id', 'expenses', 'handler_id')."
                WHERE ".($this->hasColumn('expenses', 'status') ? 'ex.status = 1' : '1=1')."
                {$this->dateCond($v, 'ex', 'expenses', 'expense_date')}"
                .$this->filter($v['salesman_id'] ?? null, 'ex', 'expenses', 'handler_id');
        }

        // 9. 库存盘点（盘盈=收入，盘亏=支出）
        if ($want('stock_check') && $this->hasTable('stock_checks')) {
            $remark = $this->hasColumn('stock_checks', 'check_type')
                ? "CONCAT('盘点：', COALESCE(sc.check_type, ''))"
                : "''";

            $parts[] = "
                SELECT 'stock_check' as type_key, '库存盘点' as type_label, 'danger' as type_color,
                       {$this->dateExpr('sc', 'stock_checks', 'check_date')} as `date`,
                       {$this->strExpr('sc', 'stock_checks', 'check_no')} as order_no,
                       NULL as customer_id, NULL as supplier_id,
                       {$this->idExpr('sc', 'stock_checks', 'warehouse_id')} as warehouse_id,
                       {$this->idExpr('sc', 'stock_checks', 'created_by')} as salesman_id,
                       '' as partner_name,
                       {$this->refNameExpr('stock_checks', 'warehouse_id', 'w')} as warehouse_name,
                       {$this->userNameExpr('stock_checks', 'created_by')} as salesman_name,
                       {$this->numExpr('sc', 'stock_checks', 'profit_amount')} as income,
                       {$this->numExpr('sc', 'stock_checks', 'loss_amount')} as expense,
                       {$remark} as remark,
                       sc.id
                FROM stock_checks sc
                ".$this->join('LEFT JOIN warehouses w ON w.id = sc.warehouse_id', 'stock_checks', 'warehouse_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = sc.created_by', 'stock_checks', 'created_by')."
                WHERE ".($this->hasColumn('stock_checks', 'status') ? "sc.status = 'approved'" : '1=1')."
                {$this->dateCond($v, 'sc', 'stock_checks', 'check_date')}"
                .$this->filter($v['warehouse_id'] ?? null, 'sc', 'stock_checks', 'warehouse_id')
                .$this->filter($v['salesman_id'] ?? null, 'sc', 'stock_checks', 'created_by');
        }

        // 10. 组装单（支出）
        if ($want('assembly') && $this->hasTable('assembly_orders')) {
            $parts[] = "
                SELECT 'assembly' as type_key, '商品组装' as type_label, 'info' as type_color,
                       {$this->dateExpr('ac', 'assembly_orders', 'assembly_date')} as `date`,
                       {$this->strExpr('ac', 'assembly_orders', 'assembly_no')} as order_no,
                       NULL as customer_id, NULL as supplier_id,
                       {$this->idExpr('ac', 'assembly_orders', 'warehouse_id')} as warehouse_id,
                       {$this->idExpr('ac', 'assembly_orders', 'salesman_id')} as salesman_id,
                       '' as partner_name,
                       {$this->refNameExpr('assembly_orders', 'warehouse_id', 'w')} as warehouse_name,
                       {$this->userNameExpr('assembly_orders', 'salesman_id')} as salesman_name,
                       0 as income,
                       {$this->numExpr('ac', 'assembly_orders', 'total_cost')} as expense,
                       {$this->strExpr('ac', 'assembly_orders', 'remark')} as remark,
                       ac.id
                FROM assembly_orders ac
                ".$this->join('LEFT JOIN warehouses w ON w.id = ac.warehouse_id', 'assembly_orders', 'warehouse_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = ac.salesman_id', 'assembly_orders', 'salesman_id')."
                WHERE ".($this->hasColumn('assembly_orders', 'status') ? "ac.status = 'approved'" : '1=1')."
                {$this->dateCond($v, 'ac', 'assembly_orders', 'assembly_date')}"
                .$this->filter($v['warehouse_id'] ?? null, 'ac', 'assembly_orders', 'warehouse_id')
                .$this->filter($v['salesman_id'] ?? null, 'ac', 'assembly_orders', 'salesman_id');
        }

        // 11. 拆分单（收入）
        if ($want('split') && $this->hasTable('split_orders')) {
            $parts[] = "
                SELECT 'split' as type_key, '商品拆分' as type_label, 'success' as type_color,
                       {$this->dateExpr('sp', 'split_orders', 'split_date')} as `date`,
                       {$this->strExpr('sp', 'split_orders', 'split_no')} as order_no,
                       NULL as customer_id, NULL as supplier_id,
                       {$this->idExpr('sp', 'split_orders', 'warehouse_id')} as warehouse_id,
                       {$this->idExpr('sp', 'split_orders', 'salesman_id')} as salesman_id,
                       '' as partner_name,
                       {$this->refNameExpr('split_orders', 'warehouse_id', 'w')} as warehouse_name,
                       {$this->userNameExpr('split_orders', 'salesman_id')} as salesman_name,
                       {$this->numExpr('sp', 'split_orders', 'total_cost')} as income,
                       0 as expense,
                       {$this->strExpr('sp', 'split_orders', 'remark')} as remark,
                       sp.id
                FROM split_orders sp
                ".$this->join('LEFT JOIN warehouses w ON w.id = sp.warehouse_id', 'split_orders', 'warehouse_id')."
                ".$this->join('LEFT JOIN auth_user u ON u.id = sp.salesman_id', 'split_orders', 'salesman_id')."
                WHERE ".($this->hasColumn('split_orders', 'status') ? "sp.status = 'approved'" : '1=1')."
                {$this->dateCond($v, 'sp', 'split_orders', 'split_date')}"
                .$this->filter($v['warehouse_id'] ?? null, 'sp', 'split_orders', 'warehouse_id')
                .$this->filter($v['salesman_id'] ?? null, 'sp', 'split_orders', 'salesman_id');
        }

        if (empty($parts)) {
            return "SELECT NULL as type_key, NULL as type_label, NULL as type_color, NULL as `date`,
                    NULL as order_no, NULL as customer_id, NULL as supplier_id, NULL as warehouse_id,
                    NULL as salesman_id, NULL as partner_name, NULL as warehouse_name,
                    NULL as salesman_name, 0 as income, 0 as expense, NULL as remark, 0 as id
                    WHERE 1=0";
        }

        $sql = implode(' UNION ALL ', $parts);

        // order_no 筛选（在外层 WHERE 应用）
        if (! empty($v['order_no'])) {
            $sql = "SELECT * FROM ({$sql}) AS sub WHERE order_no LIKE '%".addslashes($v['order_no'])."%'";
        }

        return $sql;
    }
}
