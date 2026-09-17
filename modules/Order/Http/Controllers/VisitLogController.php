<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
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

    public function achievement(Request $request)
    {
        $startDate = $request->input('date_start', date('Y-m-01'));
        $endDate = $request->input('date_end', date('Y-m-d'));

        // 获取所有员工
        $employees = User::where('status', 1)->get();
        $stats = [];

        foreach ($employees as $employee) {
            // 该员工已拜访的客户数
            $visitedCustomers = VisitLog::where('employee_id', $employee->id)
                ->whereBetween('checkin_time', [$startDate, $endDate])
                ->where('status', 2)
                ->distinct('customer_id')
                ->count('customer_id');

            $stats[] = [
                'employee_id' => $employee->id,
                'employee_name' => $employee->real_name,
                'total_customers' => 0,
                'visited_customers' => $visitedCustomers,
                'achievement' => 0,
            ];
        }

        return $this->success([
            'period' => [$startDate, $endDate],
            'stats' => $stats,
        ]);
    }

    public function schedule(Request $request)
    {
        $date = $request->input('date', date('Y-m-d'));

        $visits = VisitLog::with(['employee', 'customer'])
            ->whereDate('checkin_time', $date)
            ->orderBy('checkin_time')
            ->get();

        // 按员工分组
        $schedule = [];
        foreach ($visits as $visit) {
            $empId = $visit->employee_id;
            if (! isset($schedule[$empId])) {
                $schedule[$empId] = [
                    'employee_id' => $empId,
                    'employee_name' => $visit->employee?->real_name ?? '未知',
                    'visits' => [],
                ];
            }
            $schedule[$empId]['visits'][] = [
                'id' => $visit->id,
                'customer_id' => $visit->customer_id,
                'customer_name' => $visit->customer?->name ?? '未知',
                'checkin_time' => $visit->checkin_time,
                'checkout_time' => $visit->checkout_time,
                'visit_duration' => $visit->visit_duration,
                'visit_result' => $visit->visit_result,
                'address' => $visit->checkin_address,
            ];
        }

        return $this->success(array_values($schedule));
    }
}
