<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 商品/客户/业务员销售分析 + 增长退货率 + CSV 导出（绩效分析 → 商品与客户分析）。
 * 列表服务端分页，data 含 list/total/page/page_size/last_page + totals 合计行（真实聚合值）。
 */
class AnalysisController extends Controller
{
    private const PER_PAGE_DEFAULT = 15;

    /** 商品销售分析（订单日期筛选，退货按退货日期筛选） */
    public function products(Request $request): JsonResponse
    {
        [$start, $end] = $this->range($request);
        $sortBy = $request->input('sort_by', 'sale_amount');
        // gross_profit 是映射后字段（需扣退货），SQL 只排 sale_amount/qty，映射后再排 gross_profit
        $orderCol = $sortBy === 'qty' ? 'qty' : 'sale_amount';

        $page = $this->queryPage($this->productsQuery($start, $end)->orderByDesc($orderCol), $request);
        $col = $page->getCollection()->map(fn ($r) => $this->mapProduct($r));
        if ($sortBy === 'gross_profit') {
            $col = $col->sortByDesc('gross_profit');
        }
        $page->setCollection($col->values());

        return $this->listWithTotals($page, $this->productsTotals($start, $end));
    }

    /** 客户销售汇总 */
    public function customers(Request $request): JsonResponse
    {
        [$start, $end] = $this->range($request);

        $page = $this->queryPage($this->customersQuery($start, $end)->orderByDesc('sale_amount')->orderByDesc('c.id'), $request);
        $page->setCollection($page->getCollection()->map(fn ($r) => $this->mapCustomer($r, $start, $end))->values());

        return $this->listWithTotals($page, $this->customersTotals($start, $end));
    }

    /** 业务员销售分析（含成交率、目标达成率） */
    public function employees(Request $request): JsonResponse
    {
        [$start, $end] = $this->range($request);
        $activeBase = $this->activeCustomerBase();

        $page = $this->queryPage($this->employeesQuery($start, $end)->orderByDesc('sale_amount'), $request);
        $page->setCollection($page->getCollection()
            ->map(fn ($r) => $this->mapEmployee($r, $start, $end, $activeBase))
            ->values());

        return $this->listWithTotals($page, $this->employeesTotals($start, $end));
    }

    /** 日月增长与退货率（独立计算，不转发看板 overview；支持 start/end 日期筛选） */
    public function growthReturn(Request $request): JsonResponse
    {
        [$start, $end] = $this->range($request);
        $series = $this->growthReturnSeries($start, $end, ReportFilter::toArray($request->input('salesman_ids')));

        return $this->success(['list' => $series, 'total' => count($series), 'growth_return' => $series]);
    }

    /** 各分析表 CSV 导出：复用与列表相同的查询和筛选，返回全量行 */
    public function export(Request $request, string $name)
    {
        [$start, $end] = $this->range($request);

        [$title, $headers, $rows] = match ($name) {
            'products' => $this->exportProducts($start, $end, $request),
            'customers' => $this->exportCustomers($start, $end, $request),
            'employees' => $this->exportEmployees($start, $end, $request),
            'growth-return' => $this->exportGrowthReturn($start, $end, $request),
            default => ['分析', ['说明'], [['无数据']]],
        };

        $csv = ReportFilter::csv($title, $headers, $rows);

        return ReportFilter::csvResponse($csv, 'analysis_'.$name.'_'.now()->format('Ymd_His').'.csv');
    }

    // ================= 查询构建（列表与导出共用，保证口径一致） =================

    private function productsQuery(?string $start, ?string $end): Builder
    {
        return DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->when($start && $end, fn ($q) => $q->whereBetween('so.order_date', [$start, $end]))
            ->select(
                'p.id', 'p.code', 'p.name', 'b.name as brand_name', 'mc.name as category_name',
                DB::raw('SUM(soi.quantity) as qty'),
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale_amount'),
                DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
                DB::raw('COUNT(DISTINCT so.id) as order_count')
            )
            ->groupBy('p.id', 'p.code', 'p.name', 'b.name', 'mc.name')
            // 退货按退货日期筛选（关联子查询，select bindings 先于 where bindings 绑定）
            ->selectRaw($this->returnSub('sri.return_qty', $start, $end).' as return_qty', $this->subBindings($start, $end))
            ->selectRaw($this->returnSub('sri.return_amount', $start, $end).' as return_amount', $this->subBindings($start, $end));
    }

