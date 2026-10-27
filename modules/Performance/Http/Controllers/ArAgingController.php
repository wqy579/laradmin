<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 应收账龄分析（绩效分析 → 应收账龄分析）。
 *
 * 口径：以已审核销售订单（status=approved，排除红冲）为应收单据，
 * 应收 = total_amount - paid_amount（订单自带 paid_amount，由收款单回写）。
 * 账龄基准：as_of_date 查询参数（默认今天），天数 = DATEDIFF(as_of_date, order_date)。
 * 五档：未到期(<=0) / 1~30 / 31~60 / 61~90 / 91~180 / 180+。
 * 汇总 = 一条 GROUP BY 客户的聚合 SQL（分桶全在 SQL CASE 中完成，非逐单回表）；
 * 明细 = 固定第二条批量查询（customer_ids 入参），恒 2 条 SQL，不是 N+1；
 * details 端点按各自 as_of_date 现算天数与桶，不依赖汇总行里的 orders 快照。
 */
class ArAgingController extends Controller
{
    /** 天数上界 → 桶字段名/文案（与前端列 key 一致） */
    private const BUCKETS = [
        ['max' => 0, 'key' => 'unmatured', 'label' => '未到期'],
        ['max' => 30, 'key' => 'b1_30', 'label' => '1~30天'],
        ['max' => 60, 'key' => 'b31_60', 'label' => '31~60天'],
        ['max' => 90, 'key' => 'b61_90', 'label' => '61~90天'],
        ['max' => 180, 'key' => 'b91_180', 'label' => '91~180天'],
        ['max' => PHP_INT_MAX, 'key' => 'b180_plus', 'label' => '180天以上'],
    ];

    /** 汇总列表：4 统计卡 + 5 账龄桶（字段名前端已依赖）+ totals 后端汇总；支持客户/业务员双维度 */
    public function aging(Request $request): JsonResponse
    {
        $asOf = $this->asOfDate($request);
        $dimension = $this->dimension($request);
        $rows = $this->aggregate($request, $asOf, $dimension)->get();

        // 第二条批量明细（供前端展开列；details 端点不依赖它）：恒 2 条 SQL，无 N+1
        if ($dimension === 'salesman') {
            $details = $this->detailRowsBySalesman($rows->pluck('salesman_id')->all(), $asOf)
                ->groupBy('salesman_id');
            $list = $rows->map(fn ($o) => $this->mapSalesmanRow(
                $o,
                $details->get((string) $o->salesman_id, collect())
            ))->values();
        } else {
            $details = $this->detailRows($rows->pluck('customer_id')->all(), $asOf)
                ->groupBy('customer_id');
            $list = $rows->map(fn ($o) => $this->mapCustomerRow(
                $o,
                $details->get((string) $o->customer_id, collect())
            ))->values();
        }

        $sum = fn (string $key) => ReportFilter::num(array_sum(array_map(fn ($r) => $r[$key], $list->all())));

        return $this->success([
            'dimension' => $dimension,
            'list' => $list,
            'total' => $list->count(),
            'as_of_date' => $asOf,
            'cards' => [
                'total' => $sum('outstanding'),
                'unmatured' => $sum('unmatured'),
                'overdue' => ReportFilter::num($sum('b1_30') + $sum('b31_60') + $sum('b61_90') + $sum('b91_180') + $sum('b180_plus')),
                'overdue_90' => ReportFilter::num($sum('b91_180') + $sum('b180_plus')),
            ],
            'totals' => [
                'outstanding' => $sum('outstanding'),
                'unmatured' => $sum('unmatured'),
                'b1_30' => $sum('b1_30'),
                'b31_60' => $sum('b31_60'),
                'b61_90' => $sum('b61_90'),
                'b91_180' => $sum('b91_180'),
                'b180_plus' => $sum('b180_plus'),
            ],
        ]);
    }

