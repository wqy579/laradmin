<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\VisitLog;

class VisitLogController extends Controller
{
    public function index(Request $request)
    {
        $query = VisitLog::with(['employee', 'customer', 'route']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('route_id')) {
            $query->where('route_id', $request->route_id);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('date_start')) {
            $query->whereDate('checkin_time', '>=', $request->date_start);
        }
        if ($request->filled('date_end')) {
            $query->whereDate('checkin_time', '<=', $request->date_end);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $query->orderBy('checkin_time', 'desc');
        $visits = $query->paginate($request->integer('page_size', 20));

        return $this->paginated($visits);
    }

    public function show(VisitLog $visitLog)
    {
        $visitLog->load(['employee', 'customer', 'route']);

        return $this->success($visitLog);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:auth_user,id',
            'customer_id' => 'required|exists:customers,id',
            'route_id' => 'nullable|exists:routes,id',
            'checkin_time' => 'required|date',
            'checkin_lat' => 'nullable|numeric',
            'checkin_lng' => 'nullable|numeric',
            'checkin_address' => 'nullable|string|max:255',
            'checkin_photo' => 'nullable|string|max:255',
            'checkout_time' => 'nullable|date|after:checkin_time',
            'checkout_lat' => 'nullable|numeric',
            'checkout_lng' => 'nullable|numeric',
            'checkout_photo' => 'nullable|string|max:255',
            'visit_duration' => 'nullable|integer|min:0',
            'visit_result' => 'nullable|string|max:50',
            'remark' => 'nullable|string',
        ]);

        $visitLog = VisitLog::create($validated);

