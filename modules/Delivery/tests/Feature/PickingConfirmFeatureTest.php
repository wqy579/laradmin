<?php

namespace Tests\Delivery\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryPick;
use Modules\Delivery\Models\DeliveryPicking;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * 确认配货的库存冻结协调（问题1回归：确认配货后状态不变/拣货单不生成）。
 *
 * 根因：销售订单创建时（freeze_stock 默认 true）已调 StockService::freeze 累加
 * frozen_qty；PickingController::confirm 若再次 freeze，StockService 的库存不足
 * 判定（quantity - frozen_qty < 需要量）必抛 StockRuleException，事务整体回滚——
 * 配货单状态不变、拣货单不生成、订单状态不变，三个症状同源。
 *
 * 修复后契约（PickingController::confirm 的冻结策略）：
 * - 订单已冻结（stocks_history 有 sale_freeze 流水）→ 配货不重复 freeze，
 *   frozen_from_order=true，复用订单冻结量；
 * - 订单未冻结（freeze_stock=false）→ 配货执行真正 freeze，frozen_from_order=false；
 * - 取消配货时 frozen_from_order=true 不 unfreeze（冻结量归订单，由订单释放）。
 */
class PickingConfirmFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    private int $warehouseId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->productId = $this->makeProduct()->id;
        $this->warehouseId = $this->makeWarehouse()->id;
        $this->customerId = $this->makeCustomer()->id;
        $this->addStock($this->productId, 100);
    }

    private function addStock(int $productId, int $quantity): void
    {
        Stock::create([
            'product_id' => $productId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $quantity,
        ]);
    }

    private function frozenQty(int $productId): int
    {
        return (int) Stock::where('product_id', $productId)
            ->where('warehouse_id', $this->warehouseId)
            ->value('frozen_qty');
    }

    /** 经 SalesOrderController::store 建订单（与生产一致的冻结链路），返回订单ID */
    private function createOrder(int $qty, bool $freezeStock): int
    {
        $res = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'freeze_stock' => $freezeStock,
            'items' => [
                ['product_id' => $this->productId, 'quantity' => $qty, 'price' => 10],
            ],
        ]);

        $res->assertOk();

        return (int) $res->json('data.id');
    }

    /** 经 PickingController::store 建配货单（初始 pending），返回配货单模型 */
    private function createPicking(int $orderId, int $orderItemId, int $qty): DeliveryPicking
    {
        $res = $this->postJson('/admin/business/delivery-picking', [
            'sales_order_id' => $orderId,
            'items' => [
                ['item_id' => $orderItemId, 'product_id' => $this->productId, 'quantity' => $qty, 'price' => 10],
            ],
        ]);
        $res->assertOk();

        return DeliveryPicking::where('sales_order_id', $orderId)->firstOrFail();
    }

    /**
     * 测试 A（线上场景复现）：订单下单即冻结 → 确认配货成功，不重复冻结。
     *
     * 库存 100、订单冻 10：旧代码在此场景 confirm 时再冻 10，
     * 判定 100-10=90 ≥ 10 会通过……但 frozen_qty 会膨胀到 20（双倍冻结）；
     * 库存紧张时（如订单冻到接近可用量）则直接抛库存不足回滚。
     * 本测试用紧凑库存（订单冻 10、初始可用恰好 10）锁死两个断言：
     * confirm 不报错 + frozen_qty 仍为 10（没有二次累加）。
     */
    public function test_confirm_succeeds_when_order_already_froze_stock(): void
    {
        // 初始库存只放 10（够订单冻，但绝不够二次冻结）
        Stock::where('product_id', $this->productId)->where('warehouse_id', $this->warehouseId)->update(['quantity' => 10]);

        $orderId = $this->createOrder(10, true);
        $orderItemId = DB::table('sales_order_items')->where('sales_order_id', $orderId)->value('id');
        $picking = $this->createPicking($orderId, $orderItemId, 10);

        $this->assertSame(10, $this->frozenQty($this->productId), '下单应冻结 10');

        $res = $this->postJson("/admin/business/delivery-picking/{$picking->id}/confirm");
        $res->assertOk();

        $picking->refresh();
        $this->assertSame('picked', $picking->status, '配货单应变为已配货');
        $this->assertTrue((bool) $picking->stock_frozen, '配货单应标记库存已冻结');
        $this->assertTrue((bool) $picking->frozen_from_order, '冻结量源自订单，应标记 frozen_from_order');
        $this->assertNotNull($picking->pick_id, '应生成拣货单');
        $this->assertSame(10, $this->frozenQty($this->productId), '不得重复累加 frozen_qty');
        $this->assertSame('配货中', DB::table('sales_orders')->where('id', $orderId)->value('status'), '订单状态应推进为配货中');

        $pick = DeliveryPick::find($picking->pick_id);
        $this->assertNotNull($pick, '拣货单记录应存在');
        $this->assertSame($picking->id, $pick->picking_id, '拣货单应关联回配货单');
    }

    /** 测试 B：订单跳过冻结（freeze_stock=false）→ 配货执行真正冻结 */
    public function test_confirm_freezes_when_order_skipped_freeze(): void
    {
        $orderId = $this->createOrder(10, false);
        $this->assertSame(0, $this->frozenQty($this->productId), '订单应未冻结');

        $orderItemId = DB::table('sales_order_items')->where('sales_order_id', $orderId)->value('id');
        $picking = $this->createPicking($orderId, $orderItemId, 10);

        $res = $this->postJson("/admin/business/delivery-picking/{$picking->id}/confirm");
        $res->assertOk();

        $picking->refresh();
        $this->assertSame('picked', $picking->status);
        $this->assertTrue((bool) $picking->stock_frozen, '配货应补冻结');
        $this->assertFalse((bool) $picking->frozen_from_order, '冻结量由配货产生，非订单');
        $this->assertSame(10, $this->frozenQty($this->productId), '配货应冻结 10');
        $this->assertNotNull($picking->pick_id);
    }

    /**
     * 测试 C：取消配货单时的解冻协调。
     * - frozen_from_order=true：冻结量归订单，取消配货不释放（订单 cancel/destroy 时释放）；
     * - frozen_from_order=false：配货自己冻的量，取消时必须释放。
     */
    public function test_cancel_keeps_order_freeze_but_releases_own_freeze(): void
    {
        // ── 场景1：订单冻结过，配货取消不释放 ──
        $orderId = $this->createOrder(10, true);
        $orderItemId = DB::table('sales_order_items')->where('sales_order_id', $orderId)->value('id');
        $picking1 = $this->createPicking($orderId, $orderItemId, 10);
        $this->postJson("/admin/business/delivery-picking/{$picking1->id}/confirm")->assertOk();

        $res = $this->postJson("/admin/business/delivery-picking/{$picking1->id}/cancel");
        $res->assertOk();
        $this->assertSame('cancelled', $picking1->fresh()->status, '配货单应已取消');
        $this->assertSame(10, $this->frozenQty($this->productId), '订单冻结量应保留，不随配货取消释放');

        // ── 场景2：配货自己冻的，取消时释放 ──
        // 此时场景1的订单冻结量仍保留（10），场景2 配货再冻 10 → 合计 20
        $orderId2 = $this->createOrder(10, false);
        $orderItemId2 = DB::table('sales_order_items')->where('sales_order_id', $orderId2)->value('id');
        $picking2 = $this->createPicking($orderId2, $orderItemId2, 10);
        $this->postJson("/admin/business/delivery-picking/{$picking2->id}/confirm")->assertOk();
        $this->assertSame(20, $this->frozenQty($this->productId), '场景1订单10 + 场景2配货10 = 20');

        $this->postJson("/admin/business/delivery-picking/{$picking2->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $picking2->fresh()->status);
        $this->assertSame(10, $this->frozenQty($this->productId), '配货自己冻的量释放后，应回退到场景1订单的10');
    }

    /** 幂等防护：已确认过的配货单再次 confirm 被拒（不产生重复拣货单） */
    public function test_confirm_twice_is_rejected(): void
    {
        $orderId = $this->createOrder(10, true);
        $orderItemId = DB::table('sales_order_items')->where('sales_order_id', $orderId)->value('id');
        $picking = $this->createPicking($orderId, $orderItemId, 10);

        $this->postJson("/admin/business/delivery-picking/{$picking->id}/confirm")->assertOk();
        $this->postJson("/admin/business/delivery-picking/{$picking->id}/confirm")->assertStatus(422);

        $this->assertSame(1, DeliveryPick::where('picking_id', $picking->id)->count(), '不应重复生成拣货单');
    }
}
