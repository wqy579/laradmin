<?php

namespace App\Http\Controllers\Admin\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->keyword . '%')
                  ->orWhere('code', 'like', '%' . $request->keyword . '%');
            });
        }
        $query->orderBy('id', 'desc');
        $suppliers = $query->paginate($request->integer('page_size', 20));
        return $this->paginated($suppliers);
    }

    public function show(Supplier $supplier)
    {
        return $this->success($supplier);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'code' => 'nullable|string|max:50',
            'contact' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            'balance' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
        ]);
        $supplier = Supplier::create($validated);
        return $this->created($supplier, '创建成功');
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'code' => 'nullable|string|max:50',
            'contact' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            'balance' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
        ]);
        $supplier->update($validated);
        return $this->success($supplier, '更新成功');
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return $this->success(null, '删除成功');
    }

    public function batchUpdateStatus(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'is_active' => 'required|boolean']);
        Supplier::whereIn('id', $request->ids)->update(['is_active' => $request->is_active]);
        return $this->success(null, '操作成功');
    }

    public function batchDelete(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Supplier::whereIn('id', $request->ids)->delete();
        return $this->success(null, '删除成功');
    }
}
