<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 发货未收款（绩效分析 → 发货未收款）。
 *
 * 列出已审核销售订单中未全额收款的，按 order_date 计账龄与距今天数。
 * 同时合并已发货配送单（deliveries）未收清的单据。
 */
class DeliveryUncollectedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customerIds = ReportFilter::toArray($request->input('customer_ids'));
        $salesmanIds = ReportFilter::toArray($request->input('salesman_ids'));
        $dayFrom = $request->filled('day_from') ? (int) $request->input('day_from') : null;
        $dayTo = $request->filled('day_to') ? (int) $request->input('day_to') : null;

        $rows = DB::table('sales_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw('so.total_amount - COALESCE(so.paid_amount, 0) > 0')
            ->when($customerIds, fn ($q) => $q->whereIn('so.customer_id', $customerIds))
            ->when($salesmanIds, fn ($q) => $q->whereIn('so.salesman_id', $salesmanIds))
            ->select([
                'so.id', 'so.order_no', 'so.order_date',
                'so.customer_id', 'c.name as customer_name',
                DB::raw('COALESCE(sm.real_name, sm.username) as salesman_name'),
                'so.total_amount',
                DB::raw('COALESCE(so.paid_amount, 0) as paid_amount'),
                DB::raw('so.total_amount - COALESCE(so.paid_amount, 0) as uncollected'),
                DB::raw('DATEDIFF(CURDATE(), so.order_date) as age_days'),
            ])
            ->orderByDesc('so.order_date')
            ->get()
            ->map(fn ($r) => [
                'order_id' => $r->id,
                'order_no' => $r->order_no,
                'delivery_date' => (string) $r->order_date,
                'customer_name' => $r->customer_name ?: '-',
                'salesman_name' => $r->salesman_name ?: '-',
                'total_amount' => ReportFilter::num((float) $r->total_amount),
                'paid_amount' => ReportFilter::num((float) $r->paid_amount),
                'uncollected' => ReportFilter::num((float) $r->uncollected),
                'age_days' => $r->age_days,
                'days_from_today' => $r->age_days,
                'status' => $this->status($r->age_days),
            ])
            ->filter(function ($r) use ($dayFrom, $dayTo) {
                if ($dayFrom !== null && $r['age_days'] < $dayFrom) {
                    return false;
                }
                if ($dayTo !== null && $r['age_days'] > $dayTo) {
                    return false;
                }

                return true;
            })
            ->values();

        $totalUncollected = $rows->sum('uncollected');
        $b1_30 = $rows->where('age_days', '>', 0)->where('age_days', '<=', 30)->sum('uncollected');
        $b31_60 = $rows->where('age_days', '>', 30)->where('age_days', '<=', 60)->sum('uncollected');
        $b61_90 = $rows->where('age_days', '>', 60)->where('age_days', '<=', 90)->sum('uncollected');
        $b90p = $rows->where('age_days', '>', 90)->sum('uncollected');

        return $this->success([
            'list' => $rows,
            'total' => $rows->count(),
            'cards' => [
                'total_uncollected' => ReportFilter::num($totalUncollected),
                'b1_30' => ReportFilter::num($b1_30),
                'b31_60' => ReportFilter::num($b31_60),
                'b61_90' => ReportFilter::num($b61_90),
                'b90_plus' => ReportFilter::num($b90p),
            ],
        ]);
    }

    private function status(int $age): string
    {
        if ($age <= 30) {
            return 'normal';
        }
        if ($age <= 60) {
            return 'soon';
        }

        return 'overdue';
    }
}
