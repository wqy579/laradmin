<?php

namespace Tests\Stock\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

/**
 * 成本价格：大小单位切换 + 动态换算
 *
 * 口径（与 Product 模型 spec_display / SalesOrderCreateFeatureTest 一致）：
 *   unit_conversion  = 大→小 换算率（1件 = N个，如 480）
 *   unit_conversion_medium = 中→小 换算率（1盒 = N个，如 120）
 *   products.cost_price 始终以【最小单位】存储，是唯一真相源。
 *
 * 本组测试守住：前端「切到最大单位」提交的大单位成本价，必须按各商品自身
 *   unit_conversion 折算回最小单位存储；库存成本价/金额同步同口径。
 */
class CostPriceFeatureTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------- 单个更新

    /** 不带 unit：按最小单位原值存储（向后兼容旧客户端）。 */
    public function test_update_defaults_to_small_unit_storage(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct(['unit_conversion' => 480, 'cost_price' => 0]);
        $stock = $this->makeStock($product, 100);

        $response = $this->putJson("/admin/business/cost-price/{$product->id}", ['cost_price' => 0.5]);

        $response->assertStatus(200)->assertJsonPath('code', 200);
        $this->assertSame(0.5, (float) $product->fresh()->cost_price);
        $this->assertSame(0.5, (float) $stock->fresh()->cost_price);
        $this->assertSame(50.0, (float) $stock->fresh()->total_amount);
    }

    /** unit=large：大单位成本价 ÷ unit_conversion（大→小）= 最小单位成本价。 */
    public function test_update_converts_large_unit_to_small_unit(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct(['unit_conversion' => 480, 'cost_price' => 0]);
        $stock = $this->makeStock($product, 100);

        // 1件 = 480个；大单位成本 240 元/件 → 0.5 元/个
        $response = $this->putJson("/admin/business/cost-price/{$product->id}", [
            'cost_price' => 240,
            'unit' => 'large',
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 200);
        $this->assertSame(0.5, (float) $product->fresh()->cost_price);
        // 库存成本价与库存金额同口径（100 个 × 0.5 元 = 50 元）
        $this->assertSame(0.5, (float) $stock->fresh()->cost_price);
        $this->assertSame(50.0, (float) $stock->fresh()->total_amount);
    }

    /** unit=large 但商品无换算关系：回退按最小单位原值存储。 */
    public function test_update_large_without_conversion_falls_back_to_small(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct(['unit_conversion' => null, 'cost_price' => 0]);
        $stock = $this->makeStock($product, 50);

        $response = $this->putJson("/admin/business/cost-price/{$product->id}", [
            'cost_price' => 7.25,
            'unit' => 'large',
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 200);
        $this->assertSame(7.25, (float) $product->fresh()->cost_price);
        $this->assertSame(7.25, (float) $stock->fresh()->cost_price);
    }

    /** 非法 unit 值被校验拦下，不写入。 */
    public function test_update_rejects_invalid_unit(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct(['unit_conversion' => 480, 'cost_price' => 1]);

        $response = $this->putJson("/admin/business/cost-price/{$product->id}", [
            'cost_price' => 240,
            'unit' => 'box',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1.0, (float) $product->fresh()->cost_price);
    }

    // ---------------------------------------------------------- 批量设置

    /** 不带 unit：最小单位统一值。 */
    public function test_batch_small_unit_sets_uniform_small_cost(): void
    {
        $this->actingAsAdmin();
        $p1 = $this->makeProduct(['unit_conversion' => 480]);
        $p2 = $this->makeProduct(['unit_conversion' => 240]);
        $s1 = $this->makeStock($p1, 10);
        $s2 = $this->makeStock($p2, 10);

        $response = $this->postJson('/admin/business/cost-price/batch', [
            'ids' => [$p1->id, $p2->id],
            'cost_price' => 0.5,
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 200);
        $this->assertSame(0.5, (float) $p1->fresh()->cost_price);
        $this->assertSame(0.5, (float) $p2->fresh()->cost_price);
        $this->assertSame(5.0, (float) $s1->fresh()->total_amount);
        $this->assertSame(5.0, (float) $s2->fresh()->total_amount);
    }

    /**
     * unit=large：各商品按自身 unit_conversion 折算，不同换算率得到不同小单位成本。
     * 这是「批量同一大单位价」在大/小单位共存商品上的正确语义。
     */
    public function test_batch_large_unit_converts_per_product_conversion(): void
    {
        $this->actingAsAdmin();
        // 1件=480个，1件=240个
        $p1 = $this->makeProduct(['unit_conversion' => 480]);
        $p2 = $this->makeProduct(['unit_conversion' => 240]);
        $s1 = $this->makeStock($p1, 10);
        $s2 = $this->makeStock($p2, 10);

        // 批量设 480 元/件
        $response = $this->postJson('/admin/business/cost-price/batch', [
            'ids' => [$p1->id, $p2->id],
            'cost_price' => 480,
            'unit' => 'large',
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 200);
        $this->assertSame(1.0, (float) $p1->fresh()->cost_price); // 480/480
        $this->assertSame(2.0, (float) $p2->fresh()->cost_price); // 480/240
        $this->assertSame(10.0, (float) $s1->fresh()->total_amount); // 10×1.0
        $this->assertSame(20.0, (float) $s2->fresh()->total_amount); // 10×2.0
    }

    // ---------------------------------------------------------- 列表返回换算字段

    /** 列表需带出 unit_conversion / 单位名 / 换算展示，前端据此动态计算大单位成本。 */
    public function test_index_exposes_unit_conversion_fields(): void
    {
        $this->actingAsAdmin();
        $this->makeProduct([
            'name' => '矿泉水 550ml',
            'unit_conversion' => 480,
            'unit_conversion_medium' => 120,
            'price_unit' => '件',
            'price_unit_small' => '瓶',
            'barcode_medium_unit' => '盒',
            'cost_price' => 0.5,
        ]);

        $response = $this->getJson('/admin/business/cost-price');

        $response->assertStatus(200)->assertJsonPath('code', 200);
        $item = $response->json('data.list.0');
        $this->assertSame('480', (string) $item['unit_conversion']);
        $this->assertSame('件', $item['price_unit']);
        $this->assertSame('瓶', $item['price_unit_small']);
        $this->assertSame(0.5, (float) $item['cost_price']);
        $this->assertSame('1件=4盒=480瓶', $item['conversion_display']);
    }

    // ---------------------------------------------------------- 辅助

    private function makeStock(Product $product, int $quantity): Stock
    {
        $warehouse = Warehouse::create([
            'code' => 'WH'.strtoupper(\Illuminate\Support\Str::random(6)),
            'name' => '测试仓库',
            'is_active' => true,
        ]);

        return Stock::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'cost_price' => 0,
            'total_amount' => 0,
        ]);
    }
}
