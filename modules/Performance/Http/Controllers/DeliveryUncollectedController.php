<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 发货未收款（绩效分析 → 发货未收款）。
 *
 * 口径：按销售单聚合已发货（deliveries）且未全额收款的单据——同一销售单多次发货合并一行：
 *   发货日期 = 最早 deliveries.delivery_date，发货金额 = Σdeliveries.total_amount，
 *   未收 = 发货金额 - SO.paid_amount（订单收款回写，覆盖该单全部收款），
 *   未收为负（手工收款单无发货记录等）时按 0 计并仍入表由 GREATEST 归零，
 *   但仅「发货额-已收>0」的单据入表。账龄 = 最早发货日距今天数。
 * 颜色口径：>30 天橙 #E6A23E，>90 天红 #F56C6C（age_level: normal|warn|danger + color）。
 * 筛选：客户/业务员（id 或名称模糊）、按最早发货日 start/end、天数区间 day_from/day_to。
 */
class DeliveryUncollectedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $base = $this->query($filters);

        // totals + 卡片：clone 换成聚合列。MySQL 同一 SELECT 内不能引用 select 别名，
        // 故把未收/天数表达式原样内联（与 query() 的 select 完全一致）。
        $age = 'TIMESTAMPDIFF(DAY, dg.first_delivery_date, CURDATE())';
        $unc = 'GREATEST(dg.delivery_amount - COALESCE(so.paid_amount, 0), 0)';

        $t = $base->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->selectRaw("COUNT(*) as cnt, COALESCE(SUM({$unc}), 0) as total_uncollected")
            ->first();
        $cards = $base->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN {$age} BETWEEN 1 AND 30 THEN {$unc} END), 0) as b1_30,
                COALESCE(SUM(CASE WHEN {$age} BETWEEN 31 AND 60 THEN {$unc} END), 0) as b31_60,
                COALESCE(SUM(CASE WHEN {$age} BETWEEN 61 AND 90 THEN {$unc} END), 0) as b61_90,
                COALESCE(SUM(CASE WHEN {$age} > 90 THEN {$unc} END), 0) as b90_plus
            ")
            ->first();

        // 列表：后端分页
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(500, max(1, (int) $request->input('page_size', 30)));
        $rows = $base
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->map(fn ($r) => $this->mapRow($r))
            ->values();

        $count = (int) ($t->cnt ?? 0);

        return $this->success([
            'list' => $rows,
            'total' => $count,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => max(1, (int) ceil($count / $pageSize)),
            'cards' => [
                'total_uncollected' => ReportFilter::num($t->total_uncollected ?? 0),
                'b1_30' => ReportFilter::num($cards->b1_30 ?? 0),
                'b31_60' => ReportFilter::num($cards->b31_60 ?? 0),
                'b61_90' => ReportFilter::num($cards->b61_90 ?? 0),
                'b90_plus' => ReportFilter::num($cards->b90_plus ?? 0),
            ],
            'totals' => [
                'total_uncollected' => ReportFilter::num($t->total_uncollected ?? 0),
                'count' => $count,
            ],
        ]);
    }

    /** 同筛选导出 CSV（全量，不分页） */
    public function export(Request $request)
    {
        $rows = $this->query($this->filters($request))
            ->orderByDesc('dg.first_delivery_date')
            ->orderBy('so.id')
            ->get()
            ->map(fn ($r) => $this->mapRow($r));

        $headers = ['发货日期', '单据号', '客户', '业务员', '发货金额', '已收', '未收', '账龄(天)', '距今', '状态'];
        $csvRows = $rows->map(fn ($r) => [
            $r['delivery_date'],
            $r['order_no'],
            $r['customer_name'],
            $r['salesman_name'],
            number_format($r['total_amount'], 2, '.', ''),
            number_format($r['paid_amount'], 2, '.', ''),
            number_format($r['uncollected'], 2, '.', ''),
            $r['age_days'],
            $r['days_from_today'],
            $this->statusLabel($r['status']),
        ])->all();

        $csv = ReportFilter::csv('发货未收款 '.date('Y-m-d'), $headers, $csvRows);

        return ReportFilter::csvResponse($csv, 'delivery_uncollected_'.date('Ymd_His').'.csv');
    }

    /**
     * 解析筛选参数（id 数组 / 名称模糊 / 发货日期起止 / 天数区间）
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $date = fn (string ...$keys) => collect($keys)
            ->map(fn ($k) => trim((string) $request->input($k)))
            ->first(fn ($v) => $v !== '');

        return [
            'customerIds' => ReportFilter::toArray($request->input('customer_ids')),
            'salesmanIds' => ReportFilter::toArray($request->input('salesman_ids')),
            'customerName' => $date('customer_name') ?: null,
            'salesmanName' => $date('salesman_name') ?: null,
            // 兼容 start/end 与 start_date/end_date 两种传参
            'start' => $date('start', 'start_date'),
            'end' => $date('end', 'end_date'),
            'dayFrom' => $request->filled('day_from') ? (int) $request->input('day_from') : null,
            'dayTo' => $request->filled('day_to') ? (int) $request->input('day_to') : null,
        ];
    }

    /**
     * 按销售单聚合的未收发货查询（同一销售单一行）
     *
     * 派生表 dg 先把 deliveries 按 order_id 合并（最早发货日 + 发货金额合计），
     * 外层只做 WHERE 过滤——不依赖 select 别名，clone 换聚合列时依然成立。
     */
    private function query(array $f): Builder
    {
        $age = 'TIMESTAMPDIFF(DAY, dg.first_delivery_date, CURDATE())';

        return DB::table(DB::raw('(
            SELECT d.order_id, MIN(d.delivery_date) as first_delivery_date, SUM(d.total_amount) as delivery_amount
            FROM deliveries d
            WHERE d.order_id IS NOT NULL AND d.delivery_date IS NOT NULL
            GROUP BY d.order_id
        ) as dg'))
            ->join('sales_orders as so', 'so.id', '=', 'dg.order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw('dg.delivery_amount - COALESCE(so.paid_amount, 0) > 0')
            ->when($f['customerIds'], fn ($q) => $q->whereIn('so.customer_id', $f['customerIds']))
            ->when($f['salesmanIds'], fn ($q) => $q->whereIn('so.salesman_id', $f['salesmanIds']))
            ->when($f['customerName'], fn ($q) => $q->where('c.name', 'like', '%'.$f['customerName'].'%'))
            ->when($f['salesmanName'], fn ($q) => $q->whereRaw('COALESCE(sm.real_name, sm.username) like ?', ['%'.$f['salesmanName'].'%']))
            // 发货日期起止：作用在「最早发货日」上
            ->when($f['start'], fn ($q) => $q->where('dg.first_delivery_date', '>=', $f['start']))
            ->when($f['end'], fn ($q) => $q->where('dg.first_delivery_date', '<=', $f['end']))
            // 未收天数区间（与原实现一致的 day_from/day_to 口径）
            ->when($f['dayFrom'] !== null, fn ($q) => $q->whereRaw($age.' >= ?', [$f['dayFrom']]))
            ->when($f['dayTo'] !== null, fn ($q) => $q->whereRaw($age.' <= ?', [$f['dayTo']]))
            ->selectRaw("
                so.id,
                so.id as sales_order_id,
                so.order_no,
                so.order_date,
                so.customer_id,
                c.name as customer_name,
                COALESCE(sm.real_name, sm.username) as salesman_name,
                dg.first_delivery_date,
                dg.delivery_amount as total_amount,
                COALESCE(so.paid_amount, 0) as paid_amount,
                GREATEST(dg.delivery_amount - COALESCE(so.paid_amount, 0), 0) as uncollected,
                {$age} as age_days
            ")
            ->orderByDesc('dg.first_delivery_date')
            ->orderBy('so.id');
    }

    /** 聚合行 → 前端行（原有键只增不删） */
    private function mapRow(object $r): array
    {
        $age = (int) $r->age_days;

        return [
            'order_id' => (int) $r->id,
            'sales_order_id' => (int) $r->sales_order_id,
            'order_no' => $r->order_no,
            'order_date' => (string) $r->order_date,
            'delivery_date' => (string) $r->first_delivery_date,
            'first_delivery_date' => (string) $r->first_delivery_date,
            'customer_id' => (int) $r->customer_id,
            'customer_name' => $r->customer_name ?: '-',
            'salesman_name' => $r->salesman_name ?: '-',
            'total_amount' => ReportFilter::num($r->total_amount),
            'paid_amount' => ReportFilter::num($r->paid_amount),
            'uncollected' => ReportFilter::num($r->uncollected),
            'age_days' => $age,
            'days_from_today' => $age,
            'status' => $this->status($age),
            'age_level' => $this->ageLevel($age),
            'color' => $this->ageColor($age),
        ];
    }

    /** >30 天橙 warn、>90 天红 danger */
    private function ageLevel(int $age): string
    {
        if ($age > 90) {
            return 'danger';
        }
        if ($age > 30) {
            return 'warn';
        }

        return 'normal';
    }

    private function ageColor(int $age): string
    {
        return match ($this->ageLevel($age)) {
            'danger' => '#F56C6C',
            'warn' => '#E6A23E',
            default => '',
        };
    }

    /** 前端已消费的状态值：normal/soon/overdue，保留不变 */
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

    private function statusLabel(string $status): string
    {
        return ['normal' => '正常', 'soon' => '临期', 'overdue' => '逾期'][$status] ?? $status;
    }
}