    private function returnSub(string $col, ?string $start, ?string $end): string
    {
        $sql = "COALESCE((SELECT SUM({$col}) FROM sales_return_items sri
                INNER JOIN sales_returns sr ON sr.id = sri.return_id
                WHERE sr.status = 'approved' AND sri.product_id = p.id";
        if ($start && $end) {
            $sql .= ' AND sr.return_date BETWEEN ? AND ?';
        }

        return $sql."), 0)";
    }

    private function subBindings(?string $start, ?string $end): array
    {
        return ($start && $end) ? [$start, $end] : [];
    }

    private function mapProduct(object $r): array
    {
        $sale = (float) $r->sale_amount;
        $cost = (float) $r->cost_amount;
        $retAmt = (float) ($r->return_amount ?? 0);
        $retQty = (int) ($r->return_qty ?? 0);
        $qty = (int) $r->qty;
        $profit = $sale - $cost - $retAmt;

        return [
            'product_id' => $r->id,
            'product_code' => $r->code,
            'product_name' => $r->name,
            'brand_name' => $r->brand_name,
            'category_name' => $r->category_name,
            'qty' => $qty,
            'sale_amount' => ReportFilter::num($sale),
            'cost_amount' => ReportFilter::num($cost),
            'gross_profit' => ReportFilter::num($profit),
            'profit_rate' => $sale > 0 ? round($profit / $sale * 100, 2) : 0,
            'return_qty' => $retQty,
            'return_amount' => ReportFilter::num($retAmt),
            'return_rate' => $sale > 0 ? round($retAmt / $sale * 100, 2) : ($qty > 0 ? round($retQty / $qty * 100, 2) : 0),
            'customer_count' => (int) $r->customer_count,
            'order_count' => (int) $r->order_count,
        ];
    }

