<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Order\Models\Customer;
use Modules\Order\Models\Route;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::with('belongRoute');
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->keyword.'%')
                    ->orWhere('code', 'like', '%'.$request->keyword.'%')
                    ->orWhere('phone', 'like', '%'.$request->keyword.'%');
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }
        if ($request->filled('route_id')) {
            $query->where('route_id', $request->route_id);
        }
        $query->orderBy('id', 'desc');
        $customers = $query->paginate($request->integer('page_size', 20));

        return $this->paginated($customers);
    }

    public function show(Customer $customer)
    {
        $customer->load('belongRoute');

        return $this->success($customer);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'code' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'contact' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'route' => 'nullable|string|max:100',
            'route_id' => 'nullable|exists:routes,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'balance' => 'nullable|numeric|min:0',
            'level' => 'nullable|integer|min:1|max:10',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|string|max:500',
            'remark' => 'nullable|string',
        ]);
        $this->syncRouteLabel($validated);
        $customer = Customer::create($validated);

        return $this->created($customer, '创建成功');
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'code' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'contact' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'route' => 'nullable|string|max:100',
            'route_id' => 'nullable|exists:routes,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'balance' => 'nullable|numeric|min:0',
            'level' => 'nullable|integer|min:1|max:10',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|string|max:500',
            'remark' => 'nullable|string',
        ]);
        $this->syncRouteLabel($validated);
        $customer->update($validated);

        return $this->success($customer, '更新成功');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return $this->success(null, '删除成功');
    }

    public function batchUpdateStatus(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'is_active' => 'required|boolean']);
        Customer::whereIn('id', $request->ids)->update(['is_active' => $request->is_active]);

        return $this->success(null, '操作成功');
    }

    public function statistics()
    {
        $stats = [
            'total' => Customer::count(),
            'active' => Customer::where('is_active', true)->count(),
            'total_balance' => Customer::sum('balance') ?? 0,
        ];

        return $this->success($stats);
    }

    public function batchDelete(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Customer::whereIn('id', $request->ids)->delete();

        return $this->success(null, '删除成功');
    }

    private function syncRouteLabel(array &$validated): void
    {
        if (! array_key_exists('route_id', $validated)) {
            return;
        }
        if (empty($validated['route_id'])) {
            return;
        }
        $name = Route::where('id', $validated['route_id'])->value('name');
        if ($name) {
            $validated['route'] = $name;
        }
    }
}
