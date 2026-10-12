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

    // ==================== 连凯对标四核心报表 ====================

    /**
     * 销售日报：固定版式单日快照。
     *
     * 与 sales?dimension=doc 的区别是固定列 + 固定汇总卡 + 单日筛选，
     * 所以这里按单据日期精确取一天，校验 4 张汇总卡与明细口径一致。
     */
    public function test_daily_sales_report_returns_cards_and_rows(): void
    {
        // 同日两单：一单正常、一单不同客户，用于校验订单数/客户数去重
        $customer2 = $this->makeCustomer();
        $this->makeOrder('2026-10-08', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 2]]);
        $this->makeOrderForCustomer('2026-10-08', $customer2->id, [['product_id' => $this->ids['product'], 'price' => 50, 'qty' => 1]]);

        $res = $this->getJson('admin/business/report/daily?date=2026-10-08');

        $res->assertOk('销售日报查询失败');
        $this->assertNotEmpty($res->json('data.columns'), '销售日报没有列定义');

        $summary = $res->json('data.summary');
        // 200 + 50 = 250；2 单；2 客户；客单价 250/2 = 125
        $this->assertEquals(250, round((float) $summary['amount'], 2), '销售额未按当日汇总');
        $this->assertSame(2, (int) $summary['order_count'], '订单数未按单据去重');
        $this->assertSame(2, (int) $summary['customer_count'], '客户数未按客户去重');
        $this->assertEquals(125, round((float) $summary['avg_price'], 2), '客单价 = 销售额 / 订单数');
        // 成本 5×3 = 15，毛利 250 - 15 = 235
        $this->assertEquals(235, round((float) $summary['profit'], 2), '毛利 = 销售额 - 成本');
        $this->assertCount(2, $res->json('data.list'), '明细应按单据展开为一单一行');
    }

    /**
     * 销售月报：整月逐日趋势 + 环比增长。
     *
     * 重点校验：环比按「当日相对前一日」计算、首日无环比为 null、
     * 行按日期升序且无重复。后端只下发文字 growth_label，颜色由前端按 growth_rate 判，
     * 所以断言 rate 的语义而不是文案。
     */
    public function test_monthly_sales_report_returns_daily_trend_and_growth(): void
    {
        // 10-01 与 10-03 各一单，中间 10-02 无销售
        $this->makeOrder('2026-10-01', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 1]]);
        $this->makeOrder('2026-10-03', [['product_id' => $this->ids['product'], 'price' => 300, 'qty' => 1]]);

        $res = $this->getJson('admin/business/report/monthly?month=2026-10');

        $res->assertOk('销售月报查询失败');
        $this->assertNotEmpty($res->json('data.columns'));

        $rows = $res->json('data.list');
        $this->assertNotEmpty($rows, '月报没有返回任何日期行');
        $dates = array_column($rows, 'date');
        $this->assertSame($dates, array_values(array_unique($dates)), '日期行存在重复');
        $this->assertSame('2026-10-01', $rows[0]['date'], '首日必须是所选月份的第一天');
        // 日期必须升序
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($dates, $sorted, '日期行未按升序排列');

        $first = collect($rows)->firstWhere('date', '2026-10-01');
        $this->assertNull($first['growth_rate'], '首日无环比数据应为 null');

        $day3 = collect($rows)->firstWhere('date', '2026-10-03');
        $this->assertEquals(300, round((float) $day3['amount'], 2), '当日销售额未正确归集');
        // 前一天为 0（10-02 无销售）→ 基准为 0，增长率未定义，不应产出数值
        $this->assertNull($day3['growth_rate'], '前一日销售额为 0 时环比无法定义，必须为 null');
        $this->assertNotEmpty($day3['growth_label'], '增长率未定义时仍应下发占位文案');

        $summary = $res->json('data.summary');
        $this->assertEquals(400, round((float) $summary['amount'], 2), '月销售额未跨日汇总');
        $this->assertSame(2, (int) $summary['order_count'], '月订单数未按单据去重');
    }

    /**
     * 销售利润报表：净毛利 = 销售额 − 成本 − 退货金额。
     *
     * 退货来自 sales_returns（销售退货，order_id 指回原销售订单），与采购退货
     * returns（supplier_id）是两张表。这里只造销售退货：若误查采购退货表，
     * 退货金额会是 0、净毛利与毛利相等，断言即可暴露。
     */
    public function test_profit_report_deducts_sales_returns(): void
    {
        $user = $this->makeAdmin();
        $orderId = $this->makeOrder('2026-10-10', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 3]]);
        DB::table('sales_orders')->where('id', $orderId)->update(['salesman_id' => $user->id]);

        $this->makeSalesReturn($orderId, $this->ids['customer'], $this->ids['warehouse'], '2026-10-11', [
            ['product_name' => DB::table('products')->where('id', $this->ids['product'])->value('name'), 'return_qty' => 1, 'return_amount' => 100],
        ]);

        $res = $this->getJson('admin/business/report/profit?start_date=2026-10-01&end_date=2026-10-31');

        $res->assertOk('利润报表查询失败');
        $this->assertNotEmpty($res->json('data.columns'), '利润报表没有列定义');

        $summary = $res->json('data.summary');
        // 销售 100×3 = 300，成本 5×3 = 15，毛利 285，退货 100，净毛利 185
        $this->assertEquals(300, round((float) $summary['income'], 2), '总收入未汇总');
        $this->assertEquals(15, round((float) $summary['cost'], 2), '总成本未汇总');
        $this->assertEquals(285, round((float) $summary['profit'], 2), '总毛利 = 销售额 - 成本');
        $this->assertEquals(100, round((float) $summary['return_amount'], 2), '退货金额未归集');
        $this->assertEquals(185, round((float) $summary['net_profit'], 2), '净毛利未扣减退货金额');

        $rows = $res->json('data.list');
        $this->assertNotEmpty($rows, '利润报表没有明细行');
        $row = $rows[0];
        $this->assertArrayHasKey('return_amount', $row, '明细缺少退货金额列');
        $this->assertArrayHasKey('net_profit', $row, '明细缺少净毛利列');
        $this->assertEquals(185, round((float) $row['net_profit'], 2), '明细净毛利未扣减退货');
        $this->assertEquals(95, round((float) $row['profit_rate'], 2), '毛利率 = 毛利 / 销售额');
    }

    /**
     * 退货只统计真实发生的：approved / auto_return 计入，
     * cancelled / draft / pending 都不该进退货金额。
     */
    public function test_profit_report_ignores_unapproved_returns(): void
    {
        $orderId = $this->makeOrder('2026-10-12', [['product_id' => $this->ids['product'], 'price' => 100, 'qty' => 1]]);

        foreach (['cancelled', 'draft', 'pending'] as $status) {
            $this->makeSalesReturn($orderId, $this->ids['customer'], $this->ids['warehouse'], '2026-10-12', [
                ['product_name' => DB::table('products')->where('id', $this->ids['product'])->value('name'), 'return_qty' => 1, 'return_amount' => 100],
            ], $status);
        }

        $res = $this->getJson('admin/business/report/profit?start_date=2026-10-01&end_date=2026-10-31');

        $res->assertOk();
        $this->assertEquals(0, round((float) $res->json('data.summary.return_amount'), 2), '未生效的退货被计入了退货金额');
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

    /** 为指定客户出单（默认客户版的薄封装） */
    private function makeOrderForCustomer(string $date, int $customerId, array $items): int
    {
        $real = $this->ids['customer'];
        $this->ids['customer'] = $customerId;
        try {
            return $this->makeOrder($date, $items);
        } finally {
            $this->ids['customer'] = $real;
        }
    }

    /**
     * 造一张销售退货单（默认 approved）。
     *
     * 只写 sales_returns / sales_return_items，不碰采购退货 returns——两者是不同表，
     * 利润报表必须只认销售退货。
     */
    private function makeSalesReturn(int $orderId, int $customerId, int $warehouseId, string $date, array $items, string $status = 'approved'): int
    {
        $amount = array_sum(array_column($items, 'return_amount'));
        $qty = array_sum(array_column($items, 'return_qty'));
        $seq = DB::table('sales_returns')->count() + 1;

        $returnId = DB::table('sales_returns')->insertGetId([
            'return_no' => 'TH'.str_replace('-', '', $date).sprintf('%06d', $seq),
            'order_id' => $orderId,
            'order_no' => DB::table('sales_orders')->where('id', $orderId)->value('order_no'),
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'return_date' => $date,
            'return_type' => 'quality',
            'status' => $status,
            'total_skus' => count($items),
            'total_qty' => $qty,
            'total_amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($items as $item) {
            DB::table('sales_return_items')->insert([
                'return_id' => $returnId,
                'product_id' => $this->ids['product'],
                'product_name' => $item['product_name'],
                'order_qty' => $item['return_qty'],
                'return_qty' => $item['return_qty'],
                'return_price' => $item['return_amount'],
                'return_amount' => $item['return_amount'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $returnId;
    }
}
