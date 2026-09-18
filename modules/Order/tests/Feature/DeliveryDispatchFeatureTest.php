<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * 发货出库：发货单的 dispatch 必须按明细扣减库存
 *
 * 不变量 I2：任何情况下不允许把库存扣成负数。
 * 发货状态机：0(待发货) --dispatch--> 1(已发货) --complete--> 2(完成)
 *
 * 此前 dispatch 只翻状态不扣库存，销售链路"只进不出"：采购入库能涨库存，
 * 卖出去却永远不减，账面库存持续虚高。扣减发生在 dispatch（货物离仓时点），
 * 与采购侧「receive 即入库」对称；complete 只做签收确认，不再动库存。
 */
class DeliveryDispatchFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->productId = $this->makeProduct()->id;
    }

    /** 建一张发货单，返回单据 id */
    private function createDelivery(int $warehouseId, ?array $items = null): int
    {
        $customer = $this->makeCustomer();

        $response = $this->postJson('/admin/business/delivery', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouseId,
            'delivery_date' => now()->toDateString(),
            'items' => $items ?? [['product_id' => $this->productId, 'quantity' => 3]],
        ]);

        $response->assertOk()->assertJsonPath('message', '创建成功');

        return $response->json('data.id');
    }

    private function stockIn(int $warehouseId, int $quantity): void
    {
        Stock::create([
            'product_id' => $this->productId,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
        ]);
    }

    private function stockOf(int $warehouseId): int
    {
        return (int) Stock::where('product_id', $this->productId)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity');
    }

    public function test_dispatch_deducts_stock_per_item_line(): void
    {
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 10);
        $id = $this->createDelivery($warehouse->id, [
            ['product_id' => $this->productId, 'quantity' => 3],
        ]);

        $this->postJson("/admin/business/delivery/{$id}/dispatch")
            ->assertOk()
            ->assertJsonPath('message', '发货成功');

        $this->assertSame(
            7,
            $this->stockOf($warehouse->id),
            '发货 3 件后库存应为 10 - 3'
        );
        $this->assertSame(1, \DB::table('deliveries')->find($id)->status);
    }

    public function test_dispatch_rejects_when_stock_insufficient(): void
    {
        // 不变量 I2：库存不足必须整体回滚，不允许扣成负数
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 2);
        $id = $this->createDelivery($warehouse->id, [
            ['product_id' => $this->productId, 'quantity' => 3],
        ]);

        $this->postJson("/admin/business/delivery/{$id}/dispatch")
            ->assertStatus(422)
            ->assertJsonPath('message', '库存不足');

        $this->assertSame(
            2,
            $this->stockOf($warehouse->id),
            '库存不足时不得改动库存（更不能扣成负数）'
        );
        $this->assertSame(
            0,
            (int) \DB::table('deliveries')->find($id)->status,
            '库存不足时单据应停留在待发货'
        );
    }

    public function test_dispatch_rejects_when_no_stock_row_exists(): void
    {
        // 仓库根本没有这条库存记录时同样要拦住，不能静默跳过然后标记已发货
        $warehouse = $this->makeWarehouse();
        $id = $this->createDelivery($warehouse->id);

        $this->postJson("/admin/business/delivery/{$id}/dispatch")
            ->assertStatus(422)
            ->assertJsonPath('message', '库存不足');

        $this->assertSame(0, (int) \DB::table('deliveries')->find($id)->status);
    }

    public function test_dispatch_rolls_back_every_item_when_a_later_item_is_short(): void
    {
        // 多明细时后一条不足，前面已经扣掉的明细也必须整体回滚
        $secondProduct = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 5);
        Stock::create([
            'product_id' => $secondProduct->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);

        $id = $this->createDelivery($warehouse->id, [
            ['product_id' => $this->productId, 'quantity' => 4],
            ['product_id' => $secondProduct->id, 'quantity' => 5],
        ]);

        $this->postJson("/admin/business/delivery/{$id}/dispatch")->assertStatus(422);

        $this->assertSame(
            5,
            $this->stockOf($warehouse->id),
            '第一条明细的扣减必须随整体回滚撤回'
        );
        $this->assertSame(
            1,
            (int) Stock::where('product_id', $secondProduct->id)
                ->where('warehouse_id', $warehouse->id)
                ->value('quantity')
        );
        $this->assertSame(0, (int) \DB::table('deliveries')->find($id)->status);
    }

    public function test_dispatch_only_deducts_own_warehouse(): void
    {
        // 只扣发货单所属仓库，不动其他仓库的库存
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 10);

        $other = $this->makeWarehouse();
        $this->stockIn($other->id, 10);

        $id = $this->createDelivery($warehouse->id);
        $this->postJson("/admin/business/delivery/{$id}/dispatch")->assertOk();

        $this->assertSame(7, $this->stockOf($warehouse->id));
        $this->assertSame(
            10,
            (int) Stock::where('product_id', $this->productId)
                ->where('warehouse_id', $other->id)
                ->value('quantity'),
            '发货不应影响其他仓库的库存'
        );
    }

    public function test_dispatch_is_once_only(): void
    {
        // 已发货不能重复发货（否则会把库存扣两次）
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 10);
        $id = $this->createDelivery($warehouse->id);
        $this->postJson("/admin/business/delivery/{$id}/dispatch")->assertOk();

        $this->postJson("/admin/business/delivery/{$id}/dispatch")
            ->assertStatus(400)
            ->assertJsonPath('message', '只有待发货状态的单据才能发货');

        $this->assertSame(
            7,
            $this->stockOf($warehouse->id),
            '重复发货不得二次扣减库存'
        );
    }

    public function test_complete_does_not_deduct_again(): void
    {
        // complete 只做签收确认，库存已在 dispatch 扣过，不能再次扣减
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 10);
        $id = $this->createDelivery($warehouse->id);
        $this->postJson("/admin/business/delivery/{$id}/dispatch")->assertOk();
        $this->postJson("/admin/business/delivery/{$id}/complete")->assertOk();

        $this->assertSame(7, $this->stockOf($warehouse->id), '完成环节不得重复扣库存');
        $this->assertSame(2, (int) \DB::table('deliveries')->find($id)->status);
    }
}
