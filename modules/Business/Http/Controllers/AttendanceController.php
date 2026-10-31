<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Business\Models\Attendance;

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
            $query->whereDate('date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('date', '<=', $request->end_date);
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

    /**
     * 考勤列表导出
     *
     * 筛选条件与 index() 逐一对齐（员工/日期区间），导出结果 = 列表所见。
     * date 列有 'date' cast，落库带 00:00:00，sqlite 下裸字符串比较会把上界当天
     * 判成 '2026-10-15 00:00:00' > '2026-10-15' 而丢当天——故两侧统一 whereDate。
     */
    public function export(Request $request)
    {
        $query = Attendance::with(['employee']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('date', '<=', $request->end_date);
        }

        $rows = $query->orderBy('date', 'desc')->limit(50000)->get();

        $statusLabels = [0 => '未知', 1 => '正常', 2 => '迟到', 3 => '缺勤'];

        $csv = '﻿';
        $csv .= "考勤记录\n\n";
        $csv .= "员工编码,员工姓名,日期,上班时间,下班时间,状态,拜访数,备注\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [
                $this->csvCell($r->employee?->code),
                $this->csvCell($r->employee?->name),
                $this->csvCell($r->date?->toDateString()),
                $this->csvCell($r->clock_in),
                $this->csvCell($r->clock_out),
                $this->csvCell($statusLabels[$r->status] ?? '未知'),
                $this->csvCell($r->visit_count),
                $this->csvCell($r->remark),
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="attendance.csv"',
        ]);
    }

    /** CSV 单元格：含逗号/引号/换行的加引号转义 */
    private function csvCell($value): string
    {
        $value = (string) ($value ?? '');
        if (preg_match('/[",\r\n]/', $value)) {
            $value = '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
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

        $stats = Attendance::where('date', 'like', $month.'%')
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
