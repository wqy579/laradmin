<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Order\Models\Route;
use Modules\Order\Models\Customer;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    public function index(Request $request)
    {
        $query = Route::with(['employee', 'customers']);
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->keyword . '%')
                  ->orWhere('code', 'like', '%' . $request->keyword . '%');
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }
        $query->orderBy('sort_order');
        $routes = $query->paginate($request->integer('page_size', 20));
        return $this->paginated($routes);
    }

    public function show(Route $route)
    {
        $route->load('employee', 'customers');
        return $this->success($route);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:30|unique:routes,code',
            'area' => 'nullable|string|max:100',
            'employee_id' => 'nullable|exists:auth_user,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
            'customer_ids' => 'nullable|array',
            'customer_ids.*' => 'exists:customers,id',
        ]);
        $route = Route::create($validated);
        if ($request->filled('customer_ids')) {
            $customerIds = collect($validated['customer_ids'])->map(fn($id) => [
                'customer_id' => $id,
                'visit_order' => 0,
                'visit_frequency' => 1,
            ])->toArray();
            $route->customers()->sync($customerIds);
        }
        return $this->created($route, '创建成功');
    }

    public function update(Request $request, Route $route)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:30|unique:routes,code,' . $route->id,
            'area' => 'nullable|string|max:100',
            'employee_id' => 'nullable|exists:auth_user,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
            'customer_ids' => 'nullable|array',
            'customer_ids.*' => 'exists:customers,id',
        ]);
        $route->update($validated);
        if ($request->filled('customer_ids')) {
            $customerIds = collect($validated['customer_ids'])->map(fn($id) => [
                'customer_id' => $id,
                'visit_order' => 0,
                'visit_frequency' => 1,
            ])->toArray();
            $route->customers()->sync($customerIds);
        }
        return $this->success($route, '更新成功');
    }

    public function destroy(Route $route)
    {
        $route->delete();
        return $this->success(null, '删除成功');
    }

    public function batchUpdateStatus(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'is_active' => 'required|boolean']);
        Route::whereIn('id', $request->ids)->update(['is_active' => $request->is_active]);
        return $this->success(null, '操作成功');
    }

    public function batchDelete(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Route::whereIn('id', $request->ids)->delete();
        return $this->success(null, '删除成功');
    }

    public function customers(Route $route, Request $request)
    {
        $customers = Customer::where('is_active', true)
            ->whereNotIn('id', $route->customers()->pluck('customer_id'))
            ->paginate($request->integer('page_size', 20));
        return $this->paginated($customers);
    }

    public function addCustomer(Request $request, Route $route)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'visit_order' => 'nullable|integer',
            'visit_frequency' => 'nullable|integer',
        ]);
        $route->customers()->attach($request->customer_id, [
            'visit_order' => $request->visit_order ?? 0,
            'visit_frequency' => $request->visit_frequency ?? 1,
        ]);
        return $this->success(null, '添加成功');
    }

    public function removeCustomer(Request $request, Route $route)
    {
        $request->validate(['customer_id' => 'required|exists:customers,id']);
        $route->customers()->detach($request->customer_id);
        return $this->success(null, '移除成功');
    }
}
