<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块七：应收 + 余额 + 考勤 + 访店报表（报表管理 → 应收/访店）
 *
 * 4 个维度共用一套筛选条件集，切 tab 只换 dimension：
 *   receivable 应收款明细 / balance 客户余额一览 / attendance 业务员考勤 / visit 业务访店行程。
 *
 * 口径（执行文档 §3 E7，已按现有表结构与既有代码确认，非臆测）：
 *   应收款明细   以 sales_orders 未结清金额（total_amount - paid_amount）为应收，仅
 *               status='approved'、非红冲（original_order_id 为空）、order_date <= as_of_date；
 *               账龄桶沿用 C4（ArAgingController）六档方案（未到期/1~30/31~60/61~90/91~180/180+），
 *               配色复用 C4 的 agingColor 四色板，主表按客户聚合出五档账龄列，
 *               双击客户下钻单据级应收明细（满足「按客户 + 单据」）。
 *   客户余额一览 直接读 customers.balance（欠款余额快照，正=欠款、负=预付款），
 *               负余额标红 #F56C6C；余额语义见 ReceiveController 收款回写 / SalesReturnController 退货冲减。
 *   业务员考勤   read attendances × employees × auth_user，逐条一致（不做二次加工），
 *               状态映射：1正常 2迟到 3早退 4缺勤 5请假。
 *   业务访店行程 实际拜访 visit_logs，计划点位 route_customers（经 routes.employee_id 归属业务员），
 *               覆盖率 = 到达客户数 / 计划客户数（沿用 VisitLogController::achievement 口径）。
 *
 * 纯读模块：只读既有表，不写任何业务表。
 */
class ReceivableController extends Controller
{
    /** 维度 key → 中文名（顺序即 tab 顺序） */
    private const DIMENSIONS = [
        'receivable' => '应收款明细',
        'balance' => '客户余额一览',
        'attendance' => '业务员考勤',
        'visit' => '业务访店行程',
    ];

    /** C4 账龄桶（沿用 ArAgingController::BUCKETS）：天数上界 → 桶 key/文案 */
    private const BUCKETS = [
        ['max' => 0, 'key' => 'unmatured', 'label' => '未到期'],
        ['max' => 30, 'key' => 'b1_30', 'label' => '1~30天'],
        ['max' => 60, 'key' => 'b31_60', 'label' => '31~60天'],
        ['max' => 90, 'key' => 'b61_90', 'label' => '61~90天'],
        ['max' => 180, 'key' => 'b91_180', 'label' => '91~180天'],
        ['max' => PHP_INT_MAX, 'key' => 'b180_plus', 'label' => '180天以上'],
    ];

    /** 考勤状态：tinyint → 文案（与 attendances.status 注释一致） */
    private const ATTENDANCE_STATUS = [
        1 => '正常',
        2 => '迟到',
        3 => '早退',
        4 => '缺勤',
        5 => '请假',
    ];

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        if ($dimension === 'receivable') {
            // 应收：按客户聚合（含账龄桶），手工分页（桶由 SQL CASE 一次性算，恒 2 条 SQL）
            $rows = collect($this->receivableRows($params));
        } elseif ($dimension === 'balance') {
            $rows = collect($this->balanceRows($params));
        } elseif ($dimension === 'attendance') {
            $rows = collect($this->attendanceRows($params));
        } else {
            $rows = collect($this->visitRows($params));
        }

        $data = ReportFilter::page($this->paginateRows($rows, $request, $params));
        $data['dimension'] = $dimension;
        $data['dimensions'] = self::DIMENSIONS;
        $data['columns'] = $this->columns($dimension);
        $data['summary'] = $this->summary($rows, $dimension);

        return $this->success($data);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        $rows = match ($dimension) {
            'receivable' => collect($this->receivableRows($params))->all(),
            'balance' => $this->balanceRows($params),
            'attendance' => $this->attendanceRows($params),
            default => $this->visitRows($params),
        };

