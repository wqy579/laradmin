<?php

namespace App\Http\Controllers\Admin\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $query = Warehouse::query();
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->keyword . '%')
                  ->orWhere('code', 'like', '%' . $request->keyword . '%');
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }
        $query->orderBy('id');
        $warehouses = $query->paginate($request->integer('page_size', 20));
        return $this->paginated($warehouses);
    }

    public function show(Warehouse $warehouse)
    {
        return $this->success($warehouse);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:20|unique:warehouses,code',
            'address' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'nullable|boolean',
        ]);
        if (empty($validated['code'])) {
            $validated['code'] = $this->generateCode();
        }
        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);
        $warehouse = Warehouse::create($validated);
        return $this->created($warehouse, '创建成功');
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:warehouses,code,' . $warehouse->id,
            'address' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'nullable|boolean',
        ]);
        $warehouse->update($validated);
        return $this->success($warehouse, '更新成功');
    }

    public function destroy(Warehouse $warehouse)
    {
        $warehouse->delete();
        return $this->success(null, '删除成功');
    }

    private function generateCode(): string
    {
        $n = Warehouse::count() + 1;
        do {
            $code = 'WH' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $n++;
        } while (Warehouse::where('code', $code)->exists());
        return $code;
    }
}
