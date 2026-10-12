<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 商品/客户/业务员销售分析 + 增长退货率（绩效分析 → 商品与客户分析）。
 */
class AnalysisController extends Controller
{
    /** 商品销售分析 */
    public function products(Request $request): JsonResponse
    {
        $start = $request->input('start_date');
        $end = $request->input('end_date');
        $sortBy = $request->input('sort_by', 'sale_amount');

        $rows = DB::table('sales_orders as so')
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
                DB::raw('COUNT(DISTINCT so.customer_id) as customer_count')
            )
            ->groupBy('p.id', 'p.code', 'p.name', 'b.name', 'mc.name')
            ->get()->map(function ($r) {
                $ret = $this->productReturn($r->id, $r->id ? null : null);
                $sale = (float) $r->sale_amount;
                $cost = (float) $r->cost_amount;
                $retAmt = (float) ($ret['amount'] ?? 0);
                $retQty = (int) ($ret['qty'] ?? 0);
                $profit = $sale - $cost - $retAmt;

                return [
                    'product_id' => $r->id,
                    'product_code' => $r->code,
                    'product_name' => $r->name,
                    'brand_name' => $r->brand_name,
                    'category_name' => $r->category_name,
                    'qty' => (int) $r->qty,
                    'sale_amount' => ReportFilter::num($sale),
                    'cost_amount' => ReportFilter::num($cost),
                    'gross_profit' => ReportFilter::num($profit),
                    'profit_rate' => $sale > 0 ? round($profit / $sale * 100, 2) : 0,
                    'return_qty' => $retQty,
                    'return_rate' => $r->qty > 0 ? round($retQty / $r->qty * 100, 2) : 0,
                    'customer_count' => (int) $r->customer_count,
                ];
            });

        $sorted = $rows->sortByDesc(match ($sortBy) {
            'qty' => 'qty',
            'gross_profit' => 'gross_profit',
            default => 'sale_amount',
        })->values();

