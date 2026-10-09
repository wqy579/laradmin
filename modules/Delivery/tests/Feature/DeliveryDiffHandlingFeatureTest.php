<?php

namespace Tests\Delivery\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryCheck;
use Modules\Delivery\Models\DeliveryCheckItem;
use Modules\Delivery\Models\DeliveryPick;
use Modules\Delivery\Models\DeliveryPickItem;
use Modules\Stock\Models\StockAdjust;
use Modules\Stock\Models\StockAdjustItem;
use Tests\TestCase;

/**
 * 拣货缺货通知 + 验货差异报损单联动（文档 §5.5.4 / §5.5.6）
 *
 * 两个独立的业务规则，放在一起测因为它们串起了拣货→验货的差异处理链路：
 *
 * 1. 缺货通知：PickController::confirm 算出 short_qty > 0 时，
 *    通过 TaskNotification 契约给操作人发一条 warning 类通知。
 *    不变量：有缺货 → system_notification 表多一条、title 含「拣货缺货提醒」。
 *
 * 2. 报损单联动：CheckController::confirm 验货差异（diff_qty != 0）时，
 *    自动建一张 StockAdjust 草稿（diff>0→stock_loss / diff<0→stock_gain），
 *    不直接动库存——扣减在审核通过后由 StockService::adjust 完成。
 *    不变量：有差异 → stock_adjusts 多一张 draft、status=draft、adjust_type 与差异方向匹配。
 */
class DeliveryDiffHandlingFeatureTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private int $productId;

    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->productId = $this->makeProduct()->id;
        $this->warehouseId = $this->makeWarehouse()->id;
    }

    private function nextNo(string $prefix): string
    {
        self::$seq++;

        return $prefix.date('Ymd').str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
    }

    /** 建一条拣货单 + 明细 */
    private function makePick(int $pickQty): DeliveryPick
    {
        $pick = DeliveryPick::create([
            'pick_no' => $this->nextNo('PJ'),
            'picking_id' => 1,
            'picking_no' => 'PP'.$this::$seq,
            'customer_id' => $this->makeCustomer()->id,
            'warehouse_id' => $this->warehouseId,
            'pick_date' => now()->toDateString(),
            'total_skus' => 1,
            'total_qty' => $pickQty,
            'status' => DeliveryPick::STATUS_PENDING,
        ]);
        DeliveryPickItem::create([
            'pick_id' => $pick->id,
            'product_id' => $this->productId,
            'product_code' => 'P001',
            'product_name' => '测试商品',
            'pick_qty' => $pickQty,
            'actual_qty' => $pickQty,
            'short_qty' => 0,
            'sort' => 0,
        ]);

        return $pick;
    }

    /** 由拣货单生成验货单（模拟 PickController::confirm 的 flow->createCheckFromPick） */
    private function makeCheck(DeliveryPick $pick): DeliveryCheck
    {
        $check = DeliveryCheck::create([
            'check_no' => $this->nextNo('YH'),
            'pick_id' => $pick->id,
            'pick_no' => $pick->pick_no,
            'customer_id' => $pick->customer_id,
            'customer_name' => $pick->customer_name,
            'check_date' => now()->toDateString(),
            'total_skus' => 1,
            'expected_qty' => $pick->total_qty,
            'actual_qty' => $pick->total_qty,
            'diff_qty' => 0,
            'status' => DeliveryCheck::STATUS_PENDING,
        ]);
        foreach ($pick->items as $i => $item) {
            DeliveryCheckItem::create([
                'check_id' => $check->id,
                'pick_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'pick_qty' => $item->pick_qty,
                'actual_qty' => $item->pick_qty,
                'diff_qty' => 0,
                'sort' => $i,
            ]);
        }

        return $check;
    }

    // ─── 缺货通知 ───────────────────────────────────────────────

    public function test_pick_shortage_sends_notification(): void
    {
        $pick = $this->makePick(10); // 应拣 10

        // 不直接用 System\Models\Notification（会引入 Delivery->System 边），
        // 走裸表名查询通知表。TaskNotification 实现落在 System，
        // 但测试只验证「通知落库了」，不关心其模型实现。
        $beforeCount = DB::table('system_notification')->count();
        // 实拣 6 → 缺货 4
        $this->postJson("/admin/business/delivery-pick/{$pick->id}/confirm", [
            'items' => [
                ['id' => $pick->items[0]->id, 'actual_qty' => 6],
            ],
        ])->assertOk();

        $this->assertSame($beforeCount + 1, DB::table('system_notification')->count(), '缺货应发一条通知');
        $this->assertDatabaseHas('system_notification', [
            'title' => '拣货缺货提醒',
        ]);
    }

    public function test_pick_without_shortage_sends_no_notification(): void
    {
        $pick = $this->makePick(10);

        $beforeCount = DB::table('system_notification')->count();
        // 实拣 = 应拣，无缺货
        $this->postJson("/admin/business/delivery-pick/{$pick->id}/confirm", [
            'items' => [
                ['id' => $pick->items[0]->id, 'actual_qty' => 10],
            ],
        ])->assertOk();

        $this->assertSame($beforeCount, DB::table('system_notification')->count(), '无缺货不应发通知');
    }

    // ─── 报损单联动 ─────────────────────────────────────────────

    public function test_check_diff_creates_stock_loss_draft(): void
    {
        $pick = $this->makePick(10);
        $check = $this->makeCheck($pick);

        // 实验 7，应验 10 → diff=3（破损），应生成 stock_loss 草稿
        $this->postJson("/admin/business/delivery-check/{$check->id}/confirm", [
            'items' => [
                ['id' => $check->items[0]->id, 'actual_qty' => 7, 'remark' => '3件破损'],
            ],
        ])->assertOk();

        $adjust = StockAdjust::where('reason', 'like', "%{$check->check_no}%")->first();
        $this->assertNotNull($adjust, '应生成库存调整草稿');
        $this->assertSame('draft', $adjust->status, '调整单应为草稿状态');
        $this->assertSame('stock_loss', $adjust->adjust_type, '实验<应验应报损');
        // adjust_qty = -diff = -3
        $this->assertSame(-3.0, (float) $adjust->total_qty);
        $this->assertSame(1, StockAdjustItem::where('adjust_id', $adjust->id)->count());

        $check->refresh();
        $this->assertSame('exception', $check->status, '验货单应标异常');
        $this->assertSame('picking', $pick->fresh()->status, '拣货单应回退拣货中');
    }

    public function test_check_diff_exceeding_pick_qty_is_rejected(): void
    {
        // 验货环节强制 actual_qty <= pick_qty，故 diff 恒 >= 0、报溢不会发生。
        // 锁住这条约束：实验超过应验应被拒，且不生成任何调整单。
        $pick = $this->makePick(5);
        $check = $this->makeCheck($pick);

        $this->postJson("/admin/business/delivery-check/{$check->id}/confirm", [
            'items' => [
                ['id' => $check->items[0]->id, 'actual_qty' => 8, 'remark' => '多出3件'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, StockAdjust::count());
    }

    public function test_check_no_diff_creates_no_adjust(): void
    {
        $pick = $this->makePick(10);
        $check = $this->makeCheck($pick);

        $beforeCount = StockAdjust::count();
        // 实验 = 应验，无差异
        $this->postJson("/admin/business/delivery-check/{$check->id}/confirm", [
            'items' => [
                ['id' => $check->items[0]->id, 'actual_qty' => 10],
            ],
        ])->assertOk();

        $this->assertSame($beforeCount, StockAdjust::count(), '无差异不应生成调整单');
        $this->assertSame('checked', $check->fresh()->status);
    }

    public function test_check_diff_without_remark_is_rejected(): void
    {
        $pick = $this->makePick(10);
        $check = $this->makeCheck($pick);

        // 有差异但不填备注 → 422，且不生成调整单
        $this->postJson("/admin/business/delivery-check/{$check->id}/confirm", [
            'items' => [
                ['id' => $check->items[0]->id, 'actual_qty' => 7],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, StockAdjust::count());
    }

    public function test_check_multiple_diffs_in_one_adjust(): void
    {
        // 两个商品各有差异 → 合并到同一张调整单
        $product2 = $this->makeProduct();
        $pick = DeliveryPick::create([
            'pick_no' => $this->nextNo('PJ'),
            'picking_id' => 1,
            'picking_no' => 'PP'.$this::$seq,
            'customer_id' => $this->makeCustomer()->id,
            'warehouse_id' => $this->warehouseId,
            'pick_date' => now()->toDateString(),
            'total_skus' => 2,
            'total_qty' => 15,
            'status' => DeliveryPick::STATUS_PENDING,
        ]);
        DeliveryPickItem::create([
            'pick_id' => $pick->id, 'product_id' => $this->productId,
            'product_code' => 'P1', 'product_name' => '商品A', 'pick_qty' => 10, 'sort' => 0,
        ]);
        DeliveryPickItem::create([
            'pick_id' => $pick->id, 'product_id' => $product2->id,
            'product_code' => 'P2', 'product_name' => '商品B', 'pick_qty' => 5, 'sort' => 1,
        ]);

        $check = $this->makeCheck($pick);

        // 商品A 实拣8(缺2)，商品B 实拣3(缺2) → 合并报损 adjust_qty=-4
        $this->postJson("/admin/business/delivery-check/{$check->id}/confirm", [
            'items' => [
                ['id' => $check->items[0]->id, 'actual_qty' => 8, 'remark' => '缺2'],
                ['id' => $check->items[1]->id, 'actual_qty' => 3, 'remark' => '缺2'],
            ],
        ])->assertOk();

        $adjust = StockAdjust::where('reason', 'like', "%{$check->check_no}%")->first();
        $this->assertNotNull($adjust);
        $this->assertSame('stock_loss', $adjust->adjust_type);
        $this->assertSame(-4.0, (float) $adjust->total_qty);
        $this->assertSame(2, StockAdjustItem::where('adjust_id', $adjust->id)->count());
    }
}