        return $this->created($visitLog, '创建成功');
    }

    public function update(Request $request, VisitLog $visitLog)
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:auth_user,id',
            'customer_id' => 'nullable|exists:customers,id',
            'route_id' => 'nullable|exists:routes,id',
            'checkin_time' => 'nullable|date',
            'checkin_lat' => 'nullable|numeric',
            'checkin_lng' => 'nullable|numeric',
            'checkin_address' => 'nullable|string|max:255',
            'checkin_photo' => 'nullable|string|max:255',
            'checkout_time' => 'nullable|date',
            'checkout_lat' => 'nullable|numeric',
            'checkout_lng' => 'nullable|numeric',
            'checkout_photo' => 'nullable|string|max:255',
            'visit_duration' => 'nullable|integer|min:0',
            'visit_result' => 'nullable|string|max:50',
            'remark' => 'nullable|string',
        ]);

        $visitLog->update($validated);

        return $this->success($visitLog, '更新成功');
    }

    public function destroy(VisitLog $visitLog)
    {
        $visitLog->delete();

        return $this->success(null, '删除成功');
    }

    // =========================================================================
    // 模块八：访店达成率（拜访管理 → 访店达成率）
    // =========================================================================

    /**
     * 年度 / 月度达成率
     *
     * 计划客户数来自「业务员名下线路的客户数」：线路就是业务员的责任片区，
     * 一条线路挂多少客户，就是这个业务员一轮要拜访完的量。
     * 实际访店数以签到记录计数（有签到即算一次上门），达成客户数按客户去重。
     *
     * 月度计划访店数沿用同一口径：一个月应把线路客户走一遍，所以等于计划客户数。
     */
    public function achievement(Request $request)
    {
        $mode = $request->input('mode') === 'month' ? 'month' : 'year';
        $base = $request->filled('date') ? Carbon::parse($request->input('date')) : now();

        $start = $mode === 'year' ? $base->copy()->startOfYear() : $base->copy()->startOfMonth();
        $end = $mode === 'year' ? $base->copy()->endOfYear() : $base->copy()->endOfMonth();

        $salesmanIds = $this->intArray($request->input('salesman_ids'));

        $users = DB::table('auth_user')
            ->where('status', 1)
            ->when($salesmanIds, fn ($q) => $q->whereIn('id', $salesmanIds))
            ->orderBy('id')
            ->get(['id', 'real_name', 'username']);

        $routes = DB::table('routes')->get(['id', 'name', 'employee_id']);
        $routeCustomers = DB::table('customers')
            ->where('is_active', 1)
            ->whereNotNull('route_id')
            ->selectRaw('route_id, COUNT(*) as cnt')
            ->groupBy('route_id')
            ->pluck('cnt', 'route_id');

        $employeeCodes = DB::table('employees')->pluck('code', 'user_id');

        // 只取三个字段，按月/周的拆分放在 PHP 里做：
        // MySQL 的 MONTH() 与 SQLite 的 strftime() 不通用，写 SQL 就得为两种方言各写一份
        $logs = DB::table('visit_logs')
            ->whereBetween('checkin_time', [$start->toDateString().' 00:00:00', $end->toDateString().' 23:59:59'])
            ->get(['employee_id', 'customer_id', 'checkin_time'])
            ->groupBy('employee_id');

        $list = $users->map(function ($user) use ($routes, $routeCustomers, $logs, $mode, $employeeCodes, $start) {
            $myRoutes = $routes->where('employee_id', $user->id);
            $planCustomers = (int) $myRoutes->sum(fn ($r) => (int) ($routeCustomers[$r->id] ?? 0));

            $my = $logs->get($user->id, collect());
            $actualVisits = $my->count();
            $reachedCustomers = $my->pluck('customer_id')->filter()->unique()->count();

            $rate = fn (int $visits) => $planCustomers > 0 ? round($visits / $planCustomers * 100, 1) : 0;

            $buckets = $mode === 'year'
                ? $this->monthBuckets($my, $planCustomers)
                : $this->weekBuckets($my, $planCustomers, $start);

            return [
                'employee_id' => $user->id,
                'employee_code' => $employeeCodes[$user->id] ?? $user->username,
                'employee_name' => $user->real_name ?: $user->username,
                'route_name' => $myRoutes->pluck('name')->implode('、') ?: '—',
                'plan_customers' => $planCustomers,
                'actual_visits' => $actualVisits,
                'reached_customers' => $reachedCustomers,
                'achievement_rate' => $rate($actualVisits),
                'coverage_rate' => $planCustomers > 0 ? round($reachedCustomers / $planCustomers * 100, 1) : 0,
                'plan_visits' => $planCustomers,
                'buckets' => $buckets,
            ];
        })->values()->all();

        return $this->success([
            'mode' => $mode,
            'period' => [$start->toDateString(), $end->toDateString()],
            'list' => $list,
            'summary' => [
                'plan_customers' => array_sum(array_column($list, 'plan_customers')),
                'actual_visits' => array_sum(array_column($list, 'actual_visits')),
                'reached_customers' => array_sum(array_column($list, 'reached_customers')),
                'salesman_count' => count($list),
            ],
        ]);
    }

    /**
     * 达成率走势（双击行打开的折线图数据）
     *
     * 参考线由前端固定画 80% / 100%，这里只出数值序列。
     */
    public function trend(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'mode' => 'nullable|in:year,month',
            'date' => 'nullable|date',
        ]);

        $mode = $validated['mode'] ?? 'year';
        $employeeId = (int) $validated['employee_id'];
        $base = ! empty($validated['date']) ? Carbon::parse($validated['date']) : now();

        $start = $mode === 'year' ? $base->copy()->startOfYear() : $base->copy()->startOfMonth();
        $end = $mode === 'year' ? $base->copy()->endOfYear() : $base->copy()->endOfMonth();

        $planCustomers = (int) DB::table('routes as r')
            ->join('customers as c', 'c.route_id', '=', 'r.id')
            ->where('r.employee_id', $employeeId)
            ->where('c.is_active', 1)
            ->count();

        $logs = DB::table('visit_logs')
            ->where('employee_id', $employeeId)
            ->whereBetween('checkin_time', [$start->toDateString().' 00:00:00', $end->toDateString().' 23:59:59'])
            ->get(['customer_id', 'checkin_time']);

        $series = $mode === 'year'
            ? $this->monthBuckets($logs, $planCustomers)
            : $this->weekBuckets($logs, $planCustomers, $start);

        $employee = DB::table('auth_user')->where('id', $employeeId)->first(['id', 'real_name']);

        return $this->success([
            'employee_name' => $employee->real_name ?? '未知',
            'mode' => $mode,
            'plan_customers' => $planCustomers,
            'series' => $series,
        ]);
    }

    /** 按 12 个月切分：每月拜访次数 / 计划客户数 */
    private function monthBuckets($logs, int $planCustomers): array
    {
        $counts = array_fill(1, 12, 0);
        foreach ($logs as $log) {
            $month = (int) substr((string) $log->checkin_time, 5, 2);
            $counts[$month] = ($counts[$month] ?? 0) + 1;
        }

        $buckets = [];
        for ($m = 1; $m <= 12; $m++) {
            $buckets[] = [
                'label' => $m.'月',
                'value' => $counts[$m],
                'rate' => $planCustomers > 0 ? round($counts[$m] / $planCustomers * 100, 1) : 0,
            ];
        }

        return $buckets;
    }

    /** 按 5 个周段切分（1-7 / 8-14 / 15-21 / 22-28 / 29-月末） */
    private function weekBuckets($logs, int $planCustomers, $start): array
    {
        $counts = array_fill(1, 5, 0);
        foreach ($logs as $log) {
            $day = (int) substr((string) $log->checkin_time, 8, 2);
            $week = $day >= 29 ? 5 : intdiv($day - 1, 7) + 1;
            $counts[$week] = ($counts[$week] ?? 0) + 1;
        }

        $buckets = [];
        for ($w = 1; $w <= 5; $w++) {
            $buckets[] = [
                'label' => '第'.$w.'周',
                'value' => $counts[$w],
                'rate' => $planCustomers > 0 ? round($counts[$w] / $planCustomers * 100, 1) : 0,
            ];
        }

        return $buckets;
    }

    // =========================================================================
    // 模块九：业务员行程（拜访管理 → 业务员行程）
    // =========================================================================

    /** 行程明细（分页 + 汇总） */
    public function schedule(Request $request)
    {
        [$start, $end] = [
            $request->input('date_start') ?: now()->startOfMonth()->toDateString(),
            $request->input('date_end') ?: now()->toDateString(),
        ];

        $query = $this->scheduleQuery($request, $start, $end);
        $paginator = $query->paginate($request->integer('page_size', 30));

        return $this->success([
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'summary' => $this->scheduleSummary($request, $start, $end),
        ]);
    }

    /** 行程明细导出 */
    public function scheduleExport(Request $request)
    {
        [$start, $end] = [
            $request->input('date_start') ?: now()->startOfMonth()->toDateString(),
            $request->input('date_end') ?: now()->toDateString(),
        ];

        $rows = $this->scheduleQuery($request, $start, $end)->limit(50000)->get();

        $headers = ['拜访单号', '业务员编码', '业务员名称', '客户编码', '客户名称', '所属线路', '签到时间', '签退时间', '停留时长(分钟)', '签到地址', '拜访结果', '备注'];
        $body = [];
        foreach ($rows as $r) {
            $body[] = [
                $r->visit_no, $r->employee_code, $r->employee_name, $r->customer_code,
                $r->customer_name, $r->route_name, $r->checkin_time, $r->checkout_time,
                $r->visit_duration, $r->checkin_address, $r->visit_result, $r->remark,
            ];
        }

        $csv = "\u{FEFF}业务员行程\n\n".implode(',', $headers)."\n";
        foreach ($body as $row) {
            $csv .= implode(',', array_map(fn ($cell) => str_contains((string) $cell, ',') ? '"'.$cell.'"' : $cell, $row))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="visit_schedule.csv"',
        ]);
    }

    /**
     * 行程轨迹：某业务员某天按时间排序的拜访点
     *
     * 没有地图 SDK 也能画：把经纬度直接给前端，由前端归一化后画折线。
     * 缺失经纬度的点单独标出来（has_location=false），画轨迹时跳过，
     * 但列表里仍然保留——跳过的点也是行程的一部分。
     */
    public function trajectory(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'nullable|date',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $points = DB::table('visit_logs as vl')
            ->leftJoin('customers as c', 'c.id', '=', 'vl.customer_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'vl.employee_id')
            ->where('vl.employee_id', (int) $validated['employee_id'])
            ->whereDate('vl.checkin_time', $date)
            ->orderBy('vl.checkin_time')
            ->get([
                'vl.id', 'vl.visit_no', 'vl.checkin_time', 'vl.checkout_time',
                'vl.checkin_lat', 'vl.checkin_lng', 'vl.checkin_address',
                'vl.visit_duration', 'vl.visit_result', 'vl.remark',
                'c.name as customer_name', 'c.code as customer_code', 'c.address as customer_address',
                'u.real_name as employee_name',
            ])
            ->map(function ($p, $index) {
                $p->seq = $index + 1;
                $p->has_location = $p->checkin_lat !== null && $p->checkin_lng !== null;

                return $p;
            })->values();

        return $this->success([
            'date' => $date,
            'employee_name' => $points->first()->employee_name ?? '未知',
            'points' => $points,
            'located_count' => $points->where('has_location', true)->count(),
            'total_count' => $points->count(),
        ]);
    }

    private function scheduleQuery(Request $request, string $start, string $end)
    {
        $salesmanIds = $this->intArray($request->input('salesman_ids'));

        return DB::table('visit_logs as vl')
            ->leftJoin('customers as c', 'c.id', '=', 'vl.customer_id')
            ->leftJoin('routes as r', 'r.id', '=', 'vl.route_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'vl.employee_id')
            ->leftJoin('employees as e', 'e.user_id', '=', 'vl.employee_id')
            ->whereBetween('vl.checkin_time', [$start.' 00:00:00', $end.' 23:59:59'])
            ->when($salesmanIds, fn ($q) => $q->whereIn('vl.employee_id', $salesmanIds))
            ->select([
                'vl.id', 'vl.visit_no', 'vl.checkin_time', 'vl.checkout_time',
                'vl.visit_duration', 'vl.visit_result', 'vl.remark',
                // employee_id 必须回传：前端点「轨迹」要拿它去查 trajectory，
                // 只给 employee_code 的话得再反查一次用户表。
                'vl.employee_id',
                'vl.checkin_address', 'vl.checkin_lat', 'vl.checkin_lng', 'vl.checkin_photo',
                'c.code as customer_code', 'c.name as customer_name',
                'r.name as route_name',
                'u.real_name as employee_name',
                DB::raw('COALESCE(e.code, u.username) as employee_code'),
            ])
            ->orderByDesc('vl.checkin_time');
    }

    private function scheduleSummary(Request $request, string $start, string $end): array
    {
        $salesmanIds = $this->intArray($request->input('salesman_ids'));

        $rows = $this->scheduleQuery($request, $start, $end)->limit(50000)->get();

        return [
            'visit_count' => $rows->count(),
            'customer_count' => $rows->pluck('customer_code')->filter()->unique()->count(),
            'salesman_count' => $rows->pluck('employee_code')->filter()->unique()->count(),
            'total_duration' => (int) $rows->sum('visit_duration'),
            'avg_duration' => $rows->count() > 0 ? (int) round($rows->sum('visit_duration') / $rows->count()) : 0,
        ];
    }

    /** 多选参数统一收敛成整型数组 */
    private function intArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', (array) $value)));
    }
}
