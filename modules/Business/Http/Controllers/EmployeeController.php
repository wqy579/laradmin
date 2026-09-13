<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Business\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::query();

        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->keyword . '%')
                  ->orWhere('code', 'like', '%' . $request->keyword . '%')
                  ->orWhere('phone', 'like', '%' . $request->keyword . '%');
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }

        $query->orderBy('id', 'desc');
        $employees = $query->paginate($request->integer('page_size', 20));
        return $this->paginated($employees);
    }

    public function show(Employee $employee)
    {
        return $this->success($employee);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:100',
            'gender' => 'nullable|string|max:10',
            'position' => 'nullable|string|max:50',
            'salesman_code' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:50',
            'id_card' => 'nullable|string|max:20',
            'hire_date' => 'nullable|date',
            'base_salary' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'role' => 'nullable|string|in:staff,salesman,manager,admin',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
        ]);
        $employee = Employee::create($validated);
        return $this->created($employee, '创建成功');
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:100',
            'gender' => 'nullable|string|max:10',
            'position' => 'nullable|string|max:50',
            'salesman_code' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:50',
            'id_card' => 'nullable|string|max:20',
            'hire_date' => 'nullable|date',
            'base_salary' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'role' => 'nullable|string|in:staff,salesman,manager,admin',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
        ]);
        $employee->update($validated);
        return $this->success($employee, '更新成功');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return $this->success(null, '删除成功');
    }

    public function batchUpdateStatus(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'is_active' => 'required|boolean']);
        Employee::whereIn('id', $request->ids)->update(['is_active' => $request->is_active]);
        return $this->success(null, '操作成功');
    }

    public function batchDelete(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Employee::whereIn('id', $request->ids)->delete();
        return $this->success(null, '删除成功');
    }
}