        return $this->success(['list' => $sorted, 'total' => $sorted->count()]);
    }

    /** 客户销售汇总 */
    public function customers(Request $request): JsonResponse
    {
        $start = $request->input('start_date');
        $end = $request->input('end_date');

        $rows = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('customer_levels as cl', 'cl.id', '=', 'c.level_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
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
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->groupBy('c.id', 'c.name', 'cl.name', 'sm.username', 'sm.real_name', 'c.balance')
            ->get()->map(function ($r) {
                $payment = $this->customerPayment($r->id);
                $sale = (float) $r->sale_amount;
                $cost = (float) $r->cost_amount;
                $profit = $sale - $cost;
                $last = $r->last_order_date ? strtotime((string) $r->last_order_date) : 0;
                $daysSince = $last > 0 ? floor((time() - $last) / 86400) : 9999;

                return [
                    'customer_id' => $r->id,
                    'customer_name' => $r->customer_name ?: '-',
                    'level_name' => $r->level_name ?: '-',
                    'salesman_name' => $r->salesman_name ?: '-',
                    'order_count' => (int) $r->order_count,
                    'sale_amount' => ReportFilter::num($sale),
                    'cost_amount' => ReportFilter::num($cost),
                    'gross_profit' => ReportFilter::num($profit),
                    'payment_amount' => ReportFilter::num($payment),
                    'receivable' => ReportFilter::num((float) $r->balance),
                    'last_order_date' => (string) ($r->last_order_date ?: '-'),
                    'days_since_last' => (int) $daysSince,
                    'risk_level' => $daysSince > 90 ? 'lost' : ($daysSince > 60 ? 'high' : ($daysSince > 30 ? 'medium' : 'normal')),
                    'avg_price' => $r->order_count > 0 ? ReportFilter::num($sale / $r->order_count) : 0,
                ];
            });

        return $this->success(['list' => $rows, 'total' => $rows->count()]);
    }

    /** 业务员销售分析 */
    public function employees(Request $request): JsonResponse
    {
        $start = $request->input('start_date');
        $end = $request->input('end_date');

        $rows = DB::table('sales_orders as so')
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
            ->groupBy('so.salesman_id', 'sm.username', 'sm.real_name', 'e.department')
            ->get()->map(function ($r) use ($start, $end) {
                $payment = $this->empPayment($r->salesman_id, $start, $end);
                $ret = $this->empReturn($r->salesman_id, $start, $end);
                $commission = $this->empCommission($r->salesman_id, $start, $end);
                $sale = (float) $r->sale_amount;
                $cost = (float) $r->cost_amount;
                $profit = $sale - $cost - $ret;
                $target = $this->empTarget($r->salesman_id, $start, $end);

                return [
                    'salesman_id' => $r->salesman_id,
                    'salesman_name' => $r->salesman_name,
                    'department' => $r->department ?: '-',
                    'visit_count' => (int) $r->visit_count,
                    'deal_count' => (int) $r->visit_count, // 成交客户数（简化=有单的客户数）
                    'deal_rate' => 100, // 有单即成交，简化
                    'order_count' => (int) $r->order_count,
                    'sale_amount' => ReportFilter::num($sale),
                    'payment_amount' => ReportFilter::num($payment),
                    'gross_profit' => ReportFilter::num($profit),
                    'commission' => ReportFilter::num($commission),
                    'return_rate' => $sale > 0 ? round($ret / $sale * 100, 2) : 0,
                    'target' => ReportFilter::num($target),
                    'achieve_rate' => $target > 0 ? round($sale / $target * 100, 2) : 0,
                ];
            });

        return $this->success(['list' => $rows, 'total' => $rows->count()]);
    }

    /** 日月增长与退货率 */
    public function growthReturn(Request $request): JsonResponse
    {
        $sm = ReportFilter::toArray($request->input('salesman_ids'));
        $dashboard = new DashboardController;

        return app()->call([$dashboard, 'salesOverview'], ['request' => $request]);
    }

    private function productReturn(int $pid): array
    {
        $row = DB::table('sales_return_items as sri')
            ->join('sales_returns as sr', 'sr.id', '=', 'sri.return_id')
            ->where('sr.status', 'approved')
            ->where('sri.product_id', $pid)
            ->selectRaw('SUM(sri.return_qty) as qty, SUM(sri.return_amount) as amount')
            ->first();

        return ['qty' => (int) ($row->qty ?? 0), 'amount' => (float) ($row->amount ?? 0)];
    }

    private function customerPayment(int $cid): float
    {
        return (float) DB::table('receives')->where('customer_id', $cid)->where('status', 1)->sum('amount');
    }

    private function empPayment($empId, $start, $end): float
    {
        $q = DB::table('receives as r')->join('sales_orders as so', 'so.id', '=', 'r.sales_order_id')
            ->where('r.status', 1)->where('so.salesman_id', $empId);
        if ($start && $end) {
            $q->whereBetween('r.receive_date', [$start, $end]);
        }

        return (float) $q->sum('r.amount');
    }

    private function empReturn($empId, $start, $end): float
    {
        $q = DB::table('sales_returns as sr')
            ->join('sales_orders as so', 'so.id', '=', 'sr.order_id')
            ->where('sr.status', 'approved')->where('so.salesman_id', $empId);
        if ($start && $end) {
            $q->whereBetween('sr.return_date', [$start, $end]);
        }

        return (float) $q->sum('sr.total_amount');
    }

    private function empCommission($empId, $start, $end): float
    {
        if (! $start) {
            $start = now()->startOfMonth()->toDateString();
        }
        $period = substr($start, 0, 7);

        return (float) DB::table('perf_commission_orders')
            ->where('employee_id', $empId)
            ->where('period', $period)
            ->whereIn('status', ['audited', 'paid'])
            ->sum('commission_amount');
    }

    private function empTarget($empId, $start, $end): float
    {
        if (! $start) {
            $start = now()->startOfMonth()->toDateString();
        }
        $period = substr($start, 0, 7);

        return (float) DB::table('perf_sales_targets')
            ->where('target_type', 'employee')
            ->where('target_id', $empId)
            ->where('period', $period)
            ->value('target_amount');
    }
}
