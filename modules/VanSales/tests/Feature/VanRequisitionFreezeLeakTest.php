<?php

namespace Tests\VanSales\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Modules\VanSales\Models\VanRequisition;
use Tests\TestCase;

/**
 * 要货单冻结泄漏修复测试（P0）
 *
 * 缺陷：approve 冻结源仓库存后，唯一解冻路径是拣货单 approve；要货单审核后
 * 若不拣货，冻结量永久悬挂（可用库存虚减）。cancel 原本只允许 draft/pending，
 * approved 无法取消 → 冻结泄漏。
 *
 * 修复：cancel 放开 approved，取消时按 apply_qty 逐项 unfreeze 释放冻结。
 */
class VanRequisitionFreezeLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_approved_requisition_releases_frozen_stock(): void
    {
        $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();

        // 源仓 10 件可用
        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'quantity' => 10, 'frozen_qty' => 0,
        ]);

        // 建单 → 提交 → 审核（冻结 4 件）
        $storeRes = $this->postJson('/admin/business/van-requisition', [
            'vehicle_id' => $vehicle,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'apply_qty' => 4],
            ],
        ]);
        $storeRes->assertStatus(200);
        $id = $storeRes->json('data.id');

        $this->postJson("/admin/business/van-requisition/{$id}/submit")->assertStatus(200);
        $this->postJson("/admin/business/van-requisition/{$id}/approve")->assertStatus(200);

        $stock = Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame(4, (int) $stock->frozen_qty, '审核后应冻结 4 件');
        $this->assertSame(10, (int) $stock->quantity, '冻结不动总库存');

        // 取消已审核要货单：应成功且冻结清零（修复核心）
        $cancelRes = $this->postJson("/admin/business/van-requisition/{$id}/cancel");
        $cancelRes->assertStatus(200);

        $stock->refresh();
        $this->assertSame(0, (int) $stock->frozen_qty, '取消后冻结必须清零');
        $this->assertSame(10, (int) $stock->quantity, '取消后总库存不变');
        $this->assertSame('cancelled', $this->getRequisitionStatus($id), '状态应变为 cancelled');
    }

    public function test_cancel_pending_requisition_still_works_and_keeps_stock_untouched(): void
    {
        $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();

        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'quantity' => 10, 'frozen_qty' => 0,
        ]);

        $storeRes = $this->postJson('/admin/business/van-requisition', [
            'vehicle_id' => $vehicle,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'apply_qty' => 4],
            ],
        ]);
        $storeRes->assertStatus(200);
        $id = $storeRes->json('data.id');

        $this->postJson("/admin/business/van-requisition/{$id}/submit")->assertStatus(200);

        // pending 取消：原有行为不变
        $cancelRes = $this->postJson("/admin/business/van-requisition/{$id}/cancel");
        $cancelRes->assertStatus(200);

        $stock = Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame(0, (int) $stock->frozen_qty, 'pending 未冻结，取消不动冻结');
        $this->assertSame(10, (int) $stock->quantity);
    }

    public function test_cancel_picked_requisition_is_rejected(): void
    {
        $this->actingAsAdmin();
        [$vehicle, $vehicleWh] = $this->makeVehicleWarehouse();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();

        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'quantity' => 10, 'frozen_qty' => 0,
        ]);

        $storeRes = $this->postJson('/admin/business/van-requisition', [
            'vehicle_id' => $vehicle,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'apply_qty' => 4],
            ],
        ]);
        $id = $storeRes->json('data.id');
        $this->postJson("/admin/business/van-requisition/{$id}/submit")->assertStatus(200);
        $this->postJson("/admin/business/van-requisition/{$id}/approve")->assertStatus(200);

        // 直接置为 picked 终态（拣货流程由 VanPicking 负责，本测试只验证状态门禁）
        VanRequisition::where('id', $id)->update(['status' => 'picked']);

        $cancelRes = $this->postJson("/admin/business/van-requisition/{$id}/cancel");
        $cancelRes->assertStatus(422);
    }

    private function getRequisitionStatus(int $id): string
    {
        return (string) VanRequisition::where('id', $id)->value('status');
    }

    /** 建一辆车 + 回填车辆仓（type=vehicle + vehicle_id），复刻 VanReturnToWarehouseFlowTest */
    private function makeVehicleWarehouse(): array
    {
        $plate = '京A'.uniqid();
        $vehicleId = DB::table('vehicles')->insertGetId([
            'plate_no' => $plate,
            'driver_name' => '测试司机',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
