<?php

namespace Tests\Report\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 报表模块接口冒烟。
 *
 * 这些用例的价值不在断言业务数字，而在「SQL 真的能跑」：
 * 报表全是聚合查询 + 多表 join + 一堆可选筛选，SQLite 与 MySQL 的方言差异
 * （比如 CAST、whereJsonContains、union 后的 order by）最容易在这里炸出来，
 * 单元测试碰不到，页面上一翻页才 500。
 */
class ReportFeatureTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->seedReportData();
    }

    public function test_recent_price_takes_latest_sale_of_each_product(): void
    {
        // 给同一个商品插两笔不同日期的成交，最近价格必须取日期大的那笔
        $product = $this->ids['product'];
        $this->makeOrder('2026-09-01', [['product_id' => $product, 'price' => 10, 'qty' => 2]]);
        $this->makeOrder('2026-10-02', [['product_id' => $product, 'price' => 12, 'qty' => 3]]);

        $res = $this->getJson('admin/business/recent-prices?product_code='.$this->ids['product_code']);

        $res->assertOk();
        $row = $res->json('data.list.0');
        $this->assertSame('2026-10-02', $row['last_sale_date']);
        $this->assertEquals(12, (float) $row['price']);
    }

    public function test_sales_report_every_dimension_runs(): void
    {
        $dimensions = [
            'product_detail', 'customer', 'customer_product', 'customer_category_product',
            'product', 'warehouse_product', 'brand', 'customer_category_sale_type',
            'customer_subcategory_sale_type', 'customer_product_sale_type',
            'product_sale_type', 'doc',
        ];

        foreach ($dimensions as $dimension) {
            $res = $this->getJson('admin/business/report/sales?dimension='.$dimension);
            $res->assertOk("销售报表维度 {$dimension} 查询失败");
            $this->assertNotEmpty($res->json('data.columns'), "维度 {$dimension} 没有返回列定义");
            $this->assertIsArray($res->json('data.list'));
            $this->assertIsArray($res->json('data.summary'));
        }
    }

    public function test_sales_report_help_tab_returns_content(): void
    {
        $res = $this->getJson('admin/business/report/sales?dimension=help');

        $res->assertOk();
        $this->assertNotEmpty($res->json('data.help.steps'));
    }

    public function test_sales_report_zero_sale_only_shows_unsold_products(): void
    {
        $soldProduct = $this->ids['product'];
        $unsoldProduct = DB::table('products')->insertGetId([
            'name' => '未销售商品', 'code' => 'UNSOLD01', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->makeOrder('2026-10-02', [['product_id' => $soldProduct, 'price' => 12, 'qty' => 3]]);

        $res = $this->getJson('admin/business/report/sales?dimension=product&zero_sale=only&product_ids[]='.$unsoldProduct);

        $res->assertOk();
        $codes = array_column($res->json('data.list'), 'product_code');
        $this->assertContains('UNSOLD01', $codes);
    }

    public function test_stock_report_dimensions_and_zero_filter(): void
    {
        foreach (['product_detail', 'product', 'warehouse_product', 'brand'] as $dimension) {
            $res = $this->getJson('admin/business/report/stock?dimension='.$dimension);
            $res->assertOk("库存报表维度 {$dimension} 查询失败");
            $this->assertNotEmpty($res->json('data.columns'));
        }

        // 零库存商品默认不带出，勾上「统计为0商品」才出现。
        // 注意 stocks 有 (product_id, warehouse_id) 唯一键，必须用一个全新的商品。
        $zeroProduct = DB::table('products')->insertGetId([
            'name' => '零库存商品', 'code' => 'ZERO01', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stocks')->insert([
            'product_id' => $zeroProduct, 'warehouse_id' => $this->ids['warehouse'],
            'quantity' => 0, 'frozen_qty' => 0, 'cost_price' => 5, 'total_amount' => 0,
        ]);

        $without = $this->getJson('admin/business/report/stock?dimension=product_detail');
        $with = $this->getJson('admin/business/report/stock?dimension=product_detail&include_zero=1');

        $without->assertOk();
        $with->assertOk();

        $this->assertNotContains('ZERO01', array_column($without->json('data.list'), 'product_code'));
        $this->assertContains('ZERO01', array_column($with->json('data.list'), 'product_code'));
    }

    public function test_salesman_report_returns_profit_and_return_amount(): void
    {
        $this->makeOrder('2026-10-02', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 2]]);

        $res = $this->getJson('admin/business/report/salesman?dimension=salesman');

        $res->assertOk();
        $row = $res->json('data.list.0');
        $this->assertArrayHasKey('profit', $row);
        $this->assertArrayHasKey('return_amount', $row);
        $this->assertArrayHasKey('net_amount', $row);
        // 售价 100 × 2 - 成本 5 × 2 = 190
        $this->assertEquals(190, round((float) $row['profit'], 2));
    }

    public function test_combined_report_returns_overview_cards(): void
    {
        $res = $this->getJson('admin/business/report/combined?dimension=sales');

        $res->assertOk();
        $this->assertCount(4, $res->json('data.overview'));
        $this->assertSame('今日销售额', $res->json('data.overview.0.title'));
    }

    public function test_options_endpoint_returns_filter_sources(): void
    {
        $res = $this->getJson('admin/business/report/options');

        $res->assertOk();
        $this->assertNotEmpty($res->json('data.date_types'));
        $this->assertNotEmpty($res->json('data.warehouses'));
        $this->assertNotEmpty($res->json('data.sale_types'));
    }

    public function test_report_template_can_be_saved_and_listed(): void
    {
        $this->postJson('admin/business/report/templates', [
            'report' => 'sales',
            'name' => '上月销售',
            'conditions' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
        ])->assertOk();

        $res = $this->getJson('admin/business/report/templates?report=sales');

        $res->assertOk();
        $this->assertSame('上月销售', $res->json('data.0.name'));
    }

    public function test_report_endpoints_require_auth(): void
    {
        $this->withHeaders(['Authorization' => ''])->getJson('admin/business/report/sales')->assertStatus(401);
    }

    // ==================== 数据准备 ====================

    private function seedReportData(): void
    {
        $product = $this->makeProduct(['cost_price' => 5, 'price_small' => 12, 'price_unit_small' => '瓶']);
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();

        DB::table('products')->where('id', $product->id)->update(['brand_id' => null]);

        DB::table('stocks')->insert([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'quantity' => 50, 'frozen_qty' => 0, 'cost_price' => 5, 'total_amount' => 250,
        ]);

        $this->ids = [
            'product' => $product->id,
            'product_code' => $product->code,
            'customer' => $customer->id,
            'warehouse' => $warehouse->id,
        ];
    }

    private function makeOrder(string $date, array $items): int
    {
        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO'.str_replace('-', '', $date).rand(1000, 9999),
            'order_type' => 'normal',
            'source' => 'admin',
            'customer_id' => $this->ids['customer'],
            'warehouse_id' => $this->ids['warehouse'],
            'order_date' => $date,
            'total_amount' => 0,
            'total_qty' => 0,
            'paid_amount' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($items as $item) {
            DB::table('sales_order_items')->insert([
                'sales_order_id' => $orderId,
                'product_id' => $item['product_id'],
                'quantity' => $item['qty'],
                'price' => $item['price'],
                'price_small' => $item['price'],
                'amount' => $item['qty'] * $item['price'],
                'sale_mode' => 'normal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $orderId;
    }
}
