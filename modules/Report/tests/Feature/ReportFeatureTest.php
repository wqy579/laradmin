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
        $this->assertEquals(5, round((float) $rateCol['threshold']));
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

    public function test_sales_analysis_every_dimension_runs(): void
    {
        // 造业务员 + 促销，覆盖 7 个销售分析维度
        $user = $this->makeAdmin();
        DB::table('employees')->insert([
            'code' => 'EMP'.rand(1000, 9999), 'name' => '业务员甲', 'salesman_code' => 'SM001',
            'user_id' => $user->id, 'role' => 'salesman', 'is_active' => true,
        ]);
        $orderId = $this->makeOrder('2026-10-05', [['product_id' => $this->ids['product'], 'price' => 50, 'qty' => 4]]);
        DB::table('sales_orders')->where('id', $orderId)->update(['salesman_id' => $user->id]);

        $promotionId = DB::table('promotions')->insertGetId([
            'promotion_no' => 'CX202610010001', 'name' => '国庆特惠', 'type' => 'discount',
            'start_time' => '2026-10-01 00:00:00', 'end_time' => '2026-10-31 23:59:59',
            'customer_scope' => 'all', 'priority' => 1, 'status' => 'active',
        ]);
        DB::table('promotion_orders')->insert([
            'promotion_id' => $promotionId, 'order_id' => $orderId,
            'order_no' => DB::table('sales_orders')->where('id', $orderId)->value('order_no'),
            'customer_id' => $this->ids['customer'], 'discount_amount' => 20,
        ]);

        $dims = [
            'salesman_sales', 'product_sales', 'sale_rank', 'special_price',
            'promotion', 'salesman_item_sales', 'salesman_item_coverage',
        ];
        foreach ($dims as $dimension) {
            $res = $this->getJson('admin/business/report/sales-analysis?dimension='.$dimension);
            $res->assertOk("销售分析维度 {$dimension} 查询失败");
            $this->assertNotEmpty($res->json('data.columns'), "维度 {$dimension} 没有返回列定义");
            $this->assertIsArray($res->json('data.list'));
            $this->assertIsArray($res->json('data.summary'));
        }

        // 促销分析能归集到上面造的促销
        $res = $this->getJson('admin/business/report/sales-analysis?dimension=promotion');
        $this->assertSame('国庆特惠', $res->json('data.list.0.promotion_name'), '促销分析未带出促销方案');
    }

    public function test_sales_analysis_sale_rank_marks_top_rank(): void
    {
        $this->makeOrder('2026-10-02', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 2]]);

        $res = $this->getJson('admin/business/report/sales-analysis?dimension=sale_rank');

        $res->assertOk();
        $rankCol = collect($res->json('data.columns'))->firstWhere('prop', 'rank');
        $this->assertSame('rank', $rankCol['type'], '排名列未标记为 rank 徽标类型');
        $this->assertEquals(1, $res->json('data.list.0.rank'), 'TOP1 应标排名 1');
    }

    public function test_sales_analysis_assigns_abc_and_coverage_danger(): void
    {
        $user = $this->makeAdmin();
        DB::table('employees')->insert([
            'code' => 'EMP'.rand(1000, 9999), 'name' => '业务员乙', 'salesman_code' => 'SM002',
            'user_id' => $user->id, 'role' => 'salesman', 'is_active' => true,
        ]);
        $orderId = $this->makeOrder('2026-10-03', [['product_id' => $this->ids['product'], 'price' => 30, 'qty' => 10]]);
        DB::table('sales_orders')->where('id', $orderId)->update(['salesman_id' => $user->id]);

        $res = $this->getJson('admin/business/report/sales-analysis?dimension=product_sales');
        $res->assertOk();
        $abcCol = collect($res->json('data.columns'))->firstWhere('prop', 'abc');
        $this->assertSame('tag', $abcCol['type'], 'ABC 列未标记为 tag 类型');
        $this->assertArrayHasKey('tag_map', $abcCol, 'ABC 列缺少 tag_map');
        $this->assertContains($res->json('data.list.0.abc'), ['A', 'B', 'C'], '商品 ABC 分类应在 A/B/C 中');

        // 铺市率列必须带危险阈值，前端据此 <50% 染红
        $res = $this->getJson('admin/business/report/sales-analysis?dimension=salesman_item_coverage');
        $res->assertOk();
        $covCol = collect($res->json('data.columns'))->firstWhere('prop', 'coverage');
        $this->assertSame('coverage', $covCol['type'], '铺市率列未标记为 coverage 类型');
        $this->assertSame(50, $covCol['danger_below'], '铺市率列未标记危险阈值 50');
    }

    public function test_price_rank_every_dimension_runs(): void
    {
        // 造促销 + 促销明细，覆盖 4 个排行/价格维度
        $promotionId = DB::table('promotions')->insertGetId([
            'promotion_no' => 'JG202610010001', 'name' => '季末特卖', 'type' => 'special_price',
            'start_time' => '2026-10-01 00:00:00', 'end_time' => '2026-10-31 23:59:59',
            'customer_scope' => 'all', 'priority' => 1, 'status' => 'active',
        ]);
        DB::table('promotion_items')->insert([
            'promotion_id' => $promotionId, 'product_id' => $this->ids['product'],
            'product_name' => '季末特卖商品', 'original_price' => 12,
            'discount_rate' => 8.5, 'special_price' => 8,
        ]);
        $this->makeOrder('2026-10-05', [['product_id' => $this->ids['product'], 'price' => 50, 'qty' => 4]]);

        foreach (['sales_rank_total', 'product_price_list', 'customer_special_price', 'promotion_price_list'] as $dimension) {
            $res = $this->getJson('admin/business/report/price-rank?dimension='.$dimension);
            $res->assertOk("排行/价格维度 {$dimension} 查询失败");
            $this->assertNotEmpty($res->json('data.columns'), "维度 {$dimension} 没有返回列定义");
            $this->assertIsArray($res->json('data.list'));
            $this->assertIsArray($res->json('data.summary'));
        }

        // 促销价格一览能带出上面造的促销
        $res = $this->getJson('admin/business/report/price-rank?dimension=promotion_price_list');
        $this->assertSame('季末特卖', $res->json('data.list.0.promotion_name'), '促销价格一览未带出促销方案');
    }

    public function test_price_rank_sales_rank_total_marks_rank(): void
    {
        $this->makeOrder('2026-10-02', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 2]]);

        $res = $this->getJson('admin/business/report/price-rank?dimension=sales_rank_total');

        $res->assertOk();
        $rankCol = collect($res->json('data.columns'))->firstWhere('prop', 'rank');
        $this->assertSame('rank', $rankCol['type'], '排名列未标记为 rank 徽标类型');
        $this->assertEquals(1, $res->json('data.list.0.rank'), 'TOP1 应标排名 1');
        // 毛利 = 售价 100×2 - 成本 5×2 = 190
        $this->assertEquals(190, round((float) $res->json('data.list.0.profit'), 2));
        // 合计行应归集销售金额
        $this->assertEquals(200, round((float) $res->json('data.summary.amount'), 2));
    }

    public function test_price_rank_customer_special_price_resolves_priority(): void
    {
        // 客户等级：默认折扣 9 折（code 避开迁移内置的 LV1~LV4/VIP，避免 UNIQUE 冲突）
        $levelId = DB::table('customer_levels')->insertGetId([
            'name' => 'VVIP', 'code' => 'VVIP', 'default_discount' => 9.0, 'status' => true,
        ]);
        // 客户挂该等级（默认价 12）
        $customer = $this->makeCustomer(['level_id' => $levelId]);
        $customerId = $customer->id;
        // 等级价 9
        DB::table('product_level_prices')->insert([
            'product_id' => $this->ids['product'], 'level_id' => $levelId, 'price' => 9,
        ]);
        // 进行中的特价促销，特价 8
        $promotionId = DB::table('promotions')->insertGetId([
            'promotion_no' => 'TJ202610010001', 'name' => '国庆特价', 'type' => 'special_price',
            'start_time' => '2026-10-01 00:00:00', 'end_time' => '2026-10-31 23:59:59',
            'customer_scope' => 'all', 'priority' => 1, 'status' => 'active',
        ]);
        DB::table('promotion_items')->insert([
            'promotion_id' => $promotionId, 'product_id' => $this->ids['product'],
            'product_name' => '特价商品', 'original_price' => 12, 'special_price' => 8,
        ]);

        $res = $this->getJson('admin/business/report/price-rank?dimension=customer_special_price&customer_ids[]='.$customerId);

        $res->assertOk();
        $row = collect($res->json('data.list'))->firstWhere('customer_id', (int) $customerId);
        $this->assertNotNull($row, '客户特价一览应返回该客户行');
        // 特价 8 < 等级价 9 < 默认价 12 → 有效价取特价 8
        $this->assertEquals(8, round((float) $row['effective_price'], 2), '特价 8 应优先于等级价 9 与默认价 12');
        $this->assertSame('特价', $row['price_source'], '命中来源应为特价');
        $this->assertTrue($row['rule_hit'], '命中特价应标记 rule_hit 供整行高亮');

        // 价格来源列必须是 price_tag 类型并带 hit_type，前端据此染色 + 高亮
        $srcCol = collect($res->json('data.columns'))->firstWhere('prop', 'price_source');
        $this->assertSame('price_tag', $srcCol['type'], '价格来源列未标记为 price_tag 类型');
        $this->assertSame('rule_hit', $srcCol['hit_type'], '价格来源列未标记命中字段');
    }

    public function test_price_rank_default_price_when_no_rules_hit(): void
    {
        // 客户不挂等级、无促销 → 有效价回落默认价 12，不标命中
        $customer = $this->makeCustomer(['level_id' => null]);
        $customerId = $customer->id;

        $res = $this->getJson('admin/business/report/price-rank?dimension=customer_special_price&customer_ids[]='.$customerId);

        $res->assertOk();
        $row = collect($res->json('data.list'))->firstWhere('customer_id', (int) $customerId);
        $this->assertNotNull($row, '客户特价一览应返回该客户行');
        $this->assertEquals(12, round((float) $row['effective_price'], 2), '无命中应回落默认价 12');
        $this->assertSame('默认价', $row['price_source']);
        $this->assertFalse($row['rule_hit'], '默认价不应标记命中');
    }

    public function test_price_rank_customer_level_default_discount_falls_back(): void
    {
        // 客户挂等级（默认折扣 9 折），但商品没有单独等级价 → 等级价回落 price_small(12)×0.9=10.8
        $levelId = DB::table('customer_levels')->insertGetId([
            'name' => 'VVIP9', 'code' => 'VVIP9', 'default_discount' => 9.0, 'status' => true,
        ]);
        $customer = $this->makeCustomer(['level_id' => $levelId]);
        $customerId = $customer->id;
        // 不插 product_level_prices，让等级价走默认折扣计算
        // 也不造促销，避免特价抢跑

        $res = $this->getJson('admin/business/report/price-rank?dimension=customer_special_price&customer_ids[]='.$customerId);

        $res->assertOk();
        $row = collect($res->json('data.list'))->firstWhere('customer_id', (int) $customerId);
        $this->assertNotNull($row, '客户特价一览应返回该客户行');
        // 12 × 0.9 = 10.8
        $this->assertEquals(10.8, round((float) $row['effective_price'], 2), '无单独等级价应回落等级默认折扣价');
        $this->assertEquals(10.8, round((float) $row['level_price'], 2), '等级价列应展示折扣计算值');
        $this->assertSame('等级价', $row['price_source'], '折扣派生价仍算等级价命中');
        $this->assertTrue($row['rule_hit'], '等级价命中应整行高亮');
    }

    public function test_stock_analysis_every_dimension_runs(): void
    {
        // 各维度按需补数据：盘点单 + 拆装单（其余维度基线商品已能出结果）
        $this->seedStockCheck();
        $this->seedAssemblyAndSplit();

        $dimensions = ['expiry', 'safety', 'sellable', 'change', 'detail', 'gain_loss', 'assembly', 'analysis'];

        foreach ($dimensions as $dimension) {
            $res = $this->getJson('admin/business/report/stock-analysis?dimension='.$dimension);
            $res->assertOk("库存分析维度 {$dimension} 查询失败");
            $this->assertNotEmpty($res->json('data.columns'), "维度 {$dimension} 没有返回列定义");
            $this->assertIsArray($res->json('data.list'));
            $this->assertIsArray($res->json('data.summary'));
        }
    }

    public function test_stock_analysis_expiry_assigns_four_levels(): void
    {
        // 严重超期 / 超期 / 临期 各一行（阈值默认 near=30、severe=30）
        $this->makeStockedProduct('EXP_SEV', 100, ['expiry_days' => -100]);
        $this->makeStockedProduct('EXP_OVER', 100, ['expiry_days' => -5]);
        $this->makeStockedProduct('EXP_NEAR', 100, ['expiry_days' => 10]);

        $res = $this->getJson('admin/business/report/stock-analysis?dimension=expiry');

        $res->assertOk();
        $levels = collect($res->json('data.list'))->pluck('level', 'product_code');
        $this->assertSame('严重超期', $levels['EXP_SEV']);
        $this->assertSame('超期', $levels['EXP_OVER']);
        $this->assertSame('临期', $levels['EXP_NEAR']);

        // 级别列必须是 level 类型并带四级 tag_map（前端据此染色）
        $levelCol = collect($res->json('data.columns'))->firstWhere('prop', 'level');
        $this->assertSame('level', $levelCol['type']);
        $this->assertSame('severe', $levelCol['tag_map']['严重超期']['color'], '严重超期应映射到深色 severe');
        $this->assertSame('warning', $levelCol['tag_map']['临期']['color']);
    }

    public function test_stock_analysis_expiry_thresholds_are_configurable(): void
    {
        $this->makeStockedProduct('EXP_CFG', 100, ['expiry_days' => -40]);

        // 默认 severe=30 → -40 属严重超期；放宽到 60 后只剩「超期」
        $strict = $this->getJson('admin/business/report/stock-analysis?dimension=expiry&product_keyword=EXP_CFG');
        $loose = $this->getJson('admin/business/report/stock-analysis?dimension=expiry&product_keyword=EXP_CFG&severe_days=60');

        $this->assertSame('严重超期', $strict->json('data.list.0.level'));
        $this->assertSame('超期', $loose->json('data.list.0.level'), 'severe_days=60 后 -40 不应再判严重超期');
    }

    public function test_stock_analysis_safety_status_and_suggest(): void
    {
        // 现库存 10 < 安全线 50 → 预警；建议补货 = 50×2 − 10 = 90
        $product = $this->makeStockedProduct('SAF_LOW', 10, ['safety_stock' => 50]);
        // 库存高于安全线的对照行 → 正常
        $this->makeStockedProduct('SAF_OK', 100, ['safety_stock' => 20]);

        $res = $this->getJson('admin/business/report/stock-analysis?dimension=safety&product_keyword=SAF_LOW');

        $res->assertOk();
        $row = $res->json('data.list.0');
        $this->assertSame('预警', $row['status']);
        $this->assertEquals(-40, round((float) $row['gap'], 2), '差额 = 现库存 10 − 安全线 50');
        $this->assertEquals(90, (int) $row['suggest_qty']);

        // 「只看预警」应过滤掉正常行（基线商品库存 50 > 安全线，默认不预警）
        $baseline = $this->getJson('admin/business/report/stock-analysis?dimension=safety');
        $this->assertContains('正常', collect($baseline->json('data.list'))->pluck('status'));

        $filtered = $this->getJson('admin/business/report/stock-analysis?dimension=safety&only_warning=1');
        $this->assertNotContains('正常', collect($filtered->json('data.list'))->pluck('status'));
    }

    public function test_stock_analysis_sellable_days_below_seven_is_danger(): void
    {
        // 现库存 100，近 30 天日均出库 20（窗口内共出库 600）→ 可销 5 天，告急
        $product = $this->makeStockedProduct('SELL_DANGER', 100);
        $this->makeStockOuts($product->id, 600, 30);

        // 现库存 400，同流速 → 20 天，正常
        $safe = $this->makeStockedProduct('SELL_SAFE', 400);
        $this->makeStockOuts($safe->id, 600, 30);

        // 有库存、零出库 → 可销天数无定义（null），显示「充足」而非 0 天告急
        $idle = $this->makeStockedProduct('SELL_IDLE', 300);

        $res = $this->getJson('admin/business/report/stock-analysis?dimension=sellable');

        $res->assertOk();
        $rows = collect($res->json('data.list'))->keyBy('product_code');

        $this->assertSame('告急', $rows['SELL_DANGER']['level']);
        $this->assertEquals(5, round((float) $rows['SELL_DANGER']['sellable_days'], 2));
        $this->assertSame('正常', $rows['SELL_SAFE']['level']);
        $this->assertEquals(20, round((float) $rows['SELL_SAFE']['sellable_days'], 2));
        $this->assertNull($rows['SELL_IDLE']['sellable_days'], '零出库商品的平均天数无定义，应为 null');
        $this->assertSame('充足', $rows['SELL_IDLE']['level']);

        // 告急行排最前（升序、null 沉底）
        $codes = collect($res->json('data.list'))->pluck('product_code')->all();
        $this->assertSame('SELL_DANGER', $codes[0]);
        $this->assertGreaterThan(array_search('SELL_SAFE', $codes), array_search('SELL_IDLE', $codes));

        // 列定义带 danger_below 阈值，前端据此染色
        $daysCol = collect($res->json('data.columns'))->firstWhere('prop', 'sellable_days');
        $this->assertEquals(7, $daysCol['danger_below']);
    }

    public function test_stock_analysis_gain_loss_direction_colors(): void
    {
        $this->seedStockCheck();

        $res = $this->getJson('admin/business/report/stock-analysis?dimension=gain_loss');

        $res->assertOk();
        $rows = collect($res->json('data.list'))->keyBy('product_code');

        // 盘盈商品：diff_qty>0 → gain_qty 记正、loss_qty 为 0
        $this->assertEquals(5, round((float) $rows['GL_GAIN']['gain_qty'], 2));
        $this->assertEquals(0, round((float) $rows['GL_GAIN']['loss_qty'], 2));
        // 盘亏商品：diff_qty<0 → loss_qty 记绝对值
        $this->assertEquals(3, round((float) $rows['GL_LOSS']['loss_qty'], 2));
        $this->assertEquals(0, round((float) $rows['GL_LOSS']['gain_qty'], 2));

        // 方向色：盘盈红(ru-gain) / 盘亏绿(rp-loss)，与「增加=绿」常规语义相反（E5 规范）
        $gainCol = collect($res->json('data.columns'))->firstWhere('prop', 'gain_qty');
        $lossCol = collect($res->json('data.columns'))->firstWhere('prop', 'loss_qty');
        $this->assertSame('rp-gain', $gainCol['cell_class']);
        $this->assertSame('rp-loss', $lossCol['cell_class']);

        // 净差异 = +5 −3 = +2
        $this->assertEquals(2, round((float) $res->json('data.summary.net_qty'), 2));
    }

    public function test_stock_analysis_assembly_unions_both_order_types(): void
    {
        $this->seedAssemblyAndSplit();

        $res = $this->getJson('admin/business/report/stock-analysis?dimension=assembly');

        $res->assertOk();
        $types = collect($res->json('data.list'))->pluck('biz_type');
        $this->assertContains('组装', $types);
        $this->assertContains('拆分', $types);
        $this->assertNotEmpty($res->json('data.list.0.order_no'), '拆装单应带出单据号');
    }

    public function test_stock_analysis_detail_amounts_and_export(): void
    {
        $res = $this->getJson('admin/business/report/stock-analysis?dimension=detail');

        $res->assertOk();
        $row = collect($res->json('data.list'))->firstWhere('product_code', $this->ids['product_code']);
        $this->assertNotNull($row, '库存明细应带出基线商品');
        // 数量 50 × 成本 5 = 250；零售 50 × 12 = 600
        $this->assertEquals(250, round((float) $row['cost_amount'], 2));
        $this->assertEquals(600, round((float) $row['retail_amount'], 2));

        $export = $this->get('admin/business/report/stock-analysis/export?dimension=detail');
        $export->assertOk();
        $this->assertStringContainsString('ms-excel', strtolower($export->headers->get('content-type') ?? ''));
        $this->assertStringContainsString('商品名称', $export->getContent());
    }

    /** 建一个带库存的商品：expiry_days 为相对今天的有效期偏移（负=已过期） */
    private function makeStockedProduct(string $code, int $stockQty, array $attrs = []): object
    {
        $expiryDays = $attrs['expiry_days'] ?? null;
        unset($attrs['expiry_days']);
        // safety_stock / expiry_date 不在 Product fillable 里，单独走 DB 更新
        $safetyStock = $attrs['safety_stock'] ?? null;
        unset($attrs['safety_stock']);

        $product = $this->makeProduct(array_merge([
            'code' => $code,
            'cost_price' => 5,
            'price_small' => 12,
            'price_unit_small' => '瓶',
        ], $attrs));

        $update = [];
        if ($expiryDays !== null) {
            $update['expiry_date'] = now()->addDays($expiryDays)->toDateString();
            $update['shelf_life_days'] = 180;
        }
        if ($safetyStock !== null) {
            $update['safety_stock'] = $safetyStock;
        }
        if ($update) {
            DB::table('products')->where('id', $product->id)->update($update);
        }

        DB::table('stocks')->insert([
            'product_id' => $product->id, 'warehouse_id' => $this->ids['warehouse'],
            'quantity' => $stockQty, 'frozen_qty' => 0, 'cost_price' => 5,
            'total_amount' => $stockQty * 5, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    /** 造 window 天前的负向出库流水，累计 outQty，喂给可销天数 */
    private function makeStockOuts(int $productId, int $outQty, int $window): void
    {
        DB::table('stocks_history')->insert([
            'product_id' => $productId, 'warehouse_id' => $this->ids['warehouse'],
            'change_type' => 'sale', 'change_qty' => -$outQty,
            'before_qty' => $outQty, 'after_qty' => 0,
            'created_at' => now()->subDays(max(1, (int) floor($window / 2))),
            'updated_at' => now(),
        ]);
    }

    /** 一张已审核盘点单 + 一盈一亏两行明细 */
    private function seedStockCheck(): void
    {
        $checkId = DB::table('stock_checks')->insertGetId([
            'check_no' => 'PD'.date('Ymd').rand(1000, 9999),
            'warehouse_id' => $this->ids['warehouse'],
            'check_date' => now()->toDateString(),
            'check_type' => 'full', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([['GL_GAIN', 5], ['GL_LOSS', -3]] as [$code, $diff]) {
            $product = $this->makeStockedProduct($code, 100);
            DB::table('stock_check_items')->insert([
                'check_id' => $checkId, 'product_id' => $product->id,
                'product_code' => $code, 'product_name' => $code,
                'spec' => '1*1', 'unit' => '瓶',
                'book_qty' => 100, 'actual_qty' => 100 + $diff,
                'diff_qty' => $diff, 'cost_price' => 5, 'diff_amount' => $diff * 5,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** 已审核组装单 + 拆分单各一张 */
    private function seedAssemblyAndSplit(): void
    {
        $parent = $this->makeStockedProduct('ASM_PARENT', 10);

        DB::table('assembly_orders')->insert([
            'assembly_no' => 'ZC'.date('Ymd').rand(1000, 9999),
            'parent_product_id' => $parent->id,
            'parent_product_code' => 'ASM_PARENT', 'parent_product_name' => 'ASM_PARENT',
            'warehouse_id' => $this->ids['warehouse'],
            'quantity' => 10, 'total_cost' => 50, 'unit_cost' => 5,
            'status' => 'approved', 'assembly_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('split_orders')->insert([
            'split_no' => 'CF'.date('Ymd').rand(1000, 9999),
            'parent_product_id' => $parent->id,
            'parent_product_code' => 'ASM_PARENT', 'parent_product_name' => 'ASM_PARENT',
            'warehouse_id' => $this->ids['warehouse'],
            'quantity' => 5, 'total_cost' => 25,
            'status' => 'approved', 'split_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