    private function productsTotals(?string $start, ?string $end): array
    {
        $base = $this->productsAggBase($start, $end)
            ->selectRaw('COALESCE(SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END),0) as sale_amount,
                COALESCE(SUM(soi.quantity * COALESCE(p.cost_price,0)),0) as cost_amount,
                COALESCE(SUM(soi.quantity),0) as qty,
                COUNT(DISTINCT so.id) as order_count,
                COUNT(DISTINCT so.customer_id) as customer_count')
            ->first();
        $ret = $this->returnTotals($start, $end);
        $sale = (float) ($base->sale_amount ?? 0);
        $cost = (float) ($base->cost_amount ?? 0);
        $profit = $sale - $cost - $ret['amount'];

        return [
            'qty' => (int) ($base->qty ?? 0),
            'order_count' => (int) ($base->order_count ?? 0),
            'customer_count' => (int) ($base->customer_count ?? 0),
            'sale_amount' => ReportFilter::num($sale),
            'cost_amount' => ReportFilter::num($cost),
            'gross_profit' => ReportFilter::num($profit),
            'profit_rate' => $sale > 0 ? round($profit / $sale * 100, 2) : 0,
            'return_qty' => $ret['qty'],
            'return_amount' => ReportFilter::num($ret['amount']),
            'return_rate' => $sale > 0 ? round($ret['amount'] / $sale * 100, 2) : 0,
        ];
    }

    private function productsAggBase(?string $start, ?string $end): Builder
    {
        return DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->when($start && $end, fn ($q) => $q->whereBetween('so.order_date', [$start, $end]));
    }

    private function returnTotals(?string $start, ?string $end): array
    {
        $row = DB::table('sales_return_items as sri')
            ->join('sales_returns as sr', 'sr.id', '=', 'sri.return_id')
            ->where('sr.status', 'approved')
            ->when($start && $end, fn ($q) => $q->whereBetween('sr.return_date', [$start, $end]))
            ->selectRaw('COALESCE(SUM(sri.return_qty),0) as qty, COALESCE(SUM(sri.return_amount),0) as amount')
            ->first();

        return ['qty' => (int) ($row->qty ?? 0), 'amount' => (float) ($row->amount ?? 0)];
    }

    private function customersQuery(?string $start, ?string $end): Builder
    {
        return DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('customer_levels as cl', 'cl.id', '=', 'c.level_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->when($start && $end, fn ($q) => $q->whereBetween('so.order_date', [$start, $end]))
            ->select(
                'c.id', 'c.name as customer_name', 'cl.name as level_name',
                DB::raw('COALESCE(sm.real_name, sm.username) as salesman_name'),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale_amount'),
                DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
                'c.balance',
                DB::raw('MAX(so.order_date) as last_order_date')
            )
            ->groupBy('c.id', 'c.name', 'cl.name', 'sm.username', 'sm.real_name', 'c.balance');
    }

    private function mapCustomer(object $r, ?string $start, ?string $end): array
    {
        $payment = $this->customerPayment((int) $r->id, $start, $end);
        $sale = (float) $r->sale_amount;
        $cost = (float) $r->cost_amount;
        $profit = $sale - $cost;
        $last = $r->last_order_date ? strtotime((string) $r->last_order_date) : 0;
        $daysSince = $last > 0 ? floor((time() - $last) / 86400) : 9999;
        $orderCount = (int) $r->order_count;

        return [
            'customer_id' => $r->id,
            'customer_name' => $r->customer_name ?: '-',
            'level_name' => $r->level_name ?: '-',
            'salesman_name' => $r->salesman_name ?: '-',
            'order_count' => $orderCount,
            'sale_amount' => ReportFilter::num($sale),
            'cost_amount' => ReportFilter::num($cost),
            'gross_profit' => ReportFilter::num($profit),
            'profit_rate' => $sale > 0 ? round($profit / $sale * 100, 2) : 0,
            'payment_amount' => ReportFilter::num($payment),
            'receivable' => ReportFilter::num((float) $r->balance),
            'last_order_date' => (string) ($r->last_order_date ?: '-'),
            'days_since_last' => (int) $daysSince,
            'risk_level' => $daysSince > 90 ? 'lost' : ($daysSince > 60 ? 'high' : ($daysSince > 30 ? 'medium' : 'normal')),
            'avg_price' => $orderCount > 0 ? ReportFilter::num($sale / $orderCount) : 0,
        ];
    }

    private function customersTotals(?string $start, ?string $end): array
    {
        $row = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->when($start && $end, fn ($q) => $q->whereBetween('so.order_date', [$start, $end]))
            ->selectRaw('COALESCE(SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END),0) as sale_amount,
                COALESCE(SUM(soi.quantity * COALESCE(p.cost_price,0)),0) as cost_amount,
                COUNT(DISTINCT so.id) as order_count,
                COUNT(DISTINCT so.customer_id) as customer_count')
            ->first();
        $payment = $this->totalPayment($start, $end);
        $sale = (float) ($row->sale_amount ?? 0);
        $cost = (float) ($row->cost_amount ?? 0);

        return [
            'customer_count' => (int) ($row->customer_count ?? 0),
            'order_count' => (int) ($row->order_count ?? 0),
            'sale_amount' => ReportFilter::num($sale),
            'cost_amount' => ReportFilter::num($cost),
            'gross_profit' => ReportFilter::num($sale - $cost),
            'payment_amount' => ReportFilter::num($payment),
            'avg_price' => ($row->order_count ?? 0) > 0 ? ReportFilter::num($sale / (int) $row->order_count) : 0,
        ];
    }

    private function employeesQuery(?string $start, ?string $end): Builder
    {
        return DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->leftJoin('employees as e', 'e.user_id', '=', 'so.salesman_id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->when($start && $end, fn ($q) => $q->whereBetween('so.order_date', [$start, $end]))
            ->select(
                'so.salesman_id',
                DB::raw('COALESCE(sm.real_name, sm.username, "未指派") as salesman_name'),
                'e.department',
                DB::raw('COUNT(DISTINCT so.customer_id) as visit_count'),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale_amount'),
                DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount')
            )
            ->groupBy('so.salesman_id', 'sm.username', 'sm.real_name', 'e.department');
    }

    private function mapEmployee(object $r, ?string $start, ?string $end, int $activeBase): array
    {
        $empId = (int) $r->salesman_id;
        $payment = $this->empPayment($empId, $start, $end);
        $ret = $this->empReturn($empId, $start, $end);
        $commission = $this->empCommission($empId, $start, $end);
        $sale = (float) $r->sale_amount;
        $cost = (float) $r->cost_amount;
        $profit = $sale - $cost - $ret;
        $target = $this->empTarget($empId, $start, $end);
        // 成交率 = 下单客户数 / 活跃客户数（无可触达口径时的兜底），分母 0 返回 null
        $dealCount = (int) $r->visit_count;
        $dealRate = $activeBase > 0 ? round($dealCount / $activeBase * 100, 2) : null;

        return [
            'salesman_id' => $empId,
            'salesman_name' => $r->salesman_name,
            'department' => $r->department ?: '-',
            'visit_count' => $dealCount,
            'deal_count' => $dealCount,
            'deal_rate' => $dealRate,
            'order_count' => (int) $r->order_count,
            'sale_amount' => ReportFilter::num($sale),
            'payment_amount' => ReportFilter::num($payment),
            'gross_profit' => ReportFilter::num($profit),
            'commission' => ReportFilter::num($commission),
            'return_rate' => $sale > 0 ? round($ret / $sale * 100, 2) : 0,
            'target' => ReportFilter::num($target),
            'achieve_rate' => $target > 0 ? round($sale / $target * 100, 2) : 0,
        ];
    }

    private function employeesTotals(?string $start, ?string $end): array
    {
        $row = $this->employeesAgg($start, $end);
        $ret = $this->allReturnAmount($start, $end);
        $sale = (float) ($row->sale_amount ?? 0);
        $cost = (float) ($row->cost_amount ?? 0);
        $target = (float) DB::table('perf_sales_targets')
            ->where('target_type', 'employee')
            ->where('period', $this->period($start))
            ->sum('target_amount');

        return [
            'order_count' => (int) ($row->order_count ?? 0),
            'sale_amount' => ReportFilter::num($sale),
            'payment_amount' => ReportFilter::num($this->totalPayment($start, $end)),
            'gross_profit' => ReportFilter::num($sale - $cost - $ret),
            'commission' => ReportFilter::num((float) DB::table('perf_commission_orders')
                ->where('period', $this->period($start))
                ->whereIn('status', ['audited', 'paid'])
                ->sum('commission_amount')),
            'return_rate' => $sale > 0 ? round($ret / $sale * 100, 2) : 0,
            'target' => ReportFilter::num($target),
            'achieve_rate' => $target > 0 ? round($sale / $target * 100, 2) : 0,
        ];
    }

    private function employeesAgg(?string $start, ?string $end): object
    {
        return DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->when($start && $end, fn ($q) => $q->whereBetween('so.order_date', [$start, $end]))
            ->selectRaw('COALESCE(SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END),0) as sale_amount,
                COALESCE(SUM(soi.quantity * COALESCE(p.cost_price,0)),0) as cost_amount,
                COUNT(DISTINCT so.id) as order_count')
            ->first();
    }

    // ================= 增长退货率（独立计算） =================

    private function growthReturnSeries(?string $start, ?string $end, array $sm): array
    {
        $byDay = false;
        if ($start && $end) {
            $byDay = (strtotime($end) - strtotime($start)) / 86400 <= 62;
        } else {
            $start = now()->subMonths(11)->startOfMonth()->toDateString();
            $end = now()->endOfMonth()->toDateString();
        }
        $fmt = $byDay ? '%Y-%m-%d' : '%Y-%m';

        $sales = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->where('so.status', 'approved')->whereNull('so.original_order_id')
            ->whereBetween('so.order_date', [$start, $end])
            ->when($sm, fn ($q) => $q->whereIn('so.salesman_id', $sm))
            ->select(DB::raw("DATE_FORMAT(so.order_date, '{$fmt}') as ym"), DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as sale'))
            ->groupBy('ym')->get()->keyBy('ym');
        $returns = DB::table('sales_returns as sr')
            ->where('sr.status', 'approved')
            ->whereBetween('sr.return_date', [$start, $end])
            ->select(DB::raw("DATE_FORMAT(sr.return_date, '{$fmt}') as ym"), DB::raw('SUM(sr.total_amount) as ret'))
            ->groupBy('ym')->get()->keyBy('ym');

        $out = [];
        if ($byDay) {
            for ($t = strtotime($start); $t <= strtotime($end); $t = strtotime('+1 day', $t)) {
                $out[] = $this->growthPoint(date('Y-m-d', $t), $sales, $returns);
            }
        } else {
            $cursor = date('Y-m', strtotime($start));
            $last = date('Y-m', strtotime($end));
            while ($cursor <= $last) {
                $out[] = $this->growthPoint($cursor, $sales, $returns);
                $cursor = date('Y-m', strtotime($cursor.'-01 +1 month'));
            }
        }

        return $out;
    }

    private function growthPoint(string $key, $sales, $returns): array
    {
        $sale = (float) ($sales[$key]->sale ?? 0);
        $ret = (float) ($returns[$key]->ret ?? 0);

        return [
            'month' => $key,
            'sale' => ReportFilter::num($sale),
            'return' => ReportFilter::num($ret),
            'return_rate' => $sale > 0 ? round($ret / $sale * 100, 2) : 0,
        ];
    }

    // ================= 行级辅助查询 =================

    private function customerPayment(int $cid, ?string $start, ?string $end): float
    {
        $q = DB::table('receives')->where('customer_id', $cid)->where('status', 1);
        if ($start && $end) {
            $q->whereBetween('receive_date', [$start, $end]);
        }

        return (float) $q->sum('amount');
    }

    private function totalPayment(?string $start, ?string $end): float
    {
        $q = DB::table('receives')->where('status', 1);
        if ($start && $end) {
            $q->whereBetween('receive_date', [$start, $end]);
        }

        return (float) $q->sum('amount');
    }

    private function empPayment($empId, ?string $start, ?string $end): float
    {
        $q = DB::table('receives as r')->join('sales_orders as so', 'so.id', '=', 'r.sales_order_id')
            ->where('r.status', 1)->where('so.salesman_id', $empId);
        if ($start && $end) {
            $q->whereBetween('r.receive_date', [$start, $end]);
        }

        return (float) $q->sum('r.amount');
    }

    private function empReturn($empId, ?string $start, ?string $end): float
    {
        $q = DB::table('sales_returns as sr')
            ->join('sales_orders as so', 'so.id', '=', 'sr.order_id')
            ->where('sr.status', 'approved')->where('so.salesman_id', $empId);
        if ($start && $end) {
            $q->whereBetween('sr.return_date', [$start, $end]);
        }

        return (float) $q->sum('sr.total_amount');
    }

    private function allReturnAmount(?string $start, ?string $end): float
    {
        $q = DB::table('sales_returns')->where('status', 'approved');
        if ($start && $end) {
            $q->whereBetween('return_date', [$start, $end]);
        }

        return (float) $q->sum('total_amount');
    }

    private function empCommission($empId, ?string $start, ?string $end): float
    {
        return (float) DB::table('perf_commission_orders')
            ->where('employee_id', $empId)
            ->where('period', $this->period($start))
            ->whereIn('status', ['audited', 'paid'])
            ->sum('commission_amount');
    }

    /** 目标：perf_sales_targets(employee_id, period YYYY-MM, target_amount)；period 取 start 截 YYYY-MM 或当前月 */
    private function empTarget($empId, ?string $start, ?string $end): float
    {
        return (float) DB::table('perf_sales_targets')
            ->where('employee_id', $empId)
            ->where('period', $this->period($start))
            ->value('target_amount');
    }

    private function period(?string $start): string
    {
        return $start ? substr($start, 0, 7) : now()->format('Y-m');
    }

    private function activeCustomerBase(): int
    {
        return (int) DB::table('customers')->where('is_active', 1)->count();
    }

    // ================= 分页 / 合计 / 导出 =================

    private function range(Request $request): array
    {
        $start = $request->input('start_date') ?: $request->input('start');
        $end = $request->input('end_date') ?: $request->input('end');

        return [$start ? (string) $start : null, $end ? (string) $end : null];
    }

    private function queryPage(Builder $query, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));
        $size = (int) ($request->input('page_size') ?: $request->input('perPage') ?: self::PER_PAGE_DEFAULT);
        $size = $size > 0 ? min($size, 500) : self::PER_PAGE_DEFAULT;

        return $query->paginate($size, ['*'], 'page', $page);
    }

    private function listWithTotals(LengthAwarePaginator $page, array $totals): JsonResponse
    {
        $response = $this->paginated($page);
        $payload = $response->getData(true);
        $payload['data']['totals'] = $totals;

        return response()->json($payload);
    }

    /** 导出行：与列表同查询同筛选，全量返回 */
    private function exportProducts(?string $start, ?string $end, Request $request): array
    {
        $sortBy = $request->input('sort_by', 'sale_amount');
        $orderCol = $sortBy === 'qty' ? 'qty' : 'sale_amount';
        $rows = $this->productsQuery($start, $end)->orderByDesc($orderCol)->get();
        if ($sortBy === 'gross_profit') {
            $rows = $rows->sortByDesc(fn ($r) => $this->mapProduct($r)['gross_profit']);
        }
        $rows = $rows->map(fn ($r) => $this->productCsvRow($r))->values()->all();

        return ['商品销售分析', ['商品编码', '商品名称', '品牌', '分类', '销量', '销售额', '成本', '毛利', '毛利率(%)', '退货数量', '退货金额', '退货率(%)', '下单客户数'], $rows];
    }

    private function productCsvRow(object $r): array
    {
        $m = $this->mapProduct($r);

        return [$m['product_code'], $m['product_name'], $m['brand_name'], $m['category_name'], $m['qty'], $m['sale_amount'], $m['cost_amount'], $m['gross_profit'], $m['profit_rate'], $m['return_qty'], $m['return_amount'], $m['return_rate'], $m['customer_count']];
    }

    private function exportCustomers(?string $start, ?string $end, Request $request): array
    {
        $rows = $this->customersQuery($start, $end)->orderByDesc('sale_amount')->orderByDesc('c.id')->get()
            ->map(function ($r) use ($start, $end) {
                $m = $this->mapCustomer($r, $start, $end);

                $risk = ['normal' => '正常', 'medium' => '关注', 'high' => '高风险', 'lost' => '流失'][$m['risk_level']] ?? $m['risk_level'];

                return [$m['customer_name'], $m['level_name'], $m['salesman_name'], $m['order_count'], $m['sale_amount'], $m['gross_profit'], $m['profit_rate'], $m['payment_amount'], $m['receivable'], $m['last_order_date'], $risk, $m['avg_price']];
            })->values()->all();

        return ['客户销售分析', ['客户名称', '等级', '业务员', '订单数', '销售额', '毛利', '毛利率(%)', '回款额', '应收余额', '最后下单日期', '流失风险', '客单价'], $rows];
    }

    private function exportEmployees(?string $start, ?string $end, Request $request): array
    {
        $activeBase = $this->activeCustomerBase();
        $rows = $this->employeesQuery($start, $end)->orderByDesc('sale_amount')->get()
            ->map(function ($r) use ($start, $end, $activeBase) {
                $m = $this->mapEmployee($r, $start, $end, $activeBase);

                return [$m['salesman_name'], $m['department'], $m['visit_count'], $m['deal_count'], $m['deal_rate'] ?? '', $m['order_count'], $m['sale_amount'], $m['payment_amount'], $m['gross_profit'], $m['commission'], $m['return_rate'], $m['target'], $m['achieve_rate']];
            })->values()->all();

        return ['业务员销售分析', ['业务员', '部门', '拜访客户数', '成交客户数', '成交率(%)', '订单数', '销售额', '回款额', '毛利', '提成', '退货率(%)', '目标', '达成率(%)'], $rows];
    }

    private function exportGrowthReturn(?string $start, ?string $end, Request $request): array
    {
        $series = $this->growthReturnSeries($start, $end, ReportFilter::toArray($request->input('salesman_ids')));
        $rows = array_map(fn ($r) => [$r['month'], $r['sale'], $r['return'], $r['return_rate']], $series);

        return ['增长与退货率', ['月份', '销售额', '退货额', '退货率(%)'], $rows];
    }
}
