<?php

namespace Tests\Feature\Business;

use App\Exceptions\BusinessRuleException;
use App\Models\Business\Product;
use App\Models\Business\Warehouse;
use App\Services\Business\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 库存核心服务测试（模块化第一轮：测试先行）
 *
 * 覆盖范围：入库累加 / 出库扣减 / 库存不足 / 列表筛选 / 统计。
 * 其中 test_stock_in_returns_numeric_quantity 是回归测试，
 * 锁定「返回值必须是真实数字」——旧实现用 DB::raw 会让它变成 {} 或抛 SQL 错。
 *
 * 运行前提：需要 MySQL 测试库（迁移含 MySQL 专有语法，SQLite 无法执行）：
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=laradmin_test \
 *   php artisan test --testsuite=Feature
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stocks;
    private Product $product;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stocks = new StockService();
        $this->product = Product::create(['name' => '测试商品A']);
        $this->warehouse = Warehouse::create(['code' => uniqid('W'), 'name' => '测试仓库']);
    }

    public function test_stock_in_creates_row_when_absent(): void
    {
        $stock = $this->stocks->stockIn($this->product->id, $this->warehouse->id, 10);

        $this->assertDatabaseHas('stocks', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);
        $this->assertSame(10, (int) $stock->quantity);
    }

    public function test_stock_in_accumulates_existing_quantity(): void
    {
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 10);
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 5);

        // 唯一索引保证同一商品+仓库只有一行，而不是插两条
        $this->assertDatabaseCount('stocks', 1);
        $this->assertDatabaseHas('stocks', ['quantity' => 15]);
    }

    public function test_stock_in_updates_cost_price_when_provided(): void
    {
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 3, 12.5);

        $this->assertDatabaseHas('stocks', ['quantity' => 3, 'cost_price' => 12.5]);
    }

    public function test_stock_out_decreases_quantity(): void
    {
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 10);

        $stock = $this->stocks->stockOut($this->product->id, $this->warehouse->id, 4);

        $this->assertSame(6, (int) $stock->quantity);
        $this->assertDatabaseHas('stocks', ['quantity' => 6]);
    }

    public function test_stock_out_fails_when_insufficient(): void
    {
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 2);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('库存不足');

        $this->stocks->stockOut($this->product->id, $this->warehouse->id, 5);
    }

    public function test_stock_out_fails_when_no_stock_row(): void
    {
        $this->expectException(BusinessRuleException::class);

        $this->stocks->stockOut($this->product->id, $this->warehouse->id, 1);
    }

    public function test_insufficient_stock_out_rolls_back_quantity(): void
    {
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 2);

        try {
            $this->stocks->stockOut($this->product->id, $this->warehouse->id, 5);
        } catch (BusinessRuleException) {
            // 期望抛出
        }

        // 失败出库不得改动库存
        $this->assertDatabaseHas('stocks', ['quantity' => 2]);
    }

    /** 回归：返回值必须是真实数字，不能是 DB::raw 表达式对象 */
    public function test_stock_in_returns_numeric_quantity(): void
    {
        $stock = $this->stocks->stockIn($this->product->id, $this->warehouse->id, 7);

        $this->assertIsNumeric($stock->quantity);
        $this->assertSame(7, (int) $stock->quantity);
        $this->assertSame(7, (int) $stock->fresh()->quantity);
    }

    public function test_query_filters_by_product_and_warehouse(): void
    {
        $other = Warehouse::create(['code' => uniqid('W'), 'name' => '另一仓库']);
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 1);
        $this->stocks->stockIn($this->product->id, $other->id, 2);

        $result = $this->stocks->query(
            Request::create('/', 'GET', ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id])
        );

        $this->assertCount(1, $result['data']->items());
        $this->assertSame(1, (int) $result['data']->items()[0]->quantity);
        $this->assertTrue($result['products']->contains('id', $this->product->id));
        $this->assertCount(2, $result['warehouses']);
    }

    public function test_query_returns_all_when_no_filter(): void
    {
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 1);

        $result = $this->stocks->query(Request::create('/', 'GET', ['per_page' => 20]));

        $this->assertCount(1, $result['data']->items());
        $this->assertSame(1, $result['data']->total());
    }

    public function test_statistics_aggregates_quantity_and_low_stock(): void
    {
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 3, 10.5);
        $this->stocks->stockIn($this->product->id, $this->warehouse->id, 2);

        $stats = $this->stocks->statistics();

        $this->assertSame(1, $stats['stats']['total_products']);
        $this->assertSame(5, $stats['stats']['total_quantity']);
        $this->assertSame(1, $stats['stats']['low_stock_count']);
        $this->assertCount(1, $stats['top_products']);
        $this->assertSame('测试商品A', $stats['top_products'][0]->product->name);
    }
}
