<?php

namespace Tests\Performance\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C5 库存预警族 验收测试（真实数据，禁止 mock）。
 *
 * 覆盖验收标准（执行文档 C5）：
 *  - 安全库存预警：当前库存 ≤ 安全库存列入；建议补货 = 安全库存×2 − 当前库存（min 0）；< 红 / = 橙
 *  - 临期/超期：距到期 ≤ 60 天列入，分级 已过期红 / 临期橙
 *  - 库存可销天数：可销天数 = 库存 / 近 30 日日均销量（可复算）；明细三级分级 断档=0 红 / 告急<7 橙 / 安全≥7 灰
 *  - 预警总览 4 卡：safety_stock / expiry / low_sellable / exception 与 byType 一致
 *  - 穿透采购申请：purchaseDraft 按商品返回建议补货量 + redirect
 */
class StockWarningFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $warehouseId;

    /** 造商品（可含 safety_stock / expiry_date / shelf_life_days），返回 id */
    private function seedProduct(array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'name' => '商品_'.substr(str_shuffle('ABCDEF0123456789'), 0, 6),
            'code' => 'P'.substr(str_shuffle('ABCDEF0123456789'), 0, 6),
            'is_active' => true,
            'is_online' => true,
            'safety_stock' => 0,
        ], $overrides));
    }

    /** 造库存行（多仓合计口径：单仓即可） */
    private function makeStock(int $productId, int $quantity): void
    {
        DB::table('stocks')->insert([
            'product_id' => $productId, 'warehouse_id' => $this->warehouseId,
            'quantity' => $quantity, 'frozen_qty' => 0, 'cost_price' => 0, 'total_amount' => 0,
        ]);
    }

    /** 造近 30 日出库流水（change_qty<0，绝对值累加为近 30 日销量） */
    private function makeOutflow(int $productId, int $qty): void
    {
        DB::table('stocks_history')->insert([
            'product_id' => $productId, 'warehouse_id' => $this->warehouseId,
            'change_type' => 'sale', 'change_qty' => -$qty, 'before_qty' => 0, 'after_qty' => 0,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
    }

    private function seedWarnings(): array
    {
        $this->actingAsAdmin(); // 建立真实 JWT 身份，避免 401
        $this->warehouseId = $this->makeWarehouse()->id;

        // 可销天数：日均销量 = 出库合计 ÷ 30；sellable = 库存 ÷ 日均
        $a = $this->seedProduct();            // 库存 10，30 天出 30 → 日均 1 → 可销 10 → 安全
        $b = $this->seedProduct();            // 库存 10，30 天出 300 → 日均 10 → 可销 1 → 告急
        $c = $this->seedProduct();            // 库存 0，30 天出 30 → 日均 1 → 可销 0 → 断档
        $this->makeStock($a, 10);
        $this->makeOutflow($a, 30);
        $this->makeStock($b, 10);
        $this->makeOutflow($b, 300);
        $this->makeStock($c, 0);
        $this->makeOutflow($c, 30);

        // 安全库存：d/e 列入（≤），f 超储不列入
        $d = $this->seedProduct(['safety_stock' => 20]);
        $this->makeStock($d, 10); // < 20 → 红，建议 30
        $e = $this->seedProduct(['safety_stock' => 20]);
        $this->makeStock($e, 20); // = 20 → 橙，建议 20
        $f = $this->seedProduct(['safety_stock' => 20]);
        $this->makeStock($f, 50); // 超储，不列入

        // 临期/超期：g 临期（剩 10 天）、h 过期（剩 -5 天）、i 远未到期（剩 80 天）不列入
        $g = $this->seedProduct(['shelf_life_days' => 30, 'expiry_date' => now()->addDays(10)->format('Y-m-d')]);
        $h = $this->seedProduct(['shelf_life_days' => 30, 'expiry_date' => now()->subDays(5)->format('Y-m-d')]);
        $i = $this->seedProduct(['shelf_life_days' => 30, 'expiry_date' => now()->addDays(80)->format('Y-m-d')]);

        return compact('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i');
    }

    public function test_safety_stock_lists_low_and_suggests_replenish(): void
    {
        $seed = $this->seedWarnings();

        $res = $this->getJson('/admin/perf/warning/safety-stock')
            ->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');

        $this->assertSame(2, $data['total']);
        $byId = collect($data['list'])->keyBy('product_id');

        // d：10 ≤ 20 → 红，建议补货 = 20×2 − 10 = 30
        $d = $byId[$seed['d']];
        $this->assertSame('danger', $d['status']);
        $this->assertSame(30, $d['suggest_replenish']);
        $this->assertSame(10, $d['current_stock']);
        $this->assertSame(20, $d['safety_stock']);

        // e：20 = 20 → 橙，建议补货 = 20×2 − 20 = 20
        $e = $byId[$seed['e']];
        $this->assertSame('warning', $e['status']);
        $this->assertSame(20, $e['suggest_replenish']);

        // f 超储不入列
        $this->assertFalse($byId->has($seed['f']));
    }

    public function test_expiry_classifies_expired_and_near_expiry(): void
    {
        $seed = $this->seedWarnings();

        $res = $this->getJson('/admin/perf/warning/expiry')
            ->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');

        $this->assertSame(2, $data['total']);
        $byId = collect($data['list'])->keyBy('product_id');

        $h = $byId[$seed['h']];
        $this->assertSame('expired', $h['status']);
        $this->assertSame(-5, $h['remaining_days']);
        $this->assertSame('已过期', $h['status_label']);

        $g = $byId[$seed['g']];
        $this->assertSame('near_expiry', $g['status']);
        $this->assertSame(10, $g['remaining_days']);
        $this->assertSame('临期', $g['status_label']);

        // i 剩 80 天（>60）不入列
        $this->assertFalse($byId->has($seed['i']));
    }

    public function test_sellable_days_three_level_and_recomputable(): void
    {
        $seed = $this->seedWarnings();

        $res = $this->getJson('/admin/perf/warning/sellable-days?days=30')
            ->assertOk()->assertJson(['code' => 200]);
        $byId = collect($res->json('data.list'))->keyBy('product_id');

        // a：可销 = 10 / (30/30) = 10 → 安全（≥7，info 灰）
        $a = $byId[$seed['a']];
        $this->assertSame('safe', $a['status']);
        $this->assertEquals(1.0, $a['daily_30']);         // 日均销量可复算
        $this->assertEquals(10.0, $a['sellable_days']);    // 可销天数可复算
        $this->assertSame('安全', $a['status_label']);

        // b：可销 = 10 / (300/30) = 1 → 告急（<7，warning 橙）
        $b = $byId[$seed['b']];
        $this->assertSame('urgent', $b['status']);
        $this->assertEquals(10.0, $b['daily_30']);
        $this->assertEquals(1.0, $b['sellable_days']);

        // c：库存 0 → 可销 0 → 断档（=0，danger 红）
        $c = $byId[$seed['c']];
        $this->assertSame('out', $c['status']);
        $this->assertEquals(0.0, $c['sellable_days']);
        $this->assertSame('断档', $c['status_label']);

        // 分级 hex：断档红 / 告急橙 / 安全灰
        $this->assertSame('#F56C6C', $c['hex']);
        $this->assertSame('#E6A23E', $b['hex']);
        $this->assertSame('#909399', $a['hex']);
    }

    public function test_overview_4_cards_match_by_type_counts(): void
    {
        $seed = $this->seedWarnings();

        $res = $this->getJson('/admin/perf/warning/overview')
            ->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');

        // 4 卡字段：安全库存预警 / 临期超期 / 可销天数低 / 库存异常
        $this->assertSame(2, $data['safety_stock']);   // d, e（f 超储不入）
        $this->assertSame(2, $data['expiry']);         // g 临期, h 已过期
        // 断档/告急 = b(告急1天) + c(断档0) + g/h/i(无库存无销量→断档) = 5
        $this->assertSame(5, $data['low_sellable']);
        $this->assertSame(0, $data['exception']);
        $this->assertSame(9, $data['total']);          // 2+2+5+0

        // counts 与四类一致
        $this->assertSame($data['safety_stock'], $data['counts']['safety-stock']);
        $this->assertSame($data['expiry'], $data['counts']['expiry']);
        $this->assertSame($data['low_sellable'], $data['counts']['sellable-days']);
        $this->assertSame($data['exception'], $data['counts']['stock-exception']);
    }

    public function test_purchase_draft_returns_suggest_quantity_and_redirect(): void
    {
        $seed = $this->seedWarnings();

        // 安全库存预警里低于安全线的 d（建议 30）转采购
        $res = $this->postJson('/admin/perf/warning/safety-stock/purchase-draft', [
            'items' => [['product_id' => $seed['d'], 'quantity' => 30]],
        ])->assertOk()->assertJson(['code' => 200]);

        $data = $res->json('data');
        $this->assertSame(1, $data['count']);
        $this->assertSame('/business/purchase-application', $data['redirect']);
        $item = $data['items'][0];
        $this->assertSame($seed['d'], $item['product_id']);
        $this->assertSame(30, $item['quantity']);

        // 未传 quantity → 按 安全×2 − 当前 计算
        $res2 = $this->postJson('/admin/perf/warning/safety-stock/purchase-draft', [
            'ids' => [$seed['d']],
        ])->assertOk()->assertJson(['code' => 200]);
        $this->assertSame(30, $res2->json('data.items.0.quantity'));
    }
}