        return ReportFilter::excelResponse(
            ReportFilter::excel('应收/访店 - '.(self::DIMENSIONS[$dimension] ?? ''), $this->columns($dimension), $rows),
            'receivable_'.$dimension.'.xls'
        );
    }

    private function dimension(array $params): string
    {
        $dimension = (string) ($params['dimension'] ?? 'receivable');

        return array_key_exists($dimension, self::DIMENSIONS) ? $dimension : 'receivable';
    }

    // ================================================================
    // 穿透（可穿）：双击行 → 明细
    //   receivable/balance 回该客户未结单据；attendance 回该员工当日拜访；visit 回该业务员行程明细。
    // ================================================================

    public function drill(Request $request): JsonResponse
    {
        $dimension = $this->dimension($request->all());

        return match ($dimension) {
            'receivable' => $this->drillReceivable($request),
            'balance' => $this->drillReceivable($request), // 余额穿透 = 该客户未结应收单据
            'attendance' => $this->drillAttendance($request),
            'visit' => $this->drillVisit($request),
            default => $this->error('未知维度', 422),
        };
    }

    /** 应收/余额穿透：该客户未结应收单据明细（按 as_of_date 现算账龄与桶） */
    private function drillReceivable(Request $request): JsonResponse
    {
        $validated = $request->validate(['customer_id' => 'required|integer']);

        $customer = DB::table('customers')->where('id', $validated['customer_id'])->first(['id', 'code', 'name']);
        if (! $customer) {
            return $this->error('客户不存在', 404);
        }

        $params = ReportFilter::parse($request);
        $asOf = $this->asOfDate($request);

        $docs = DB::table('sales_orders as so')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.customer_id', $validated['customer_id'])
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw('so.total_amount - COALESCE(so.paid_amount, 0) > 0')
            ->whereDate('so.order_date', '<=', $asOf)
            ->select([
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
                'order_no' => $o->order_no,
                'order_date' => (string) $o->order_date,
                'salesman_name' => $o->salesman_name ?: '-',
                'total_amount' => ReportFilter::num($o->total_amount),
                'paid_amount' => ReportFilter::num($o->paid_amount),
                'outstanding' => ReportFilter::num($o->outstanding),
                'age_days' => (int) $o->age_days,
                'bucket_label' => $this->bucketLabel((int) $o->age_days),
            ])
            ->values()
            ->all();

        return $this->success([
            'header' => [
                'title' => '客户应收明细',
                'code' => $customer->code,
                'desc' => [
                    ['label' => '客户', 'value' => $customer->name],
                    ['label' => '截止日期', 'value' => $asOf],
                ],
                'totals' => [
                    ['label' => '应收合计', 'value' => array_sum(array_column($docs, 'outstanding'))],
                ],
            ],
            'items' => $docs,
            'columns' => [
                ['prop' => 'order_no', 'label' => '单据号', 'width' => 150, 'align' => 'left', 'type' => 'text', 'bold' => true],
                ['prop' => 'order_date', 'label' => '日期', 'width' => 100, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'total_amount', 'label' => '应收金额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                ['prop' => 'paid_amount', 'label' => '已收', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                ['prop' => 'outstanding', 'label' => '未收', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true, 'cell_class' => 'rp-danger'],
                ['prop' => 'age_days', 'label' => '账龄(天)', 'width' => 90, 'align' => 'center', 'type' => 'int'],
                ['prop' => 'bucket_label', 'label' => '账龄档', 'width' => 100, 'align' => 'center', 'type' => 'text'],
            ],
        ]);
    }

    /** 考勤穿透：该员工当日拜访记录（visit_logs 按员工 + 日期） */
    private function drillAttendance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_user_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
        ]);

        $user = DB::table('auth_user')->where('id', $validated['employee_user_id'])->first(['id', 'real_name', 'username']);
        $name = $user ? ($user->real_name ?: $user->username) : '未知';

        $visits = DB::table('visit_logs as vl')
            ->leftJoin('customers as c', 'c.id', '=', 'vl.customer_id')
            ->where('vl.employee_id', $validated['employee_user_id'])
            ->whereDate('vl.checkin_time', $validated['date'])
            ->select([
                'vl.visit_no',
                'vl.checkin_time',
                'vl.checkout_time',
                'vl.visit_duration',
                'vl.visit_result',
                'c.code as customer_code',
                'c.name as customer_name',
            ])
            ->orderBy('vl.checkin_time')
            ->get()
            ->map(fn ($o) => [
                'visit_no' => $o->visit_no,
                'checkin_time' => $o->checkin_time,
                'checkout_time' => $o->checkout_time ?: '—',
                'customer_code' => $o->customer_code ?: '—',
                'customer_name' => $o->customer_name ?: '—',
                'visit_duration' => $o->visit_duration !== null ? (int) $o->visit_duration : 0,
                'visit_result' => $o->visit_result ?: '—',
            ])
            ->values()
            ->all();

        return $this->success([
            'header' => [
                'title' => '当日拜访明细',
                'code' => (string) $validated['employee_user_id'],
                'desc' => [
                    ['label' => '业务员', 'value' => $name],
                    ['label' => '日期', 'value' => $validated['date']],
                ],
                'totals' => [
                    ['label' => '拜访数', 'value' => count($visits)],
                    ['label' => '总时长(分)', 'value' => array_sum(array_column($visits, 'visit_duration'))],
                ],
            ],
            'items' => $visits,
            'columns' => [
                ['prop' => 'visit_no', 'label' => '拜访单号', 'width' => 150, 'align' => 'left', 'type' => 'text', 'bold' => true],
                ['prop' => 'checkin_time', 'label' => '签到时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'checkout_time', 'label' => '签退时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'visit_duration', 'label' => '时长(分)', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                ['prop' => 'visit_result', 'label' => '拜访结果', 'width' => 110, 'align' => 'center', 'type' => 'text'],
            ],
        ]);
    }

    /** 访店穿透：该业务员区间内行程明细（visit_logs 逐条） */
    private function drillVisit(Request $request): JsonResponse
    {
        $validated = $request->validate(['salesman_id' => 'required|integer']);

        $user = DB::table('auth_user')->where('id', $validated['salesman_id'])->first(['id', 'real_name', 'username']);
        $name = $user ? ($user->real_name ?: $user->username) : '未知';

        $params = ReportFilter::parse($request);
        $start = $params['start_date'] ?? '1970-01-01';
        $end = $params['end_date'] ?? '2999-12-31';

        $visits = DB::table('visit_logs as vl')
            ->leftJoin('customers as c', 'c.id', '=', 'vl.customer_id')
            ->leftJoin('routes as r', 'r.id', '=', 'vl.route_id')
            ->where('vl.employee_id', $validated['salesman_id'])
            ->whereBetween('vl.checkin_time', [$start.' 00:00:00', $end.' 23:59:59'])
            ->select([
                'vl.visit_no',
                'vl.checkin_time',
                'vl.checkout_time',
                'vl.visit_duration',
                'vl.visit_result',
                'c.code as customer_code',
                'c.name as customer_name',
                'r.name as route_name',
            ])
            ->orderByDesc('vl.checkin_time')
            ->get()
            ->map(fn ($o) => [
                'visit_no' => $o->visit_no,
                'checkin_time' => $o->checkin_time,
                'checkout_time' => $o->checkout_time ?: '—',
                'customer_code' => $o->customer_code ?: '—',
                'customer_name' => $o->customer_name ?: '—',
                'route_name' => $o->route_name ?: '—',
                'visit_duration' => $o->visit_duration !== null ? (int) $o->visit_duration : 0,
                'visit_result' => $o->visit_result ?: '—',
            ])
            ->values()
            ->all();

        return $this->success([
            'header' => [
                'title' => '访店行程明细',
                'code' => (string) $validated['salesman_id'],
                'desc' => [
                    ['label' => '业务员', 'value' => $name],
                    ['label' => '起止日期', 'value' => $start.' ~ '.$end],
                ],
                'totals' => [
                    ['label' => '拜访数', 'value' => count($visits)],
                    ['label' => '总时长(分)', 'value' => array_sum(array_column($visits, 'visit_duration'))],
                ],
            ],
            'items' => $visits,
            'columns' => [
                ['prop' => 'visit_no', 'label' => '拜访单号', 'width' => 150, 'align' => 'left', 'type' => 'text', 'bold' => true],
                ['prop' => 'checkin_time', 'label' => '签到时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'checkout_time', 'label' => '签退时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'route_name', 'label' => '线路', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'visit_duration', 'label' => '时长(分)', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                ['prop' => 'visit_result', 'label' => '拜访结果', 'width' => 110, 'align' => 'center', 'type' => 'text'],
            ],
        ]);
    }

    // ================================================================
    // 应收款明细：sales_orders 未结清，按客户聚合 + 六档账龄
    // ================================================================

    /** 按客户聚合的应收账龄行（一条 SQL 出全部桶，恒 2 条 SQL 无 N+1） */
    private function receivableRows(array $params): array
    {
        $asOf = $this->asOfDateFromParams($params);
        $age = $this->ageExpr($asOf);
        $amt = '(so.total_amount - COALESCE(so.paid_amount, 0))';

        $query = DB::table('sales_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->where('so.status', 'approved')
            ->whereNull('so.original_order_id')
            ->whereRaw("{$amt} > 0")
            ->whereDate('so.order_date', '<=', $asOf)
            ->when(! empty($params['customer_ids']), fn ($q) => $q->whereIn('so.customer_id', $params['customer_ids']))
            ->when(! empty($params['salesman_ids']), fn ($q) => $q->whereIn('so.salesman_id', $params['salesman_ids']))
            ->when(! empty($params['customer_name']), fn ($q) => $q->where('c.name', 'like', '%'.$params['customer_name'].'%'))
            ->when(! empty($params['order_no']), fn ($q) => $q->where('so.order_no', 'like', '%'.$params['order_no'].'%'))
            ->groupBy(['so.customer_id', 'c.code', 'c.name', 'sm.real_name', 'sm.username'])
            ->selectRaw("
                so.customer_id,
                c.code as customer_code,
                c.name as customer_name,
                COALESCE(sm.real_name, sm.username) as salesman_name,
                COUNT(*) as order_count,
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

        return $query->get()->map(fn ($o) => [
            'customer_id' => (int) $o->customer_id,
            'customer_code' => $o->customer_code ?: '-',
            'customer_name' => $o->customer_name ?: '未指派客户',
            'salesman_name' => $o->salesman_name ?: '-',
            'order_count' => (int) $o->order_count,
            'outstanding' => ReportFilter::num($o->outstanding),
            'unmatured' => ReportFilter::num($o->unmatured),
            'b1_30' => ReportFilter::num($o->b1_30),
            'b31_60' => ReportFilter::num($o->b31_60),
            'b61_90' => ReportFilter::num($o->b61_90),
            'b91_180' => ReportFilter::num($o->b91_180),
            'b180_plus' => ReportFilter::num($o->b180_plus),
            'max_age' => (int) $o->max_age,
        ])->all();
    }

    // ================================================================
    // 客户余额一览：customers.balance 快照
    // ================================================================

    private function balanceRows(array $params): array
    {
        return DB::table('customers as c')
            ->leftJoin('customer_levels as cl', 'cl.id', '=', 'c.level_id')
            ->leftJoin('routes as r', 'r.id', '=', 'c.route_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'r.employee_id')
            ->where('c.is_active', 1)
            ->when(! empty($params['customer_ids']), fn ($q) => $q->whereIn('c.id', $params['customer_ids']))
            ->when(! empty($params['customer_category']), fn ($q) => $q->whereIn('c.category', $params['customer_category']))
            ->when(! empty($params['customer_level_ids']), fn ($q) => $q->whereIn('c.level_id', $params['customer_level_ids']))
            ->when(! empty($params['channel_category']), fn ($q) => $q->whereIn('c.channel_category', $params['channel_category']))
            ->when(! empty($params['customer_name']), fn ($q) => $q->where('c.name', 'like', '%'.$params['customer_name'].'%'))
            ->select([
                'c.id as customer_id',
                'c.code',
                'c.name',
                'c.category',
                'c.credit_limit',
                'c.balance',
                'cl.name as level_name',
                'c.channel_category',
                'c.channel_sub_category',
                'r.name as route_name',
                DB::raw('COALESCE(sm.real_name, sm.username) as salesman_name'),
            ])
            ->orderBy('c.name')
            ->get()
            ->map(function ($o) {
                $creditLimit = (float) $o->credit_limit;
                $balance = (float) $o->balance;

                return [
                    'customer_id' => (int) $o->customer_id,
                    'customer_code' => $o->code ?: '-',
                    'customer_name' => $o->name ?: '未指派客户',
                    'category' => $o->category ?: '—',
                    'level_name' => $o->level_name ?: '—',
                    'channel_category' => $o->channel_category ?: '—',
                    'route_name' => $o->route_name ?: '—',
                    'salesman_name' => $o->salesman_name ?: '-',
                    'credit_limit' => ReportFilter::num($creditLimit),
                    'balance' => ReportFilter::num($balance),
                    'credit_usage_rate' => $creditLimit > 0 ? round($balance / $creditLimit * 100, 2) : 0,
                    'status' => $balance > $creditLimit && $creditLimit > 0 ? 'over' : 'normal',
                ];
            })
            ->all();
    }

    // ================================================================
    // 业务员考勤：attendances × employees × auth_user（逐条一致）
    // ================================================================

    private function attendanceRows(array $params): array
    {
        $query = DB::table('attendances as a')
            ->leftJoin('employees as e', 'e.id', '=', 'a.employee_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'e.user_id')
            ->when(! empty($params['salesman_ids']), fn ($q) => $q->whereIn('e.user_id', $params['salesman_ids']));

        if (! empty($params['start_date']) || ! empty($params['end_date'])) {
            $query->whereBetween('a.date', [
                $params['start_date'] ?: '1970-01-01',
                $params['end_date'] ?: '2999-12-31',
            ]);
        }

        return $query->select([
            'a.id as attendance_id',
            'a.employee_id',
            'a.date',
            'a.clock_in',
            'a.clock_out',
            'a.status',
            'a.visit_count',
            'a.remark',
            'e.user_id as employee_user_id',
            'e.code as employee_code',
            'e.name as employee_name',
            'e.department',
            'e.position',
            DB::raw('COALESCE(u.real_name, u.username) as username'),
        ])
            ->orderByDesc('a.date')
            ->orderBy('e.code')
            ->get()
            ->map(function ($o) {
                $status = (int) $o->status;

                return [
                    'attendance_id' => (int) $o->attendance_id,
                    'employee_user_id' => $o->employee_user_id !== null ? (int) $o->employee_user_id : null,
                    'employee_code' => $o->employee_code ?: '-',
                    'employee_name' => $o->employee_name ?: ($o->username ?: '-'),
                    'department' => $o->department ?: '—',
                    'position' => $o->position ?: '—',
                    'date' => (string) $o->date,
                    'clock_in' => $o->clock_in,
                    'clock_out' => $o->clock_out ?: '—',
                    'status' => $status,
                    'status_label' => self::ATTENDANCE_STATUS[$status] ?? '未知',
                    'visit_count' => (int) $o->visit_count,
                    'remark' => $o->remark ?: '',
                ];
            })
            ->all();
    }

    // ================================================================
    // 业务访店行程：实际拜访 visit_logs / 计划点位 route_customers
    // ================================================================

    private function visitRows(array $params): array
    {
        $start = $params['start_date'] ?? '1970-01-01';
        $end = $params['end_date'] ?? '2999-12-31';

        // 实际拜访按业务员汇总（visit_logs.employee_id 直接指 auth_user）
        $actual = DB::table('visit_logs as vl')
            ->whereBetween('vl.checkin_time', [$start.' 00:00:00', $end.' 23:59:59'])
            ->when(! empty($params['salesman_ids']), fn ($q) => $q->whereIn('vl.employee_id', $params['salesman_ids']))
            ->groupBy('vl.employee_id')
            ->selectRaw('
                vl.employee_id,
                COUNT(*) as actual_visits,
                COUNT(DISTINCT vl.customer_id) as reached_customers,
                SUM(COALESCE(vl.visit_duration, 0)) as total_duration
            ')
            ->get()
            ->keyBy('employee_id');

        // 计划点位（沿用 VisitLogController::achievement 口径）：业务员名下线路的客户数，
        // 线路 = routes.employee_id 归属的业务员；客户挂线路 = customers.route_id 非空且启用。
        // 一条线路挂多少启用客户，即该业务员一轮要拜访完的量。
        $plannedByRoute = DB::table('customers as c')
            ->where('c.is_active', 1)
            ->whereNotNull('c.route_id')
            ->when(! empty($params['salesman_ids']), fn ($q) => $q->whereIn(
                'c.route_id',
                DB::table('routes')->whereIn('employee_id', $params['salesman_ids'])->select('id')
            ))
            ->selectRaw('c.route_id, COUNT(*) as plan_customers')
            ->groupBy('c.route_id')
            ->pluck('plan_customers', 'route_id');

        $routes = DB::table('routes')->get(['id', 'employee_id'])->groupBy('employee_id');
        $planned = collect();
        foreach ($routes as $salesmanId => $myRoutes) {
            if (! empty($params['salesman_ids']) && ! in_array((int) $salesmanId, $params['salesman_ids'], true)) {
                continue;
            }
            $planned[$salesmanId] = (object) [
                'plan_customers' => $myRoutes->sum(fn ($r) => (int) ($plannedByRoute[$r->id] ?? 0)),
            ];
        }

        $ids = $actual->keys()->merge($planned->keys())->filter()->unique()->values();

        $users = DB::table('auth_user')
            ->whereIn('id', $ids->all())
            ->get(['id', 'real_name', 'username'])
            ->keyBy('id');

        return $ids->map(function ($id) use ($actual, $planned, $users) {
            $a = $actual->get($id);
            $p = $planned->get($id);
            $u = $users->get($id);

            $plan = (int) ($p->plan_customers ?? 0);
            $reached = (int) ($a->reached_customers ?? 0);

            return [
                'salesman_id' => (int) $id,
                'salesman_name' => $u ? ($u->real_name ?: $u->username) : '未知业务员',
                'plan_customers' => $plan,
                'actual_visits' => (int) ($a->actual_visits ?? 0),
                'reached_customers' => $reached,
                'coverage_rate' => $plan > 0 ? round($reached / $plan * 100, 1) : 0,
                'total_duration' => (int) ($a->total_duration ?? 0),
                'avg_duration' => (int) ($a->actual_visits ?? 0) > 0 ? (int) round(($a->total_duration ?? 0) / $a->actual_visits) : 0,
            ];
        })->values()->all();
    }

    // ================================================================
    // 公共工具：分页 / 合计 / 列定义 / 账龄表达式
    // ================================================================

    private function paginateRows($rows, Request $request, array $params): LengthAwarePaginator
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = min(500, max(1, (int) ($params['page_size'] ?? 20)));

        return new LengthAwarePaginator(
            $rows->forPage($page, $pageSize)->values(),
            $rows->count(),
            $pageSize,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /** 合计：对全量行求和，键名与各维度列 prop 对齐 */
    private function summary($rows, string $dimension): array
    {
        $rows = collect($rows);

        switch ($dimension) {
            case 'receivable':
                return [
                    'outstanding' => ReportFilter::num($rows->sum('outstanding')),
                    'unmatured' => ReportFilter::num($rows->sum('unmatured')),
                    'b1_30' => ReportFilter::num($rows->sum('b1_30')),
                    'b31_60' => ReportFilter::num($rows->sum('b31_60')),
                    'b61_90' => ReportFilter::num($rows->sum('b61_90')),
                    'b91_180' => ReportFilter::num($rows->sum('b91_180')),
                    'b180_plus' => ReportFilter::num($rows->sum('b180_plus')),
                ];
            case 'balance':
                return [
                    'credit_limit' => ReportFilter::num($rows->sum('credit_limit')),
                    'balance' => ReportFilter::num($rows->sum('balance')),
                ];
            case 'attendance':
                return [
                    'visit_count' => (int) $rows->sum('visit_count'),
                    'count' => $rows->count(),
                ];
            case 'visit':
                return [
                    'plan_customers' => (int) $rows->sum('plan_customers'),
                    'actual_visits' => (int) $rows->sum('actual_visits'),
                    'reached_customers' => (int) $rows->sum('reached_customers'),
                    'total_duration' => (int) $rows->sum('total_duration'),
                ];
        }

        return [];
    }

    /** 列定义（JSON 驱动，前端 ReportTable 按 type 渲染）；账龄列配色复用 C4 agingColor 四色板 */
    private function columns(string $dimension): array
    {
        switch ($dimension) {
            case 'receivable':
                return [
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'order_count', 'label' => '单据数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'outstanding', 'label' => '应收余额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'unmatured', 'label' => '未到期', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'b1_30', 'label' => '1~30天', 'width' => 110, 'align' => 'right', 'type' => 'money', 'cell_class' => 'rp-aging-ok'],
                    ['prop' => 'b31_60', 'label' => '31~60天', 'width' => 110, 'align' => 'right', 'type' => 'money', 'cell_class' => 'rp-aging-warn'],
                    ['prop' => 'b61_90', 'label' => '61~90天', 'width' => 110, 'align' => 'right', 'type' => 'money', 'cell_class' => 'rp-aging-danger'],
                    ['prop' => 'b91_180', 'label' => '91~180天', 'width' => 120, 'align' => 'right', 'type' => 'money', 'cell_class' => 'rp-aging-critical'],
                    ['prop' => 'b180_plus', 'label' => '180天以上', 'width' => 120, 'align' => 'right', 'type' => 'money', 'cell_class' => 'rp-aging-critical'],
                    ['prop' => 'max_age', 'label' => '最长逾期', 'width' => 100, 'align' => 'center', 'type' => 'int', 'cell_class' => 'rp-danger'],
                ];

            case 'balance':
                return [
                    ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 190, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'category', 'label' => '客户分类', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'level_name', 'label' => '客户等级', 'width' => 90, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'channel_category', 'label' => '渠道大类', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'route_name', 'label' => '线路', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'credit_limit', 'label' => '信用额度', 'width' => 120, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'balance', 'label' => '欠款余额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true, 'negative_red' => true],
                    ['prop' => 'credit_usage_rate', 'label' => '额度占用率', 'width' => 110, 'align' => 'right', 'type' => 'percent', 'cell_class' => 'rp-aging-warn'],
                    ['prop' => 'status', 'label' => '状态', 'width' => 90, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        'over' => ['label' => '超额度', 'color' => 'danger'],
                        'normal' => ['label' => '正常', 'color' => 'success'],
                    ]],
                ];

            case 'attendance':
                return [
                    ['prop' => 'date', 'label' => '考勤日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'employee_code', 'label' => '员工编号', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'employee_name', 'label' => '员工姓名', 'width' => 120, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'department', 'label' => '部门', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'position', 'label' => '职位', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'clock_in', 'label' => '签到时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'clock_out', 'label' => '签退时间', 'width' => 150, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'status_label', 'label' => '考勤状态', 'width' => 100, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '正常' => ['label' => '正常', 'color' => 'success'],
                        '迟到' => ['label' => '迟到', 'color' => 'warning'],
                        '早退' => ['label' => '早退', 'color' => 'warning'],
                        '缺勤' => ['label' => '缺勤', 'color' => 'danger'],
                        '请假' => ['label' => '请假', 'color' => 'info'],
                        '未知' => ['label' => '未知', 'color' => 'info'],
                    ]],
                    ['prop' => 'visit_count', 'label' => '拜访客户数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'remark', 'label' => '备注', 'width' => 140, 'align' => 'left', 'type' => 'text'],
                ];

            case 'visit':
            default:
                return [
                    ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 150, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'plan_customers', 'label' => '应拜访点位', 'width' => 110, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'actual_visits', 'label' => '实际拜访', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'reached_customers', 'label' => '到达客户数', 'width' => 110, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'coverage_rate', 'label' => '覆盖率', 'width' => 110, 'align' => 'right', 'type' => 'coverage', 'danger_below' => 50, 'bold' => true],
                    ['prop' => 'total_duration', 'label' => '总时长(分)', 'width' => 110, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'avg_duration', 'label' => '平均时长(分)', 'width' => 120, 'align' => 'right', 'type' => 'int'],
                ];
        }
    }

    /** 截止日：默认今天，格式非法回落今天 */
    private function asOfDate(Request $request): string
    {
        $asOf = (string) $request->input('as_of_date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
    }

    /** 从已 parse 的 params 取截止日（as_of_date 已含在 ReportFilter::parse 里） */
    private function asOfDateFromParams(array $params): string
    {
        $asOf = (string) ($params['as_of_date'] ?? '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
    }

    /**
     * 跨方言「order_date 距截止日天数」表达式（沿用 ArAgingController::ageExpr）：
     * MySQL 用 DATEDIFF；测试/轻量环境 SQLite 用 julianday 差值。
     */
    private function ageExpr(string $asOf): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(julianday('{$asOf}') - julianday(so.order_date) AS INTEGER)";
        }

        return "DATEDIFF('{$asOf}', so.order_date)";
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
}
