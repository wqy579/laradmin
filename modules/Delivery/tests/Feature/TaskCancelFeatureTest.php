<?php

namespace Tests\Delivery\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Delivery\Models\DeliveryLoad;
use Modules\Delivery\Models\DeliveryLoadItem;
use Modules\Delivery\Models\DeliveryTask;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Models\SalesReturn;
use Modules\Order\Models\SalesReturnItem;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * 配送任务取消：取消后必须回补库存 + 生成退货入库单
 *
 * 背景（开发文档 §5.5.6）：装车确认时已统一 stockOut 扣减库存
 * （LoadController::confirm），因此取消任务必须把对应商品退回仓库，
 * 否则账面库存永久少一份。
 *
 * 不变量：取消后库存增加量 = 被取消任务的明细数量之和。
 *
 * 可取消状态：pending（待配送）、delivering（配送中）。
 * 不可取消：delivered（已送达）、paid（已收款）——货已到客户手中，应走销售退货流程。
 *
 * 注意：测试直接构造装车单/任务（不模拟 LoadController::confirm 的 stockOut），
 * 因此库存基线是初始值，断言用「基线 + 回补量」验证。
 */
class TaskCancelFeatureTest extends TestCase
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

    private function addStock(int $productId, int $quantity): void
    {
        Stock::create([
            'product_id' => $productId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $quantity,
        ]);
    }

    private function stockQty(int $productId): int
    {
        return (int) Stock::where('product_id', $productId)
            ->where('warehouse_id', $this->warehouseId)
            ->value('quantity');
    }

    private function makeLoad(string $status = 'delivering'): DeliveryLoad
    {
        return DeliveryLoad::create([
            'load_no' => $this->nextNo('LD'),
            'load_date' => now()->toDateString(),
            'status' => $status,
            'loaded_at' => $status === 'pending' ? null : now(),
        ]);
    }

    /** 建一条任务（含快照字段，避免 NOT NULL 约束失败） */
    private function makeTask(DeliveryLoad $load, string $status, int $checkId, ?SalesOrder $order = null): DeliveryTask
    {
        return DeliveryTask::create(array_merge([
            'task_no' => $this->nextNo('RW'),
            'load_id' => $load->id,
            'load_no' => $load->load_no,
            'check_id' => $checkId,
            'check_no' => 'YH'.$checkId,
            'warehouse_id' => $this->warehouseId,
            'status' => $status,
            'total_qty' => 0,
        ], $order ? [
            'sales_order_id' => $order->id,
            'order_no' => $order->order_no,
            'customer_id' => $order->customer_id,
        ] : []));
    }

    private function makeLoadItem(DeliveryLoad $load, int $checkId, int $productId, int $qty, float $price = 10.0): void
    {
        DeliveryLoadItem::create([
            'load_id' => $load->id,
            'check_id' => $checkId,
            'product_id' => $productId,
            'quantity' => $qty,
            'price' => $price,
            'amount' => round($qty * $price, 2),
        ]);
    }

    private function makeOrder(): SalesOrder
    {
        $customer = $this->makeCustomer();

        return SalesOrder::create([
            'order_no' => 'SO'.now()->format('Ymd').str_pad((string) ++self::$seq, 6, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'status' => '配送中',
        ]);
    }

    private function assertCancel(string $reason): TestResponse
    {
        return $this->postJson('/admin/business/delivery-task/999999/cancel', ['cancel_reason' => $reason]);
    }

    public function test_cancel_pending_task_returns_stock_and_creates_return_order(): void
    {
        $this->addStock($this->productId, 20);
        $load = $this->makeLoad();
        $task = $this->makeTask($load, 'pending', 100001);
        $this->makeLoadItem($load, 100001, $this->productId, 5);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'cancelled')
            ->assertJsonPath('data.task.cancel_reason', '客户取消订单')
            ->assertJsonPath('data.return_order.status', 'auto_return');

        $task->refresh();
        $this->assertSame('cancelled', $task->status);
        $this->assertNotNull($task->return_id);

        // 库存回补：基线 20 + 回补 5 = 25
        $this->assertSame(25, $this->stockQty($this->productId));

        $this->assertDatabaseHas('sales_returns', [
            'id' => $task->return_id,
            'return_type' => 'cancel',
            'status' => 'auto_return',
        ]);
    }

    public function test_cancel_delivering_task_is_allowed(): void
    {
        $this->addStock($this->productId, 20);
        $load = $this->makeLoad();
        $task = $this->makeTask($load, 'delivering', 100002);
        $this->makeLoadItem($load, 100002, $this->productId, 3);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [
            'cancel_reason' => '客户拒收',
        ])->assertOk();

        $this->assertSame('cancelled', $task->refresh()->status);
        $this->assertSame(23, $this->stockQty($this->productId));
    }

    public function test_cancel_delivered_task_is_rejected(): void
    {
        $this->addStock($this->productId, 20);
        $load = $this->makeLoad();
        $task = $this->makeTask($load, 'delivered', 100003);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertStatus(422);

        $this->assertSame('delivered', $task->refresh()->status);
        $this->assertSame(20, $this->stockQty($this->productId), '不可取消状态不应回补库存');
    }

    public function test_cancel_paid_task_is_rejected(): void
    {
        $load = $this->makeLoad();
        $task = $this->makeTask($load, 'paid', 100004);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertStatus(422);

        $this->assertSame('paid', $task->refresh()->status);
    }

    public function test_cancel_nonexistent_task_returns_404(): void
    {
        $this->assertCancel('客户取消订单')->assertStatus(404);
    }

    public function test_cancel_without_reason_is_rejected(): void
    {
        $load = $this->makeLoad();
        $task = $this->makeTask($load, 'pending', 100006);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cancel_reason']);

        $this->assertSame('pending', $task->refresh()->status);
    }

    public function test_cancel_all_tasks_of_load_rolls_back_load_to_pending(): void
    {
        $this->addStock($this->productId, 30);
        $load = $this->makeLoad();
        $checkId = 100010;
        $task1 = $this->makeTask($load, 'pending', $checkId);
        $task2 = $this->makeTask($load, 'delivering', $checkId);
        $this->makeLoadItem($load, $checkId, $this->productId, 4);

        // 第一个取消后，装车单仍为 delivering（第二个还在配送中）
        $this->postJson("/admin/business/delivery-task/{$task1->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertOk();
        $this->assertSame('delivering', $load->fresh()->status);

        // 第二个取消后，全部取消 → 装车单回退 pending、清空装车时间
        $this->postJson("/admin/business/delivery-task/{$task2->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertOk();
        $this->assertSame('pending', $load->fresh()->status);
        $this->assertNull($load->fresh()->loaded_at);
    }

    public function test_cancel_task_with_order_rolls_back_order_status(): void
    {
        $this->addStock($this->productId, 20);
        $order = $this->makeOrder();
        $load = $this->makeLoad();
        $task = $this->makeTask($load, 'delivering', 100012, $order);
        $this->makeLoadItem($load, 100012, $this->productId, 5);

        $this->assertSame('配送中', $order->fresh()->status);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertOk();

        // 该订单仅一个任务且已取消 → 订单回退「待配送」
        $this->assertSame('待配送', $order->fresh()->status);
    }

    public function test_cancel_task_keeps_order_status_when_other_task_active(): void
    {
        $this->addStock($this->productId, 20);
        $order = $this->makeOrder();
        $load = $this->makeLoad();
        $task1 = $this->makeTask($load, 'pending', 100014, $order);
        // 第二个任务关联同一订单，仍配送中
        $this->makeTask($load, 'delivering', 100015, $order);
        $this->makeLoadItem($load, 100014, $this->productId, 3);

        $this->postJson("/admin/business/delivery-task/{$task1->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertOk();

        $this->assertSame('配送中', $order->fresh()->status, '其他任务仍配送中时订单不应回退');
    }

    public function test_cancel_multiple_items_returns_each_product(): void
    {
        $product2 = $this->makeProduct();
        $this->addStock($this->productId, 10);
        $this->addStock($product2->id, 10);
        $load = $this->makeLoad();
        $checkId = 100020;
        $task = $this->makeTask($load, 'delivering', $checkId);
        $this->makeLoadItem($load, $checkId, $this->productId, 3, 10.0);
        $this->makeLoadItem($load, $checkId, $product2->id, 2, 20.0);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertOk();

        // 两个商品各自回补
        $this->assertSame(13, $this->stockQty($this->productId));
        $this->assertSame(12, $this->stockQty($product2->id));

        // 退货入库单含两条明细
        $task->refresh();
        $this->assertSame(2, SalesReturnItem::where('return_id', $task->return_id)->count());
    }

    public function test_auto_return_is_excluded_from_approved_return_calculations(): void
    {
        // 回归防护：auto_return 绝不能被当成已审核退货。
        // SalesReturnController::getReturnedQtyByOrder 以 where('status','approved') 累计已退数量，
        // BusinessHistoryController 经营历程以 status='approved' 计收入；
        // 若此处写成 approved，会产生幽灵收入并虚增可退数量。
        $this->addStock($this->productId, 20);
        $load = $this->makeLoad();
        $task = $this->makeTask($load, 'pending', 100030);
        $this->makeLoadItem($load, 100030, $this->productId, 5);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [
            'cancel_reason' => '客户取消订单',
        ])->assertOk();

        $returnId = $task->refresh()->return_id;

        // 关键断言：不属于 approved 集合
        $this->assertSame(0, SalesReturn::where('id', $returnId)->where('status', 'approved')->count());
        // 可被「排除 cancelled 外的全部」口径命中——说明该单据是真实存在的退货记录，
        // 只是不进入财务口径
        $this->assertSame(1, SalesReturn::where('id', $returnId)->where('status', '!=', 'cancelled')->count());

        // 库存仍回补成功（功能性不受口径影响）
        $this->assertSame(25, $this->stockQty($this->productId));
    }

    public function test_cancel_exception_task_is_allowed(): void
    {
        // 回归防护：exception 必须可取消，否则异常任务无出口、任务与订单永久卡死
        $this->addStock($this->productId, 20);
        $load = $this->makeLoad();
        $task = $this->makeTask($load, 'exception', 100032);
        $task->exception_type = 'reject';
        $task->save();
        $this->makeLoadItem($load, 100032, $this->productId, 4);

        $this->postJson("/admin/business/delivery-task/{$task->id}/cancel", [
            'cancel_reason' => '客户拒收，取消配送',
        ])->assertOk();

        $task->refresh();
        $this->assertSame('cancelled', $task->status);
        $this->assertSame(24, $this->stockQty($this->productId));
    }

    public function test_exception_accepts_unreachable_and_other_types(): void
    {
        // 异常登记一次后状态变为 exception，不可重复登记，故每种类型各建一个任务
        $cases = ['unreachable' => '联系不上客户', 'other' => '其他异常', 'reject' => '客户拒收'];
        $checkId = 100021;

        foreach ($cases as $type => $remark) {
            $load = $this->makeLoad();
            $task = $this->makeTask($load, 'delivering', $checkId++);

            $this->postJson("/admin/business/delivery-task/{$task->id}/exception", [
                'exception_type' => $type,
                'exception_remark' => $remark,
            ])->assertOk();

            $task->refresh();
            $this->assertSame('exception', $task->status);
            $this->assertSame($type, $task->exception_type);
        }
    }
}
