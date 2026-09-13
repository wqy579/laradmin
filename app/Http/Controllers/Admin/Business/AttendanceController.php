<?php

namespace App\Http\Controllers\Admin\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Attendance;
use App\Models\Business\Employee;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = Attendance::with(['employee']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('start_date')) {
            $query->where('date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('date', '<=', $request->end_date);
        }

        $query->orderBy('date', 'desc');
        $attendances = $query->paginate($request->integer('page_size', 20));

        return $this->paginated($attendances);
    }

    public function show(Attendance $attendance)
    {
        $attendance->load('employee');
        return $this->success($attendance);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'check_in' => 'nullable|date_format:H:i',
            'check_out' => 'nullable|date_format:H:i',
            'status' => 'nullable|integer|in:0,1,2,3',
            'remark' => 'nullable|string',
        ]);

        $attendance = Attendance::create($validated);
        return $this->created($attendance);
    }

    public function update(Request $request, Attendance $attendance)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'check_in' => 'nullable|date_format:H:i',
            'check_out' => 'nullable|date_format:H:i',
            'status' => 'nullable|integer|in:0,1,2,3',
            'remark' => 'nullable|string',
        ]);

        $attendance->update($validated);
        return $this->success($attendance);
    }

    public function destroy(Attendance $attendance)
    {
        $attendance->delete();
        return $this->noContent();
    }

    public function statistics(Request $request)
    {
        $employeeId = $request->input('employee_id');
        $month = $request->input('month', date('Y-m'));

        $stats = Attendance::where('date', 'like', $month . '%')
            ->when($employeeId, function ($q) use ($employeeId) {
                $q->where('employee_id', $employeeId);
            })
            ->selectRaw('
                employee_id,
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as normal_days,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as absent_days
            ')
            ->groupBy('employee_id')
            ->get();

        return $this->success($stats);
    }
}
