<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $query = Vehicle::query();
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('plate_no', 'like', '%'.$request->keyword.'%')
                    ->orWhere('driver_name', 'like', '%'.$request->keyword.'%');
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
        $this->syncVehicleWarehouse($vehicle);

        return $this->created($vehicle, '创建成功');
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $validated = $request->validate([
            'plate_no' => 'required|string|max:20|unique:vehicles,plate_no,'.$vehicle->id,
            'driver_name' => 'required|string|max:100',
            'driver_phone' => 'nullable|string|max:50',
            'vehicle_type' => 'nullable|string|max:50',
            'load_capacity' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string',
        ]);
        $vehicle->update($validated);
        $this->syncVehicleWarehouse($vehicle);

        return $this->success($vehicle, '更新成功');
    }

    public function destroy(Vehicle $vehicle)
    {
        DB::transaction(function () use ($vehicle) {
            // 删车辆前先删其对应的车辆仓记录（type='vehicle'），保持引用一致
            DB::table('warehouses')->where('vehicle_id', $vehicle->id)->delete();
            $vehicle->delete();
        });

        return $this->success(null, '删除成功');
    }

    public function batchUpdateStatus(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'is_active' => 'required|boolean']);
        Vehicle::whereIn('id', $request->ids)->update(['is_active' => $request->is_active]);
        // 同步车辆仓的启用状态
        DB::table('warehouses')->where('type', 'vehicle')->whereIn('vehicle_id', $request->ids)
            ->update(['is_active' => (bool) $request->is_active]);

        return $this->success(null, '操作成功');
    }

    public function batchDelete(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        DB::transaction(function () use ($request) {
            DB::table('warehouses')->where('type', 'vehicle')->whereIn('vehicle_id', $request->ids)->delete();
            Vehicle::whereIn('id', $request->ids)->delete();
        });

        return $this->success(null, '删除成功');
    }

    /**
     * 车辆伪装成仓库：为每辆车维护一条 type='vehicle' 的 warehouse 记录，
     * 车销模块的车上库存即通过该 warehouse_id 复用 stocks + StockService。
     */
    private function syncVehicleWarehouse(Vehicle $vehicle): void
    {
        DB::table('warehouses')->updateOrInsert(
            ['vehicle_id' => $vehicle->id],
            [
                'code' => 'VH'.$vehicle->plate_no,
                'name' => '车辆-'.$vehicle->plate_no,
                'type' => 'vehicle',
                'is_active' => (bool) $vehicle->is_active,
                'updated_at' => now(),
            ]
        );
        // updateOrInsert 不带 created_at，单独补
        DB::table('warehouses')->where('vehicle_id', $vehicle->id)->whereNull('created_at')->update(['created_at' => now()]);
    }
}
