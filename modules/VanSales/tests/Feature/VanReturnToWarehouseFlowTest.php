<?php

namespace Tests\VanSales\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

/**
 * 车上退仓端到端流程测试
 *
 * 验证核心不变量：
 * ① store 校验车上可用库存（quantity - frozen_qty），超额拒绝且不动库存；
 * ② approve 后车上仓 stockOut + 目的普通仓 stockIn，两边数量守恒；
 * ③ 状态机：draft→pending→approved 不可逆，approved 后不能再 approve。
 */
class VanReturnToWarehouseFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_rejects_when_return_qty_exceeds_vehicle_stock(): void
    {
        $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();

        // 车上仓只有 3 件可用
        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $vehicleWh->id,
            'quantity' => 3, 'frozen_qty' => 0,
        ]);

        $response = $this->postJson('/admin/business/van-return-to-warehouse', [
            'vehicle_id' => $vehicle,
            'vehicle_warehouse_id' => $vehicleWh->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'return_qty' => 5],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(3, (int) Stock::where('warehouse_id', $vehicleWh->id)->value('quantity'), '拒绝后车上库存必须不变');
        $this->assertSame(0, DB::table('van_return_to_warehouse')->count(), '超额时不应创建退仓单');
    }

    public function test_approve_moves_stock_from_vehicle_to_warehouse(): void
    {
        $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();

        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $vehicleWh->id,
            'quantity' => 10, 'frozen_qty' => 0, 'cost_price' => 5.00,
        ]);

        // 创建退仓单（草稿）
        $storeRes = $this->postJson('/admin/business/van-return-to-warehouse', [
            'vehicle_id' => $vehicle,
            'vehicle_warehouse_id' => $vehicleWh->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'return_qty' => 4],
            ],
        ]);
        $storeRes->assertStatus(200);
        $id = $storeRes->json('data.id');

        // 草稿态不动库存
        $this->assertSame(10, (int) Stock::where('warehouse_id', $vehicleWh->id)->value('quantity'));
        $this->assertSame(0, (int) Stock::where('warehouse_id', $warehouse->id)->value('quantity') ?? 0, '草稿态目的仓不应有库存');

        // 提交→审核
        $this->postJson("/admin/business/van-return-to-warehouse/{$id}/submit")->assertStatus(200);
        $approveRes = $this->postJson("/admin/business/van-return-to-warehouse/{$id}/approve");
        $approveRes->assertStatus(200);

        // 核心不变量：车上仓减 4、目的仓加 4，总量守恒
        $vehicleQty = (int) Stock::where('warehouse_id', $vehicleWh->id)->value('quantity');
        $warehouseQty = (int) Stock::where('warehouse_id', $warehouse->id)->value('quantity');
        $this->assertSame(6, $vehicleQty, '车上仓应从 10 减到 6');
        $this->assertSame(4, $warehouseQty, '目的仓应从 0 加到 4');
        $this->assertSame(10, $vehicleQty + $warehouseQty, '库存总量守恒');

        // 状态 = approved
        $this->assertSame('approved', DB::table('van_return_to_warehouse')->where('id', $id)->value('status'));
    }

    public function test_approve_is_idempotent_and_rejects_non_pending(): void
    {
        $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();

        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $vehicleWh->id,
            'quantity' => 10, 'frozen_qty' => 0,
        ]);

        $storeRes = $this->postJson('/admin/business/van-return-to-warehouse', [
            'vehicle_id' => $vehicle,
            'vehicle_warehouse_id' => $vehicleWh->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'return_qty' => 3]],
        ]);
        $id = $storeRes->json('data.id');

        $this->postJson("/admin/business/van-return-to-warehouse/{$id}/submit")->assertStatus(200);
        $this->postJson("/admin/business/van-return-to-warehouse/{$id}/approve")->assertStatus(200);

        // 重复审核返回 200（幂等），不再二次动库存
        $this->postJson("/admin/business/van-return-to-warehouse/{$id}/approve")->assertStatus(200);
        $this->assertSame(7, (int) Stock::where('warehouse_id', $vehicleWh->id)->value('quantity'), '幂等审核不应二次扣库存');

        // 草稿态不能直接审核
        $storeRes2 = $this->postJson('/admin/business/van-return-to-warehouse', [
            'vehicle_id' => $vehicle,
            'vehicle_warehouse_id' => $vehicleWh->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'return_qty' => 1]],
        ]);
        $id2 = $storeRes2->json('data.id');
        $this->postJson("/admin/business/van-return-to-warehouse/{$id2}/approve")->assertStatus(422);
    }

    public function test_store_rejects_same_source_and_destination(): void
    {
        $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $product = $this->makeProduct();
        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $vehicleWh->id,
            'quantity' => 10, 'frozen_qty' => 0,
        ]);

        $response = $this->postJson('/admin/business/van-return-to-warehouse', [
            'vehicle_id' => $vehicle,
            'vehicle_warehouse_id' => $vehicleWh->id,
            'warehouse_id' => $vehicleWh->id, // 同一仓
            'items' => [['product_id' => $product->id, 'return_qty' => 1]],
        ]);

        $response->assertStatus(422);
        $this->assertSame('车上仓与目的仓库不能相同', $response->json('message'));
    }

    /**
     * 建一辆车 + 回填出它的车辆仓（type=vehicle + vehicle_id）。
     * vehicles.driver_name NOT NULL，必须带。
     */
    private function makeVehicleWarehouse(): array
    {
        $plate = '京A'.uniqid();
        $vehicleId = DB::table('vehicles')->insertGetId([
            'plate_no' => $plate,
            'driver_name' => '测试司机',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 复刻迁移回填：为该车辆建一条 type=vehicle 的仓库
        $warehouseId = DB::table('warehouses')->insertGetId([
            'code' => 'VW'.uniqid(),
            'name' => '车辆仓_'.$plate,
            'type' => 'vehicle',
            'vehicle_id' => $vehicleId,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$vehicleId, Warehouse::find($warehouseId)];
    }
}