    /** 未结明细（客户维度按 customerId、业务员维度按 salesmanId）：按 as_of_date 现算各桶天数与余额 */
    public function details(Request $request, int $id): JsonResponse
    {
        $asOf = $this->asOfDate($request);
        $dimension = $this->dimension($request);

        $list = $dimension === 'salesman'
            ? $this->detailRowsBySalesman([$id], $asOf)->map(fn ($r) => $this->detailPayload($r))->values()
            : $this->detailRows([$id], $asOf)->map(fn ($r) => $this->detailPayload($r))->values();

        return $this->success(['list' => $list, 'as_of_date' => $asOf]);
    }

    /** 同筛选（截止日 + 客户/单号/业务员 + 维度）导出 CSV */
    public function export(Request $request)
    {
        $asOf = $this->asOfDate($request);
        $dimension = $this->dimension($request);
        $rows = $this->aggregate($request, $asOf, $dimension)->get();

        if ($dimension === 'salesman') {
            $headers = ['业务员', '客户数', '应收合计', '未到期', '1~30天', '31~60天', '61~90天', '91~180天', '180天以上', '最长逾期', '状态'];
            $csvRows = $rows->map(function ($o) {
                $r = $this->mapSalesmanRow($o);

                return [
                    $r['salesman_name'],
                    $r['customer_count'],
                    number_format($r['outstanding'], 2, '.', ''),
                    number_format($r['unmatured'], 2, '.', ''),
                    number_format($r['b1_30'], 2, '.', ''),
                    number_format($r['b31_60'], 2, '.', ''),
                    number_format($r['b61_90'], 2, '.', ''),
                    number_format($r['b91_180'], 2, '.', ''),
                    number_format($r['b180_plus'], 2, '.', ''),
                    $r['max_age'],
                    $this->statusLabel($r['status']),
                ];
            })->all();
        } else {
            $headers = ['客户编号', '客户名称', '业务员', '应收合计', '未到期', '1~30天', '31~60天', '61~90天', '91~180天', '180天以上', '最长逾期', '状态'];
            $csvRows = $rows->map(function ($o) {
                $r = $this->mapCustomerRow($o);

                return [
                    $r['customer_code'],
                    $r['customer_name'],
                    $r['salesman_name'],
                    number_format($r['outstanding'], 2, '.', ''),
                    number_format($r['unmatured'], 2, '.', ''),
                    number_format($r['b1_30'], 2, '.', ''),
                    number_format($r['b31_60'], 2, '.', ''),
                    number_format($r['b61_90'], 2, '.', ''),
                    number_format($r['b91_180'], 2, '.', ''),
                    number_format($r['b180_plus'], 2, '.', ''),
                    $r['max_age'],
                    $this->statusLabel($r['status']),
                ];
            })->all();
        }

        $csv = ReportFilter::csv('应收账龄分析 截止 '.$asOf, $headers, $csvRows);

        return ReportFilter::csvResponse($csv, 'ar_aging_'.date('Ymd_His').'.csv');
    }

