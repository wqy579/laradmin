<?php

namespace App\Http\Controllers\Admin\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $query = Vehicle::query();
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('plate_no', 'like', '%' . $request->keyword . '%')
                  ->orWhere('driver_name', 'like', '%' . $request->keyword . '%');
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }
        $query->orderBy('id', 'desc');
        $vehicles = $query->paginate($request->integer('page_size', 20));
        return $this->paginated($vehicles);
    }

    public function show(Vehicle $vehicle)
    {
        return $this->success($vehicle);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'plate_no' => 'required|string|max:20|unique:vehicles,plate_no',
            'driver_name' => 'required|string|max:100',
            'driver_phone' => 'nullable|string|max:50',
            'vehicle_type' => 'nullable|string|max:50',
            'load_capacity' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
        ]);
        $vehicle = Vehicle::create($validated);
        return $this->created($vehicle, '创建成功');
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $validated = $request->validate([
            'plate_no' => 'required|string|max:20|unique:vehicles,plate_no,' . $vehicle->id,
            'driver_name' => 'required|string|max:100',
            'driver_phone' => 'nullable|string|max:50',
            'vehicle_type' => 'nullable|string|max:50',
            'load_capacity' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
        ]);
        $vehicle->update($validated);
        return $this->success($vehicle, '更新成功');
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();
        return $this->success(null, '删除成功');
    }

    public function batchUpdateStatus(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'is_active' => 'required|boolean']);
        Vehicle::whereIn('id', $request->ids)->update(['is_active' => $request->is_active]);
        return $this->success(null, '操作成功');
    }

    public function batchDelete(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Vehicle::whereIn('id', $request->ids)->delete();
        return $this->success(null, '删除成功');
    }
}
