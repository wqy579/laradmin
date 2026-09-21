<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * T6 采购/销售订单状态机测试
 *
 * 采购单：draft --approve--> approved --receive--> received
 *   - receive 把每张明细的数量加进收货仓库（库存随单据流动，不再需要另开 stock-in 补记）
 *   - 只有 draft/approved 可以 cancel；已入库的单据不能作废
 * 销售单：draft --approve--> approved（无后续单据联动）
 *
 * approve / receive 都有前置状态校验，非法流转一律 422。
 * 此前这三处是「钉住现状」的红灯：approve 无前置校验（状态可被来回翻转）、
 * receive 只翻状态不动库存、没有取消端点。现已修复，用例改为正向断言。
 * 退货单的状态机与库存不足保护见 ReturnOrderFeatureTest。
 */
class OrderStateFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->productId, 'quantity' => 10, 'price' => 3.5],
                ['product_id' => $this->productId, 'quantity' => 5, 'price' => 2],
            ],
        ], $overrides);
    }

    private int $productId;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->actingAsAdmin()->id;
        $this->productId = $this->makeProduct()->id;
    }

    /** 下单即冻结库存，需为 (商品, 仓库) 预置库存 */
    private function seedStock(int $warehouseId, int $qty = 1000): void
    {
        Stock::create([
            'product_id' => $this->productId,
            'warehouse_id' => $warehouseId,
            'quantity' => $qty,
            'frozen_qty' => 0,
        ]);
    }

    // ---------------------------------------------------------------- 采购单

    public function test_purchase_order_store_creates_draft_with_totals(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();

        $response = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()->assertJsonPath('message', '创建成功');

        $order = DB::table('purchase_orders')->latest('id')->first();
        $this->assertSame('draft', $order->status);
        $this->assertStringStartsWith('PO', $order->order_no);
        $this->assertSame(15, (int) $order->total_qty, '总数量应为 10 + 5');
        $this->assertSame(45.0, (float) $order->total_amount, '总金额应为 10*3.5 + 5*2');
        $this->assertSame(2, (int) DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->count());

        // 参数校验：空 items / 不存在的供应商
        $this->postJson('/admin/business/purchase-order', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
        ])->assertStatus(422);
        $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => 999999,
            'warehouse_id' => $warehouse->id,
        ]))->assertStatus(422);
    }

    public function test_purchase_order_update_only_in_draft(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $create = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]));
        $id = $create->json('data.id');

        $this->putJson("/admin/business/purchase-order/{$id}", $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $this->productId, 'quantity' => 1, 'price' => 1],
            ],
        ]))->assertOk();

        $order = DB::table('purchase_orders')->find($id);
        $this->assertSame(1, (int) $order->total_qty, '编辑草稿应重算明细');

        // 审批后不可编辑
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->putJson("/admin/business/purchase-order/{$id}", $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertStatus(422);
    }

    public function test_purchase_order_destroy_only_in_draft(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        // 审批后不可删
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->deleteJson("/admin/business/purchase-order/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态可以删除');

        // 新建一张草稿单可删
        $id2 = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');
        $this->deleteJson("/admin/business/purchase-order/{$id2}")
            ->assertOk()
            ->assertJsonPath('message', '删除成功');
        $this->assertNull(DB::table('purchase_orders')->find($id2));
    }

    public function test_purchase_order_approve_flow(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        $this->postJson("/admin/business/purchase-order/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('message', '审批成功');

        $order = DB::table('purchase_orders')->find($id);
        $this->assertSame('approved', $order->status);
        $this->assertNotNull($order->approved_at);
        // 审计字段必须落到当前登录的管理员。这里用 auth('admin') 而不是默认 guard，
        // 因为默认 guard 是 web，管理端请求下取不到用户 id。
        $this->assertSame($this->adminId, $order->approved_by, 'approved_by 应记录审批人');
        $this->assertNotNull(DB::table('purchase_orders')->find($id)->created_by, 'created_by 应记录创建人');
    }

    public function test_purchase_order_receive_requires_approved_status(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        // 草稿不能直接入库
        $this->postJson("/admin/business/purchase-order/{$id}/receive")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有已审批的订单可以入库');

        // 审批后可入库
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$id}/receive")
            ->assertOk()
            ->assertJsonPath('message', '入库成功');
        $this->assertSame('received', DB::table('purchase_orders')->find($id)->status);

        // 入库后不能重复入库
        $this->postJson("/admin/business/purchase-order/{$id}/receive")->assertStatus(422);
    }

    public function test_purchase_order_receive_adds_items_to_receiving_warehouse_stock(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $this->productId, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $this->productId, 'quantity' => 5, 'price' => 2],
            ],
        ]))->json('data.id');

        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$id}/receive")
            ->assertOk()
            ->assertJsonPath('message', '入库成功');

        // 采购入库必须反映到收货仓库：此前 receive 只翻状态，采购单永远不进库存，
        // 出入库全靠另开一张 stock-in 手工补记。
        $this->assertSame(
            15,
            (int) Stock::where('product_id', $this->productId)->where('warehouse_id', $warehouse->id)->value('quantity'),
            '入库后收货仓库库存应为 10 + 5'
        );

        // 只进收货仓库，不动其他仓库
        $other = $this->makeWarehouse();
        $this->assertNull(
            Stock::where('product_id', $this->productId)->where('warehouse_id', $other->id)->first(),
            '入库不应在其他仓库凭空建出库存行'
        );

        // 采购单价不等于成本价，联动入库不得静默覆盖 cost_price
        $this->assertSame(
            0.0,
            (float) Stock::where('product_id', $this->productId)->where('warehouse_id', $warehouse->id)->value('cost_price')
        );
    }

    public function test_purchase_order_approve_only_in_draft(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        // 已入库的单据不能再审批，状态不得被打回 approved
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$id}/receive")->assertOk();
        $this->assertSame('received', DB::table('purchase_orders')->find($id)->status);

        $this->postJson("/admin/business/purchase-order/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态的订单可以审批');
        $this->assertSame('received', DB::table('purchase_orders')->find($id)->status);

        // 已审批未入库的单据同样不能重复审批
        $id2 = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');
        $this->postJson("/admin/business/purchase-order/{$id2}/approve")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$id2}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿状态的订单可以审批');
    }

    public function test_purchase_order_cancel_lifecycle(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();

        // 草稿与已审批的单据都能取消
        foreach (['draft', 'approved'] as $fromStatus) {
            $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
            ]))->json('data.id');

            if ($fromStatus === 'approved') {
                $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
            }

            $this->postJson("/admin/business/purchase-order/{$id}/cancel")
                ->assertOk()
                ->assertJsonPath('message', '取消成功');
            $this->assertSame('cancelled', DB::table('purchase_orders')->find($id)->status);
        }

        // 已入库的单据不能作废——库存变动已经发生
        $id = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');
        $this->postJson("/admin/business/purchase-order/{$id}/approve")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$id}/receive")->assertOk();

        $this->postJson("/admin/business/purchase-order/{$id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有草稿或已审批的订单可以取消');
        $this->assertSame('received', DB::table('purchase_orders')->find($id)->status);

        // 已取消的单据不能再取消，也不能再入库
        $cancelled = $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');
        $this->postJson("/admin/business/purchase-order/{$cancelled}/cancel")->assertOk();
        $this->postJson("/admin/business/purchase-order/{$cancelled}/cancel")->assertStatus(422);
        $this->postJson("/admin/business/purchase-order/{$cancelled}/receive")->assertStatus(422);
    }

    public function test_purchase_order_statistics(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $this->postJson('/admin/business/purchase-order', $this->orderPayload([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertOk();

        $response = $this->getJson('/admin/business/purchase-order/statistics');
        $response->assertOk();
        $stats = $response->json('data');
        $this->assertSame(1, (int) $stats['total_orders']);
        $this->assertSame(1, (int) $stats['pending']);
        $this->assertSame(0, (int) $stats['approved']);
    }

    // ---------------------------------------------------------------- 销售单

    public function test_sales_order_store_creates_pending_with_totals(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $this->seedStock($warehouse->id);

        $response = $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $response->assertOk()->assertJsonPath('message', '订单创建成功');

        $order = DB::table('sales_orders')->latest('id')->first();
        $this->assertSame('pending', $order->status);
        $this->assertStringStartsWith('SO', $order->order_no);
        $this->assertSame(15, (int) $order->total_qty);
        $this->assertSame(45.0, (float) $order->total_amount);
    }

    public function test_sales_order_approve_disabled_pending_editable(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $this->seedStock($warehouse->id);
        $payload = $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $id = $this->postJson('/admin/business/sales-order', $payload)->json('data.id');

        // 对齐旧系统：下单即生效，无审批环节，approve 一律 422
        $this->postJson("/admin/business/sales-order/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', '销售单下单即生效，无需审批');

        // 待配货可编辑 / 可删除
        $this->putJson("/admin/business/sales-order/{$id}", $payload)->assertOk();
        $this->deleteJson("/admin/business/sales-order/{$id}")->assertOk();
    }

    public function test_sales_order_cancel_only_from_pending(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $this->seedStock($warehouse->id);

        $id = $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]))->json('data.id');

        // 待配货可取消（并释放冻结库存）
        $this->postJson("/admin/business/sales-order/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', '取消成功');
        $this->assertSame('cancelled', \DB::table('sales_orders')->find($id)->status);

        // 已取消的不能再取消
        $this->postJson("/admin/business/sales-order/{$id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有待配货状态的订单可以作废');
    }

    public function test_sales_order_statistics_and_list_filter(): void
    {
        $customer1 = $this->makeCustomer();
        $customer2 = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $this->seedStock($warehouse->id);

        $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer1->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertOk();
        $this->postJson('/admin/business/sales-order', $this->orderPayload([
            'customer_id' => $customer2->id,
            'warehouse_id' => $warehouse->id,
        ]))->assertOk();

        $stats = $this->getJson('/admin/business/sales-order/statistics')->json('data');
        $this->assertSame(2, (int) $stats['total_orders']);
        $this->assertSame(2, (int) $stats['pending']);
        $this->assertSame(0, (int) $stats['approved']);

        // 列表按状态/客户过滤（分页统一走 paginated 信封：data.list）
        $this->getJson('/admin/business/sales-order?status=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data.list');
        $this->getJson("/admin/business/sales-order?customer_id={$customer1->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.list');
    }
}