    /** 按维度聚合的账龄查询：一条 SQL 出全部桶（替代逐单循环分桶），支持客户/业务员双维度 */
    private function aggregate(Request $request, string $asOf, string $dimension): Builder
    {
        $customerIds = ReportFilter::toArray($request->input('customer_ids'));
        $salesmanIds = ReportFilter::toArray($request->input('salesman_ids'));
        $customerName = trim((string) $request->input('customer_name'));
        $salesmanName = trim((string) $request->input('salesman_name'));
        $orderNo = trim((string) $request->input('order_no'));

        // asOf 已由 asOfDate() 校验为 Y-m-d；桶表达式在 select 中出现 8 次，
        // 逐个 ? 绑定会打乱 select 绑定顺序，故内联（格式已校验，无注入面）。
        $age = $this->ageExpr($asOf);
        $amt = '(so.total_amount - COALESCE(so.paid_amount, 0))';

        $base = DB::table('sales_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw('so.total_amount - COALESCE(so.paid_amount, 0) > 0')
            ->whereDate('so.order_date', '<=', $asOf)
            ->when($customerIds, fn ($q) => $q->whereIn('so.customer_id', $customerIds))
            ->when($salesmanIds, fn ($q) => $q->whereIn('so.salesman_id', $salesmanIds))
            ->when($customerName !== '', fn ($q) => $q->where('c.name', 'like', '%'.$customerName.'%'))
            ->when($salesmanName !== '', fn ($q) => $q->whereRaw('COALESCE(sm.real_name, sm.username) like ?', ['%'.$salesmanName.'%']))
            ->when($orderNo !== '', fn ($q) => $q->where('so.order_no', 'like', '%'.$orderNo.'%'));

        if ($dimension === 'salesman') {
            // 业务员维度：按业务员分组，展示负责客户数
            return $base
                ->groupBy(['so.salesman_id', 'sm.real_name', 'sm.username'])
                ->selectRaw("
                    so.salesman_id,
                    COALESCE(sm.real_name, sm.username) as salesman_name,
                    COUNT(DISTINCT so.customer_id) as customer_count,
                    SUM({$amt}) as outstanding,
                    SUM(CASE WHEN {$age} <= 0 THEN {$amt} ELSE 0 END) as unmatured,
                    SUM(CASE WHEN {$age} BETWEEN 1 AND 30 THEN {$amt} ELSE 0 END) as b1_30,
                    SUM(CASE WHEN {$age} BETWEEN 31 AND 60 THEN {$amt} ELSE 0 END) as b31_60,
                    SUM(CASE WHEN {$age} BETWEEN 61 AND 90 THEN {$amt} ELSE 0 END) as b61_90,
                    SUM(CASE WHEN {$age} BETWEEN 91 AND 180 THEN {$amt} ELSE 0 END) as b91_180,
                    SUM(CASE WHEN {$age} > 180 THEN {$amt} ELSE 0 END) as b180_plus,
                    MAX({$age}) as max_age
                ")
                ->orderBy('salesman_name');
        }

        return $base
            ->groupBy(['so.customer_id', 'c.code', 'c.name', 'c.credit_limit', 'c.balance', 'sm.real_name', 'sm.username'])
            ->selectRaw("
                so.customer_id,
                c.code as customer_code,
                c.name as customer_name,
                c.credit_limit,
                c.balance,
                COALESCE(sm.real_name, sm.username) as salesman_name,
                SUM({$amt}) as outstanding,
                SUM(CASE WHEN {$age} <= 0 THEN {$amt} ELSE 0 END) as unmatured,
                SUM(CASE WHEN {$age} BETWEEN 1 AND 30 THEN {$amt} ELSE 0 END) as b1_30,
                SUM(CASE WHEN {$age} BETWEEN 31 AND 60 THEN {$amt} ELSE 0 END) as b31_60,
                SUM(CASE WHEN {$age} BETWEEN 61 AND 90 THEN {$amt} ELSE 0 END) as b61_90,
                SUM(CASE WHEN {$age} BETWEEN 91 AND 180 THEN {$amt} ELSE 0 END) as b91_180,
                SUM(CASE WHEN {$age} > 180 THEN {$amt} ELSE 0 END) as b180_plus,
                MAX({$age}) as max_age
            ")
            ->orderBy('c.name');
    }

    /**
     * 批量取未结明细行（aging 展开列与 details 端点共用）
     *
     * @param  array<int>  $customerIds
     * @return Collection<int, array<string, mixed>> 未分组的明细行
     */
    private function detailRows(array $customerIds, string $asOf): Collection
    {
        if ($customerIds === []) {
            return collect();
        }

        // asOf 已校验，内联避免 select 绑定错位
        return DB::table('sales_orders as so')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->whereIn('so.customer_id', $customerIds)
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw('so.total_amount - COALESCE(so.paid_amount, 0) > 0')
            ->whereDate('so.order_date', '<=', $asOf)
            ->select([
                'so.customer_id',
                'so.id',
                'so.order_no',
                'so.order_date',
                'so.total_amount',
                DB::raw('COALESCE(so.paid_amount, 0) as paid_amount'),
                DB::raw('so.total_amount - COALESCE(so.paid_amount, 0) as outstanding'),
                DB::raw('COALESCE(sm.real_name, sm.username) as salesman_name'),
                DB::raw($this->ageExpr($asOf).' as age_days'),
            ])
            ->orderBy('so.order_date')
            ->orderBy('so.id')
            ->get()
            ->map(fn ($o) => [
                'customer_id' => (int) $o->customer_id,
                'order_id' => (int) $o->id,
                'sales_order_id' => (int) $o->id,
                'order_no' => $o->order_no,
                'order_date' => (string) $o->order_date,
                'total_amount' => ReportFilter::num($o->total_amount),
                'paid_amount' => ReportFilter::num($o->paid_amount),
                'outstanding' => ReportFilter::num($o->outstanding),
                'salesman_name' => $o->salesman_name ?: '-',
                'age_days' => (int) $o->age_days,
                'bucket' => $this->bucketKey((int) $o->age_days),
                'bucket_label' => $this->bucketLabel((int) $o->age_days),
            ]);
    }

    /**
     * 按业务员批量取未结明细行（业务员维度下钻共用）
     *
     * @param  array<int>  $salesmanIds
     * @return Collection<int, array<string, mixed>> 未分组的明细行
     */
    private function detailRowsBySalesman(array $salesmanIds, string $asOf): Collection
    {
        if ($salesmanIds === []) {
            return collect();
        }

        return DB::table('sales_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->whereIn('so.salesman_id', $salesmanIds)
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw('so.total_amount - COALESCE(so.paid_amount, 0) > 0')
            ->whereDate('so.order_date', '<=', $asOf)
            ->select([
                'so.customer_id',
                'so.salesman_id',
                'so.id',
                'so.order_no',
                'so.order_date',
                'so.total_amount',
                'c.name as customer_name',
                DB::raw('COALESCE(so.paid_amount, 0) as paid_amount'),
                DB::raw('so.total_amount - COALESCE(so.paid_amount, 0) as outstanding'),
                DB::raw('COALESCE(sm.real_name, sm.username) as salesman_name'),
                DB::raw($this->ageExpr($asOf).' as age_days'),
            ])
            ->orderBy('so.order_date')
            ->orderBy('so.id')
            ->get()
            ->map(fn ($o) => [
                'customer_id' => (int) $o->customer_id,
                'salesman_id' => (int) $o->salesman_id,
                'customer_name' => $o->customer_name ?: '-',
                'order_id' => (int) $o->id,
                'sales_order_id' => (int) $o->id,
                'order_no' => $o->order_no,
                'order_date' => (string) $o->order_date,
                'total_amount' => ReportFilter::num($o->total_amount),
                'paid_amount' => ReportFilter::num($o->paid_amount),
                'outstanding' => ReportFilter::num($o->outstanding),
                'salesman_name' => $o->salesman_name ?: '-',
                'age_days' => (int) $o->age_days,
                'bucket' => $this->bucketKey((int) $o->age_days),
                'bucket_label' => $this->bucketLabel((int) $o->age_days),
            ]);
    }

    /** details 端点对外结构（list 行） */
    private function detailPayload(array $r): array
    {
        unset($r['customer_id']);

        return $r;
    }

    /** 聚合行 → 前端行（原有键只增不删；$orders 为空集合时不嵌明细，如导出） */
    private function mapCustomerRow(object $o, ?Collection $orders = null): array
    {
        $maxAge = (int) $o->max_age;
        $creditLimit = (float) $o->credit_limit;
        $balance = (float) $o->balance;

        return [
            'customer_id' => (int) $o->customer_id,
            'customer_code' => $o->customer_code,
            'customer_name' => $o->customer_name ?: '未指派客户',
            'salesman_name' => $o->salesman_name ?: '-',
            'credit_limit' => ReportFilter::num($creditLimit),
            'balance' => ReportFilter::num($balance),
            'outstanding' => ReportFilter::num($o->outstanding),
            'unmatured' => ReportFilter::num($o->unmatured),
            'b1_30' => ReportFilter::num($o->b1_30),
            'b31_60' => ReportFilter::num($o->b31_60),
            'b61_90' => ReportFilter::num($o->b61_90),
            'b91_180' => ReportFilter::num($o->b91_180),
            'b180_plus' => ReportFilter::num($o->b180_plus),
            'max_age' => $maxAge,
            'credit_usage_rate' => $creditLimit > 0 ? round($balance / $creditLimit * 100, 2) : 0,
            'status' => $this->customerStatus($maxAge),
            // 前端展开列消费此键；钻取详情走 details（as_of_date 现算），不受此快照影响
            'orders' => $orders
                ? $orders->map(fn ($d) => [
                    'order_id' => $d['order_id'],
                    'sales_order_id' => $d['sales_order_id'],
                    'order_no' => $d['order_no'],
                    'order_date' => $d['order_date'],
                    'total_amount' => $d['total_amount'],
                    'paid_amount' => $d['paid_amount'],
                    'outstanding' => $d['outstanding'],
                    'age_days' => $d['age_days'],
                    'bucket' => $d['bucket'],
                ])->values()
                : [],
        ];
    }

    /** 业务员维度聚合行 → 前端行（键与前端业务员列一致） */
    private function mapSalesmanRow(object $o, ?Collection $orders = null): array
    {
        $maxAge = (int) $o->max_age;

        return [
            'salesman_id' => (int) $o->salesman_id,
            'salesman_name' => $o->salesman_name ?: '未指派业务员',
            'customer_count' => (int) $o->customer_count,
            'outstanding' => ReportFilter::num($o->outstanding),
            'unmatured' => ReportFilter::num($o->unmatured),
            'b1_30' => ReportFilter::num($o->b1_30),
            'b31_60' => ReportFilter::num($o->b31_60),
            'b61_90' => ReportFilter::num($o->b61_90),
            'b91_180' => ReportFilter::num($o->b91_180),
            'b180_plus' => ReportFilter::num($o->b180_plus),
            'max_age' => $maxAge,
            'status' => $this->customerStatus($maxAge),
            'orders' => $orders
                ? $orders->map(fn ($d) => [
                    'order_id' => $d['order_id'],
                    'sales_order_id' => $d['sales_order_id'],
                    'order_no' => $d['order_no'],
                    'order_date' => $d['order_date'],
                    'customer_name' => $d['customer_name'],
                    'total_amount' => $d['total_amount'],
                    'paid_amount' => $d['paid_amount'],
                    'outstanding' => $d['outstanding'],
                    'age_days' => $d['age_days'],
                    'bucket' => $d['bucket'],
                ])->values()
                : [],
        ];
    }

    /** 截止日：默认今天，格式非法回落今天 */
    private function asOfDate(Request $request): string
    {
        $asOf = (string) $request->input('as_of_date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
    }

    /**
     * 跨方言「order_date 距截止日天数」表达式。
     *
     * 生产走 MySQL 用 DATEDIFF；测试/轻量环境是 SQLite，DATEDIFF 不存在，
     * 改用 julianday 差值（结果同为整数天数，行为一致）。
     *
     * @param  string  $asOf  已校验的 Y-m-d 截止日
     */
    private function ageExpr(string $asOf): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(julianday('{$asOf}') - julianday(so.order_date) AS INTEGER)";
        }

        return "DATEDIFF('{$asOf}', so.order_date)";
    }

    /** 维度：customer（默认）/salesman */
    private function dimension(Request $request): string
    {
        $d = (string) $request->input('dimension', '');

        return in_array($d, ['customer', 'salesman'], true) ? $d : 'customer';
    }

    private function bucketKey(int $age): string
    {
        foreach (self::BUCKETS as $b) {
            if ($age <= $b['max']) {
                return $b['key'];
            }
        }

        return 'b180_plus';
    }

    private function bucketLabel(int $age): string
    {
        foreach (self::BUCKETS as $b) {
            if ($age <= $b['max']) {
                return $b['label'];
            }
        }

        return '180天以上';
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

    private function statusLabel(string $status): string
    {
        return ['normal' => '正常', 'warning' => '预警', 'high_risk' => '高风险'][$status] ?? $status;
    }
}
