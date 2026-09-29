<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * 退货单状态机与库存不足保护
 *
 * 退货单：draft --approve--> approved --process--> completed
 *
 * 不变量 I2：任何情况下不允许把库存扣成负数。
 * 退货的 process 此前是无条件 decrement（没有充足性校验、没有 lockForUpdate），
 * 可以把任意商品退成负库存——调拨 TransferController::execute 早已有同一套保护，
 * 退货这条口子漏了。approve 也缺少前置状态校验。
 */
class ReturnOrderFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->actingAsAdmin()->id;
        $this->productId = $this->makeProduct()->id;
    }

    /** 建一张退货单，返回单据 id */
    private function createReturn(int $warehouseId, ?array $items = null): int
    {
        $customer = $this->makeCustomer();

        $response = $this->postJson('/admin/business/return', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouseId,
            'return_date' => now()->toDateString(),
            'items' => $items ?? [['product_id' => $this->productId, 'quantity' => 3, 'price' => 2]],
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

    public function test_return_store_creates_draft_with_totals(): void
    {
        $warehouse = $this->makeWarehouse();
        $id = $this->createReturn($warehouse->id, [
            ['product_id' => $this->productId, 'quantity' => 4, 'price' => 2.5],
            ['product_id' => $this->productId, 'quantity' => 2, 'price' => 1],
        ]);

        $this->assertSame('draft', \DB::table('returns')->find($id)->status);
        $this->assertSame(6, (int) \DB::table('returns')->find($id)->total_qty);
        $this->assertSame(12.0, (float) \DB::table('returns')->find($id)->total_amount);
    }

    public function test_return_approve_only_in_draft(): void
    {
        $warehouse = $this->makeWarehouse();
        $id = $this->createReturn($warehouse->id);

        $this->postJson("/admin/business/return/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('message', '审批成功');

        $row = \DB::table('returns')->find($id);
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->adminId, $row->approved_by, 'approved_by 应记录审批人');
        $this->assertNotNull($row->approved_at);

        // 已审批/已完成的单据都不能重复审批
        $this->postJson("/admin/business/return/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态的退货单可以审批');

        $this->stockIn($warehouse->id, 10);
        $this->postJson("/admin/business/return/{$id}/process")->assertOk();
        $this->postJson("/admin/business/return/{$id}/approve")
            ->assertStatus(422);
        $this->assertSame('completed', \DB::table('returns')->find($id)->status);
    }

    public function test_return_process_rejects_when_stock_insufficient(): void
    {
        // 不变量 I2：库存不足时必须整体回滚，不允许把库存扣成负数
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 2);
        $id = $this->createReturn($warehouse->id, [
            ['product_id' => $this->productId, 'quantity' => 3, 'price' => 2],
        ]);
        $this->postJson("/admin/business/return/{$id}/approve")->assertOk();

        $this->postJson("/admin/business/return/{$id}/process")
            ->assertStatus(422)
            ->assertJsonPath('message', '库存不足：该仓库现有 2 件，无法退货 3 件');

        $this->assertSame(
            2,
            (int) Stock::where('product_id', $this->productId)->where('warehouse_id', $warehouse->id)->value('quantity'),
            '库存不足时不得改动库存（更不能扣成负数）'
        );
        $this->assertSame('approved', \DB::table('returns')->find($id)->status, '库存不足时单据应停留在已审批');
    }

    public function test_return_process_rejects_when_no_stock_row_exists(): void
    {
        // 仓库根本没有这条库存记录时同样要拦住，不能静默跳过然后标记完成
        $warehouse = $this->makeWarehouse();
        $id = $this->createReturn($warehouse->id);
        $this->postJson("/admin/business/return/{$id}/approve")->assertOk();

        $this->postJson("/admin/business/return/{$id}/process")
            ->assertStatus(422)
            ->assertJsonPath('message', '库存不足：该仓库现有 0 件，无法退货 3 件');

        $this->assertSame('approved', \DB::table('returns')->find($id)->status);
    }

    public function test_return_process_decrements_stock_and_marks_completed(): void
    {
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 10);
        $id = $this->createReturn($warehouse->id);
        $this->postJson("/admin/business/return/{$id}/approve")->assertOk();

        $this->postJson("/admin/business/return/{$id}/process")
            ->assertOk()
            ->assertJsonPath('message', '退货处理成功');

        $this->assertSame(
            7,
            (int) Stock::where('product_id', $this->productId)->where('warehouse_id', $warehouse->id)->value('quantity'),
            '退货 3 件后库存应为 10 - 3'
        );
        $this->assertSame('completed', \DB::table('returns')->find($id)->status);

        // 只扣退货单所属仓库
        $other = $this->makeWarehouse();
        $this->stockIn($other->id, 10);
        $this->assertSame(
            10,
            (int) Stock::where('product_id', $this->productId)->where('warehouse_id', $other->id)->value('quantity'),
            '退货不应影响其他仓库的库存'
        );
    }

    public function test_return_process_rolls_back_every_item_when_a_later_item_is_short(): void
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

        $id = $this->createReturn($warehouse->id, [
            ['product_id' => $this->productId, 'quantity' => 4, 'price' => 2],
            ['product_id' => $secondProduct->id, 'quantity' => 5, 'price' => 1],
        ]);
        $this->postJson("/admin/business/return/{$id}/approve")->assertOk();

        $this->postJson("/admin/business/return/{$id}/process")->assertStatus(422);

        $this->assertSame(
            5,
            (int) Stock::where('product_id', $this->productId)->where('warehouse_id', $warehouse->id)->value('quantity'),
            '第一条明细的扣减必须随整体回滚撤回'
        );
        $this->assertSame(
            1,
            (int) Stock::where('product_id', $secondProduct->id)->where('warehouse_id', $warehouse->id)->value('quantity')
        );
        $this->assertSame('approved', \DB::table('returns')->find($id)->status);
    }

    public function test_return_process_is_once_only(): void
    {
        $warehouse = $this->makeWarehouse();
        $this->stockIn($warehouse->id, 10);
        $id = $this->createReturn($warehouse->id);
        $this->postJson("/admin/business/return/{$id}/approve")->assertOk();
        $this->postJson("/admin/business/return/{$id}/process")->assertOk();

        // 已完成不能重复处理（否则会把库存扣两次）
        $this->postJson("/admin/business/return/{$id}/process")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有已审批的退货单可以处理');
        $this->assertSame(
            7,
            (int) Stock::where('product_id', $this->productId)->where('warehouse_id', $warehouse->id)->value('quantity'),
            '重复处理不得二次扣减库存'
        );
    }

    public function test_return_statistics(): void
    {
        $warehouse = $this->makeWarehouse();
        $id = $this->createReturn($warehouse->id);

        $stats = $this->getJson('/admin/business/return/statistics')->json('data');
        $this->assertSame(1, (int) $stats['total_returns']);
        $this->assertSame(1, (int) $stats['pending']);
        $this->assertSame(0, (int) $stats['approved']);
        $this->assertSame(0, (int) $stats['completed']);
    }
}
