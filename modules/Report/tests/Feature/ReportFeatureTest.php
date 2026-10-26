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

    /**
     * 生产事故回归：业务员报表在有退货数据时全维度 500
     * Undefined property: stdClass::$total_amount。
     *
     * 根因在 returnAmounts() 的 pluck(DB::raw('SUM(sr.total_amount)'))：
     * Builder::stripTableForPluck 取「最后一个 . 之后」作为行属性名，
     * SUM(sr.total_amount) 含一个 . 会被切成 total_amount)（带右括号），
     * 在结果行上取该属性直接抛 Undefined property。
     *
     * 它此前逃过所有测试，两个原因叠加：
     *   1. 本用例之前从未造过退货数据，pluck 返回空 Collection 直接短路返回，
     *      取值键根本不会被访问——测试库「没有退货单」时 bug 完全不可见；
     *   2. summary() 对**所有**维度无条件调用 returnAmounts()，
     *      所以不是只有「按业务员」维度炸，而是 5 个维度一起 500，
     *      与 dimension 参数无关。
     *
     * 断言同时校验「能跑」和「数值对」：只断 assertOk 挡不住取值键错位
     * 但返回 0 这类静默错值，必须校验 net_amount 真的扣掉了退货金额。
     */
    public function test_salesman_report_runs_when_returns_exist(): void
    {
        // 指派业务员并出单：售价 100 × 3 = 300，成本 5 × 3 = 15
        $user = $this->makeAdmin();
        $orderId = $this->makeOrder('2026-10-05', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 3]]);
        DB::table('sales_orders')->where('id', $orderId)->update(['salesman_id' => $user->id]);

        // 真实退货：退回 1 件，退货金额 100，状态为「真实退货」（非 cancelled/auto_return）
        DB::table('sales_returns')->insert([
            'return_no' => 'TH'.str_replace('-', '', '2026-10-06').'0001',
            'order_id' => $orderId,
            'customer_id' => $this->ids['customer'],
            'warehouse_id' => $this->ids['warehouse'],
            'return_date' => '2026-10-06',
            'return_type' => 'quality',
            'status' => 'approved',
            'total_qty' => 1,
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5 个维度必须都能跑通（bug 与 dimension 无关，summary() 全维度都会查退货）
        foreach (['salesman', 'salesman_customer', 'salesman_product', 'salesman_customer_product', 'salesman_brand_product'] as $dimension) {
            $res = $this->getJson("admin/business/report/salesman?dimension={$dimension}");
            $res->assertOk("维度 {$dimension} 有退货数据时 500");
        }

        // 数值校验：销售 300 - 退货 100 = 净销售 200，合计行必须真的扣掉退货
        $res = $this->getJson('admin/business/report/salesman?dimension=salesman');
        $summary = $res->json('data.summary');

        $this->assertEquals(300, round((float) $summary['amount'], 2));
        $this->assertEquals(100, round((float) $summary['return_amount'], 2), '退货金额未归集到业务员名下');
        $this->assertEquals(200, round((float) $summary['net_amount'], 2), '净销售未扣减退货金额');
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

    public function test_customer_report_every_dimension_runs(): void
    {
        // 造两笔销售：一笔 2026-09（2×100=200），一笔 2026-10（3×12=36）
        $this->makeOrder('2026-09-05', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 2]]);
        $this->makeOrder('2026-10-02', [['product_id' => $this->ids['product'], 'price' => 12, 'qty' => 3]]);

        foreach (['order_detail', 'shipped_uncollected', 'return_rate_mom', 'customer_sales', 'customer_order', 'return_summary'] as $dimension) {
            $res = $this->getJson('admin/business/report/customer?dimension='.$dimension);
            $res->assertOk("客户报表维度 {$dimension} 查询失败");
            $this->assertNotEmpty($res->json('data.columns'), "维度 {$dimension} 没有返回列定义");
            $this->assertIsArray($res->json('data.list'));
            $this->assertIsArray($res->json('data.summary'));
        }
    }

    public function test_customer_report_shipped_uncollected_marks_overdue(): void
    {
        // 已发货且未收齐的订单：金额 100，已收 0，发货日期 120 天前 → 未收天数 120（> 60 红）
        $orderId = $this->makeOrder('2026-05-01', [['product_id' => $this->ids['product'], 'price' => 50, 'qty' => 2]]);
        DB::table('sales_orders')->where('id', $orderId)->update([
            'dispatch_date' => '2026-05-05',
            'delivery_date' => '2026-05-06',
            'total_amount' => 100,
            'paid_amount' => 0,
        ]);
        DB::table('deliveries')->insert([
            'order_id' => $orderId,
            'status' => 1,
            'delivery_no' => 'DEL'.$orderId.'20260505',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->getJson('admin/business/report/customer?dimension=shipped_uncollected&as_of_date=2026-10-27');

        $res->assertOk();
        $row = $res->json('data.list.0');
        $this->assertNotEmpty($row, '发货未收款应有数据');
        $this->assertEquals(100, round((float) $row['unpaid_amount'], 2));
        // 未收天数列定义必须带逾期阈值，前端据此染红
        $ageCol = collect($res->json('data.columns'))->firstWhere('prop', 'age_days');
        $this->assertSame(60, $ageCol['overdue_threshold'], '未收天数列未标记逾期阈值');
        $this->assertGreaterThan(60, (float) $row['age_days'], '逾期订单未收天数应 > 60');
        // 合计行应归集逾期未收
        $this->assertEquals(100, round((float) $res->json('data.summary.overdue_unpaid_amount'), 2));
    }

    public function test_customer_report_return_rate_mom_computes_mom_change(): void
    {
        // 9 月销售 200，退货 0 → 退货率 0；10 月销售 100，退货 50 → 退货率 50，环比 +50
        $this->makeOrder('2026-09-05', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 2]]);
        $this->makeOrder('2026-10-02', [['product_id' => $this->ids['product'], 'price' => 50, 'qty' => 2]]);
        $this->makeReturn('2026-10-06', 50);

        $res = $this->getJson('admin/business/report/customer?dimension=return_rate_mom&return_rate_threshold=5');

        $res->assertOk();
        $rows = collect($res->json('data.list'));
        $oct = $rows->firstWhere('period', '2026-10');
        $this->assertNotNull($oct, '缺少 2026-10 月份行');
        $this->assertEquals(50, round((float) $oct['return_rate'], 2), '10 月退货率应为 50%');
        $this->assertEquals(50, round((float) $oct['mom_change'], 2), '10 月环比应为 +50%');
        // 退货率列定义需带阈值，环比列需带上涨箭头标记，前端据此标红 + 箭头
        $rateCol = collect($res->json('data.columns'))->firstWhere('prop', 'return_rate');
        $this->assertSame(5, (float) $rateCol['threshold']);
        $momCol = collect($res->json('data.columns'))->firstWhere('prop', 'mom_change');
        $this->assertTrue($momCol['arrow'] ?? false, '环比列应带上涨箭头标记');
    }

    public function test_customer_report_drill_returns_customer_orders(): void
    {
        $orderId = $this->makeOrder('2026-10-02', [['product_id' => $this->ids['product'], 'price' => 12, 'qty' => 3]]);

        $res = $this->getJson('admin/business/report/customer/drill?customer_id='.$this->ids['customer']);

        $res->assertOk();
        $this->assertEquals($this->ids['customer'], (int) $res->json('data.header.customer_id'));
        $orders = $res->json('data.items');
        $this->assertNotEmpty($orders, '该客户应有订单');
        $this->assertSame(
            DB::table('sales_orders')->where('id', $orderId)->value('order_no'),
            $orders[0]['order_no']
        );
        $this->assertNotEmpty($res->json('data.columns'), '穿透抽屉应返回列定义');
    }

    public function test_customer_report_drill_requires_identifier(): void
    {
        $this->getJson('admin/business/report/customer/drill')->assertStatus(422);
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

    private function makeReturn(string $date, float $amount): int
    {
        $id = DB::table('sales_returns')->insertGetId([
            'return_no' => 'TH'.str_replace('-', '', $date).rand(1000, 9999),
            'order_id' => null,
            'customer_id' => $this->ids['customer'],
            'warehouse_id' => $this->ids['warehouse'],
            'return_date' => $date,
            'return_type' => 'quality',
            'status' => 'approved',
            'total_qty' => 1,
            'total_amount' => $amount,
            'refund_amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
