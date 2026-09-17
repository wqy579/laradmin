<?php

namespace Tests\Stock\Feature;

use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T5 库存不变量测试
 *
 * 库存是进销存系统的命根子，本类守住以下不变量：
 *  I1 入库/出库/调拨后 stocks 表数量与单据数量一致；
 *  I2 任何路径都不允许产生负库存；
 *  I3 多次变动后按 (product, warehouse) 聚合的库存 = 期初 + 入库 - 出库。
 *
 * 路由前缀说明：入库/出库接口直接作用于 stocks 表（不存在独立单据表）。
 */
class StockFeatureTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- 入库

    public function test_stock_in_creates_row_for_new_pair(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();

        $response = $this->postJson('/admin/business/stock-in', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
            'cost_price' => 1.5,
        ]);

        // 回归保护：新品首单入库。早期实现用
        //   Stock::updateOrCreate([...], ['quantity' => DB::raw('quantity + 5')])
        // 在「该 (商品,仓库) 组合还没有库存行」的插入路径上生成
        //   insert into stocks (..., quantity, ...) values (..., quantity + 5, ...)
        // SQLite 报 no such column: quantity，MySQL 也报 1054——新品首次入库必然失败。
        // 现已收敛到 StockService::stockIn()（查无则 create、有则累加），此处守住这条路径。
        $response->assertStatus(200);

        $this->assertDatabaseHas('stocks', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);
    }

    public function test_stock_in_accumulates_on_existing_row(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

        $response = $this->postJson('/admin/business/stock-in', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);

        $response->assertOk();
        $this->assertSame('入库成功', $response->json('message'));

        $stock = Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame(15, (int) $stock->quantity, '入库后应为 10 + 5 = 15');
    }

    public function test_stock_in_validates_payload(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();

        // quantity 必须为正整数
        $this->postJson('/admin/business/stock-in', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 0,
        ])->assertStatus(422);

        // 商品/仓库必须存在
        $this->postJson('/admin/business/stock-in', [
            'product_id' => 999999,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ])->assertStatus(422);
    }

    // ---------------------------------------------------------------- 出库

    public function test_stock_out_rejects_insufficient_stock(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 3]);

        $response = $this->postJson('/admin/business/stock-out', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(422);
        $this->assertSame('库存不足', $response->json('message'));
        $this->assertSame(3, (int) Stock::first()->quantity, '拒绝后库存必须保持不变');
    }

    public function test_stock_out_to_zero_exactly(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5]);

        $this->postJson('/admin/business/stock-out', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ])->assertOk();

        $this->assertSame(0, (int) Stock::first()->quantity);
    }

    public function test_stock_out_on_missing_stock_row_is_rejected(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();

        $response = $this->postJson('/admin/business/stock-out', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertSame('库存不足', $response->json('message'));
        $this->assertSame(0, (int) Stock::count(), '无库存行出库不应凭空创建行');
    }

    public function test_stock_roundtrip_consistency_invariant(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 0]);

        $flow = [
            ['stock-in', 10],
            ['stock-out', 4],
            ['stock-in', 2],
            ['stock-out', 8],
        ];

        $expected = 0;
        foreach ($flow as [$endpoint, $qty]) {
            $this->postJson("/admin/business/{$endpoint}", [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $qty,
            ])->assertOk();

            $expected += $endpoint === 'stock-in' ? $qty : -$qty;
            $this->assertSame(
                $expected,
                (int) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'),
                "第 {$qty} 笔 {$endpoint} 后库存与流水账不一致"
            );
        }

        // I3：全表聚合守恒
        $this->assertSame($expected, (int) Stock::sum('quantity'));
    }

    // ---------------------------------------------------------------- 调拨

    private function createTransferViaApi(Product $product, Warehouse $from, Warehouse $to, int $qty, float $price = 1.0): int
    {
        $response = $this->postJson('/admin/business/transfer', [
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'transfer_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => $qty, 'price' => $price],
            ],
        ]);
        $response->assertOk();

        return (int) $response->json('data.id');
    }

    public function test_transfer_execute_requires_approved_status(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $from = $this->makeWarehouse();
        $to = $this->makeWarehouse();
        $transferId = $this->createTransferViaApi($product, $from, $to, 3);

        $this->postJson("/admin/business/transfer/{$transferId}/execute")
            ->assertStatus(422)
            ->assertJsonPath('message', '只有已审批的调拨单可以执行');

        $this->assertSame('draft', \DB::table('transfers')->where('id', $transferId)->value('status'));
        $this->assertSame(0, (int) Stock::count(), '草稿调拨不应动库存');
    }

    public function test_transfer_execute_moves_stock_happy_path(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $from = $this->makeWarehouse();
        $to = $this->makeWarehouse();

        Stock::create(['product_id' => $product->id, 'warehouse_id' => $from->id, 'quantity' => 10]);

        $transferId = $this->createTransferViaApi($product, $from, $to, 4);

        // 走正常审批（当前只能通过数据库置为 approved —— API 层没有审批入口，见下一条用例）
        \DB::table('transfers')->where('id', $transferId)->update(['status' => 'approved']);

        $response = $this->postJson("/admin/business/transfer/{$transferId}/execute");

        $response->assertOk();
        $this->assertSame('调拨成功', $response->json('message'));

        $fromQty = (int) Stock::where('product_id', $product->id)->where('warehouse_id', $from->id)->value('quantity');
        $toQty = (int) Stock::where('product_id', $product->id)->where('warehouse_id', $to->id)->value('quantity');

        $this->assertSame(6, $fromQty, '源仓库应减去调拨数量');
        $this->assertSame(4, $toQty, '目标仓库应加上调拨数量（自动建行）');
        $this->assertSame('completed', \DB::table('transfers')->where('id', $transferId)->value('status'));

        // I1：调拨只换位置不改变总量
        $this->assertSame(10, (int) Stock::where('product_id', $product->id)->sum('quantity'));
    }

    public function test_transfer_execute_prevents_negative_source_stock(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $from = $this->makeWarehouse();
        $to = $this->makeWarehouse();

        // 源仓库只有 2 件，却要调 5 件
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $from->id, 'quantity' => 2]);

        $transferId = $this->createTransferViaApi($product, $from, $to, 5);
        \DB::table('transfers')->where('id', $transferId)->update(['status' => 'approved']);

        $response = $this->postJson("/admin/business/transfer/{$transferId}/execute");

        // 不变量 I2：不允许负库存。当前实现无条件 decrement 且不做库存校验，
        // 实际返回 200 并把源库存打到 -3 —— 本断言当前为红，是兜底网抓到的真实缺陷。
        $response->assertStatus(422);

        $this->assertSame(2, (int) Stock::where('product_id', $product->id)->where('warehouse_id', $from->id)->value('quantity'));
        $this->assertSame('approved', \DB::table('transfers')->where('id', $transferId)->value('status'), '执行失败单据不得置为 completed');
    }

    public function test_transfer_cannot_reach_executed_state_through_api(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();
        $from = $this->makeWarehouse();
        $to = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $from->id, 'quantity' => 10]);

        $transferId = $this->createTransferViaApi($product, $from, $to, 1);

        // 状态机必须可经 API 驱动：创建(draft) -> approve(approved) -> execute(completed)。
        // 此前没有 approve 端点，状态机死路（已补审批端点）。
        $this->postJson("/admin/business/transfer/{$transferId}/approve")->assertStatus(200);
        $response = $this->postJson("/admin/business/transfer/{$transferId}/execute");
        $response->assertStatus(200);
        $this->assertSame('completed', \DB::table('transfers')->where('id', $transferId)->value('status'));
    }

    // ---------------------------------------------------------------- 库存查询

    public function test_stock_index_returns_rows_with_relations(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct(['name' => '苹果汁']);
        $warehouse = $this->makeWarehouse(['name' => '一号仓']);
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 7]);

        $response = $this->getJson('/admin/business/stock?warehouse_id='.$warehouse->id);

        $response->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['quantity']);
        $this->assertSame('苹果汁', $rows[0]['product']['name'] ?? null, '库存行应带商品信息');
        $this->assertSame('一号仓', $rows[0]['warehouse']['name'] ?? null, '库存行应带仓库信息');
    }
}
