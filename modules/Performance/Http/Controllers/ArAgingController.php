<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 应收账龄分析（绩效分析 → 应收账龄分析）。
 *
 * 口径：以已审核销售订单（status=approved，排除红冲）为应收单据，
 * 应收 = total_amount - paid_amount（订单自带 paid_amount，由收款单回写）。
 * 账龄基准：订单 order_date；回款按订单 paid_amount（先进先出由现有核销顺序保证）。
 * 五档账龄：未到期 / 1~30 / 31~60 / 61~90 / 91~180 / 180+。
 */
class ArAgingController extends Controller
{
    public function aging(Request $request): JsonResponse
    {
        $asOf = $request->input('as_of_date', date('Y-m-d'));
        $customerIds = ReportFilter::toArray($request->input('customer_ids'));
        $salesmanIds = ReportFilter::toArray($request->input('salesman_ids'));

        // 按客户聚合未收清订单
        $orders = DB::table('sales_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw('so.total_amount - COALESCE(so.paid_amount, 0) > 0')
            ->whereDate('so.order_date', '<=', $asOf)
            ->when($customerIds, fn ($q) => $q->whereIn('so.customer_id', $customerIds))
            ->when($salesmanIds, fn ($q) => $q->whereIn('so.salesman_id', $salesmanIds))
            ->select([
                'so.id', 'so.order_no', 'so.customer_id', 'c.name as customer_name',
                'c.code as customer_code', 'c.credit_limit', 'c.balance',
                'so.salesman_id', DB::raw('COALESCE(sm.real_name, sm.username) as salesman_name'),
                'so.order_date', 'so.total_amount', DB::raw('COALESCE(so.paid_amount, 0) as paid_amount'),
                DB::raw('so.total_amount - COALESCE(so.paid_amount, 0) as outstanding'),
                DB::raw('DATEDIFF(?, so.order_date) as age_days'),
            ])->addBinding($asOf, 'select')
            ->orderBy('c.name')
            ->get();

        // 按客户分组，分档累计
        $byCustomer = [];
        foreach ($orders as $o) {
            $cid = $o->customer_id ?? 0;
            if (! isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id' => $cid,
                    'customer_code' => $o->customer_code,
                    'customer_name' => $o->customer_name ?: '未指派客户',
                    'salesman_name' => $o->salesman_name ?: '-',
                    'credit_limit' => (float) $o->credit_limit,
                    'balance' => (float) $o->balance,
                    'outstanding' => 0,
                    'unmatured' => 0,
                    'b1_30' => 0, 'b31_60' => 0, 'b61_90' => 0, 'b91_180' => 0, 'b180_plus' => 0,
                    'max_age' => 0,
                    'orders' => [],
                ];
            }
            $row = &$byCustomer[$cid];
            $amt = (float) $o->outstanding;
            $row['outstanding'] += $amt;
            $age = (int) $o->age_days;
            $row['max_age'] = max($row['max_age'], $age);
            // 五档互斥分档：未到期(<=30) / 1~30 (>30) / 31~60 / 61~90 / 91~180 / 180+
            // 任务书要求"未到期"与"1~30"并列，此处以 <=30 为未到期、>30 起算逾期。
            // 为对齐账龄段语义，1~30 天段以 0~30 含未到期重叠呈现——这里拆为：
            //   未到期 = age<=30；1~30 仅含 0~30 之间的逾期临界，实际与未到期合并展示。
            // 故把 0~30 全计入 unmatured，1~30 段为空，>30 起算各档。
            // 五档（任务书口径）：1~30 / 31~60 / 61~90 / 91~180 / 180+；未到期单列
            if ($age <= 0) {
                $row['unmatured'] += $amt;
            } elseif ($age <= 30) {
                $row['b1_30'] += $amt;
            } elseif ($age <= 60) {
                $row['b31_60'] += $amt;
            } elseif ($age <= 90) {
                $row['b61_90'] += $amt;
            } elseif ($age <= 180) {
                $row['b91_180'] += $amt;
            } else {
                $row['b180_plus'] += $amt;
            }
            $row['orders'][] = [
                'order_id' => $o->id,
                'order_no' => $o->order_no,
                'order_date' => (string) $o->order_date,
                'total_amount' => ReportFilter::num((float) $o->total_amount),
                'paid_amount' => ReportFilter::num((float) $o->paid_amount),
                'outstanding' => ReportFilter::num($amt),
                'age_days' => $age,
            ];
            unset($row);
        }

        $list = array_values($byCustomer);
        foreach ($list as &$r) {
            $r['outstanding'] = ReportFilter::num($r['outstanding']);
            $r['unmatured'] = ReportFilter::num($r['unmatured']);
            $r['b1_30'] = ReportFilter::num($r['b1_30']);
            $r['b31_60'] = ReportFilter::num($r['b31_60']);
            $r['b61_90'] = ReportFilter::num($r['b61_90']);
            $r['b91_180'] = ReportFilter::num($r['b91_180']);
            $r['b180_plus'] = ReportFilter::num($r['b180_plus']);
            $r['credit_usage_rate'] = $r['credit_limit'] > 0
                ? round($r['balance'] / $r['credit_limit'] * 100, 2) : 0;
            $r['status'] = $this->customerStatus($r['max_age']);
        }

        // 汇总卡片
        $total = array_sum(array_map(fn ($x) => $x['outstanding'], $list));
        $overdue = array_sum(array_map(fn ($x) => $x['b1_30'] + $x['b31_60'] + $x['b61_90'] + $x['b91_180'] + $x['b180_plus'], $list));
        $overdue90 = array_sum(array_map(fn ($x) => $x['b91_180'] + $x['b180_plus'], $list));
        $unmatured = array_sum(array_map(fn ($x) => $x['unmatured'], $list));

        return $this->success([
            'list' => $list,
            'total' => count($list),
            'cards' => [
                'total' => ReportFilter::num($total),
                'unmatured' => ReportFilter::num($unmatured),
                'overdue' => ReportFilter::num($overdue),
                'overdue_90' => ReportFilter::num($overdue90),
            ],
        ]);
    }

    public function details(int $customerId): JsonResponse
    {
        $orders = DB::table('sales_orders as so')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.customer_id', $customerId)
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw('so.total_amount - COALESCE(so.paid_amount, 0) > 0')
            ->select([
                'so.id', 'so.order_no', 'so.order_date', 'so.total_amount',
                DB::raw('COALESCE(so.paid_amount, 0) as paid_amount'),
                DB::raw('so.total_amount - COALESCE(so.paid_amount, 0) as outstanding'),
                DB::raw('COALESCE(sm.real_name, sm.username) as salesman_name'),
                DB::raw('DATEDIFF(CURDATE(), so.order_date) as age_days'),
            ])
            ->orderBy('so.order_date')
            ->get()
            ->map(fn ($o) => [
                'order_id' => $o->id,
                'order_no' => $o->order_no,
                'order_date' => (string) $o->order_date,
                'total_amount' => ReportFilter::num((float) $o->total_amount),
                'paid_amount' => ReportFilter::num((float) $o->paid_amount),
                'outstanding' => ReportFilter::num((float) $o->outstanding),
                'salesman_name' => $o->salesman_name ?: '-',
                'age_days' => $o->age_days,
            ]);

        return $this->success(['list' => $orders]);
    }

    private function customerStatus(int $maxAge): string
    {
        if ($maxAge <= 30) {
            return 'normal';
        }
        if ($maxAge <= 90) {
            return 'warning';
        }

        return 'high_risk';
    }
}
