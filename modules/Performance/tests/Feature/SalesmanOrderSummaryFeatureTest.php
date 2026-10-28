<?php

namespace Tests\Performance\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C7 业务员订单汇总 验收测试（真实数据，禁止 mock）。
 *
 * 覆盖验收标准：
 *  - 三个页签均取真实单据：商品明细逐行、客户×商品汇总、业务员订单汇总
 *  - 仅 approved / completed 计入有效销售，draft / pending / cancelled 一律排除
 *  - 下单日期区间 + 业务员 + 客户筛选生效（区间为闭区间）
 *  - 合计行恒等于本页返回行之和；订单数按 DISTINCT 单，订单金额不被明细行数放大
 *  - 导出 CSV 与界面数字一致（三段表头 + 金额 2 位）
 *  - 空结果返回空列表与数值 0（不出现 null / NaN）
 */
class SalesmanOrderSummaryFeatureTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $ids = [];

    /**
     * 造测试数据：2 业务员 × 2 客户 × 2 商品，4 张有效单 + 3 张无效单 + 1 张无明细单。
     *
     * 有效单金额：甲 = 1000(06-01, 两行 500+500) + 300(06-10, 一行 300) = 1300
     *             乙 = 800(05-01, 一行 800)；明细金额合计 2100、数量合计 25
     */
    private function seedOrders(): void
    {
        $s1 = $this->actingAsAdmin(['real_name' => '业务员甲'])->id;
        $s2 = $this->makeAdmin(['real_name' => '业务员乙'])->id;
        $c1 = $this->makeCustomer(['name' => '客户一'])->id;
        $c2 = $this->makeCustomer(['name' => '客户二'])->id;
        $wh = $this->makeWarehouse()->id;
        $p1 = $this->makeProduct(['name' => '商品甲', 'code' => 'PA001'])->id;
        $p2 = $this->makeProduct(['name' => '商品乙', 'code' => 'PB002'])->id;
        $this->ids = compact('s1', 's2', 'c1', 'c2', 'wh', 'p1', 'p2');

        // 甲/客户一 06-01 已审核：两行 500 + 500 = 1000
        $this->makeOrder(['salesman_id' => $s1, 'customer_id' => $c1, 'order_date' => '2026-06-01', 'total_amount' => 1000, 'total_qty' => 15], [
            ['product_id' => $p1, 'quantity' => 10, 'price' => 50, 'amount' => 500],
            ['product_id' => $p2, 'quantity' => 5, 'price' => 100, 'amount' => 500],
        ]);
        // 甲/客户一 06-10 已完成：一行 300（与客户一商品甲合成 order_count=2）
        $this->makeOrder(['salesman_id' => $s1, 'customer_id' => $c1, 'order_date' => '2026-06-10', 'status' => 'completed', 'total_amount' => 300, 'total_qty' => 2], [
            ['product_id' => $p1, 'quantity' => 2, 'price' => 150, 'amount' => 300],
        ]);
        // 乙/客户二 05-01 已审核：一行 800
        $this->makeOrder(['salesman_id' => $s2, 'customer_id' => $c2, 'order_date' => '2026-05-01', 'total_amount' => 800, 'total_qty' => 8], [
            ['product_id' => $p1, 'quantity' => 8, 'price' => 100, 'amount' => 800],
        ]);

        // 无效状态：三张都必须被排除
        $this->makeOrder(['salesman_id' => $s1, 'customer_id' => $c1, 'order_date' => '2026-06-05', 'status' => 'cancelled', 'total_amount' => 999], [
            ['product_id' => $p1, 'quantity' => 1, 'price' => 999, 'amount' => 999],
        ]);
        $this->makeOrder(['salesman_id' => $s1, 'customer_id' => $c1, 'order_date' => '2026-06-05', 'status' => 'draft', 'total_amount' => 400], [
            ['product_id' => $p1, 'quantity' => 1, 'price' => 400, 'amount' => 400],
        ]);
        $this->makeOrder(['salesman_id' => $s2, 'customer_id' => $c2, 'order_date' => '2026-06-05', 'status' => 'pending', 'total_amount' => 500], [
            ['product_id' => $p1, 'quantity' => 1, 'price' => 500, 'amount' => 500],
        ]);

        // 已审核但无明细：不产生明细行，也不计入业务员订单数/金额
        $this->makeOrder(['salesman_id' => $s2, 'customer_id' => $c2, 'order_date' => '2026-06-02', 'total_amount' => 700], []);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function makeOrder(array $overrides, array $items): int
    {
        $id = DB::table('sales_orders')->insertGetId(array_merge([
            'order_no' => 'SO-C7-'.substr(str_shuffle('0123456789ABCDEF'), 0, 6),
            'customer_id' => $this->ids['c1'], 'warehouse_id' => $this->ids['wh'],
            'order_date' => '2026-06-01', 'total_qty' => 10, 'total_amount' => 1000,
            'paid_amount' => 0, 'status' => 'approved', 'salesman_id' => $this->ids['s1'],
        ], $overrides));

        foreach ($items as $item) {
            DB::table('sales_order_items')->insert(array_merge([
                'sales_order_id' => $id, 'product_id' => $this->ids['p1'],
                'quantity' => 1, 'price' => 100, 'amount' => 100,
            ], $item));
        }

        return $id;
    }

    public function test_items_tab_matches_inserted_rows(): void
    {
        $this->seedOrders();

        $data = $this->getJson('/admin/perf/salesman-order/items?page_size=50')
            ->assertOk()->assertJson(['code' => 200])
            ->json('data');

        // 4 条有效明细（无效状态 3 行 + 无明细单不出现）
        $this->assertSame(4, $data['total']);
        $this->assertCount(4, $data['list']);

        $row = collect($data['list'])->firstWhere('product_code', 'PB002');
        $this->assertNotNull($row, '商品乙明细应存在');
        $this->assertSame('2026-06-01', $row['apply_date']);
        $this->assertSame('业务员甲', $row['salesman']);
        $this->assertSame('客户一', $row['customer']);
        $this->assertSame('商品乙', $row['product_name']);
        $this->assertSame(5, $row['qty']);
        $this->assertEquals(100.0, $row['unit_price']);
        $this->assertEquals(500.0, $row['amount']);
        $this->assertSame('approved', $row['status']);
        $this->assertSame('', $row['brand']);

        $this->assertSame(25, $data['totals']['qty']);
        $this->assertEquals(2100.0, $data['totals']['amount']);
    }

    public function test_items_totals_equal_sum_of_returned_page_rows(): void
    {
        $this->seedOrders();

        $data = $this->getJson('/admin/perf/salesman-order/items?page=1&page_size=2')
            ->assertOk()->json('data');

        $this->assertSame(4, $data['total'], '总数仍是全量筛选结果');
        $this->assertCount(2, $data['list']);

        // 合计只统计本页返回行
        $this->assertSame((int) array_sum(array_column($data['list'], 'qty')), $data['totals']['qty']);
        $this->assertEquals(
            round(array_sum(array_column($data['list'], 'amount')), 2),
            $data['totals']['amount']
        );

        // 第一页首行 = 最新日期（06-10）的明细
        $this->assertSame('2026-06-10', $data['list'][0]['apply_date']);

        // 第二页存在且与第一页行不重复（同单同行不跨页）
        $page2 = $this->getJson('/admin/perf/salesman-order/items?page=2&page_size=2')->assertOk()->json('data');
        $this->assertCount(2, $page2['list']);
        $keys1 = array_map(fn ($r) => $r['order_no'].'|'.$r['product_code'], $data['list']);
        $keys2 = array_map(fn ($r) => $r['order_no'].'|'.$r['product_code'], $page2['list']);
        $this->assertEmpty(array_intersect($keys1, $keys2));
    }

    public function test_invalid_statuses_are_excluded(): void
    {
        $this->seedOrders();

        $list = $this->getJson('/admin/perf/salesman-order/items?page_size=50')->assertOk()->json('data.list');
        $statuses = array_unique(array_column($list, 'status'));
        sort($statuses);
        $this->assertSame(['approved', 'completed'], $statuses);

        // 业务员汇总也不含无效单：甲 2 单、乙 1 单（无明细单不计）
        $sData = $this->getJson('/admin/perf/salesman-order/salesman')->assertOk()->json('data');
        $this->assertSame(3, $sData['totals']['order_count']);
        $this->assertEquals(2100.0, $sData['totals']['order_amount']);
    }

    public function test_date_range_narrows_results(): void
    {
        $this->seedOrders();

        $data = $this->getJson('/admin/perf/salesman-order/items?apply_date_start=2026-06-01&apply_date_end=2026-06-05&page_size=50')
            ->assertOk()->json('data');

        $this->assertSame(2, $data['total'], '闭区间应只剩 06-01 那张单');
        $this->assertSame(15, $data['totals']['qty']);
        $this->assertEquals(1000.0, $data['totals']['amount']);
        $this->assertSame(['2026-06-01'], array_values(array_unique(array_column($data['list'], 'apply_date'))));

        // 单边界：只看 6 月之后
        $after = $this->getJson('/admin/perf/salesman-order/items?apply_date_start=2026-06-10&page_size=50')
            ->assertOk()->json('data');
        $this->assertSame(1, $after['total']);
        $this->assertEquals(300.0, $after['totals']['amount']);
    }

    public function test_customer_item_groups_and_distinct_order_count(): void
    {
        $this->seedOrders();

        $data = $this->getJson('/admin/perf/salesman-order/customer-item?page_size=50')
            ->assertOk()->assertJson(['code' => 200])->json('data');

        $this->assertSame(3, $data['total']);
        $rows = collect($data['list']);

        // 客户一 × 商品甲：跨两张单 → order_count 2、qty 12、金额 800
        // （商品甲在客户二也有一行，必须按客户+商品共同定位）
        $row = $rows->first(fn ($r) => $r['customer'] === '客户一' && $r['product_name'] === '商品甲');
        $this->assertNotNull($row);
        $this->assertSame('业务员甲', $row['salesman']);
        $this->assertSame(12, $row['qty']);
        $this->assertEquals(800.0, $row['amount']);
        $this->assertSame(2, $row['order_count'], '订单数须按 DISTINCT 单去重');

        $this->assertSame(25, $data['totals']['qty']);
        $this->assertEquals(2100.0, $data['totals']['amount']);
    }

    public function test_salesman_tab_amounts_not_multiplied_by_item_rows(): void
    {
        $this->seedOrders();

        $data = $this->getJson('/admin/perf/salesman-order/salesman')
            ->assertOk()->assertJson(['code' => 200])->json('data');

        $this->assertSame(2, $data['total']);
        $rows = collect($data['list']);

        // 甲：3 条明细行、2 张单、订单金额 1300（不是 1000×2+300×… 的放大值）
        $s1 = $rows->firstWhere('sales_name', '业务员甲');
        $this->assertNotNull($s1);
        $this->assertSame(2, $s1['order_count']);
        $this->assertSame(3, $s1['goods_item_count']);
        $this->assertEquals(1300.0, $s1['order_amount']);
        $this->assertSame(1, $s1['rank']);
        $this->assertEquals(61.90, $s1['revenue']);

        $s2 = $rows->firstWhere('sales_name', '业务员乙');
        $this->assertSame(1, $s2['order_count']);
        $this->assertSame(1, $s2['goods_item_count']);
        $this->assertEquals(800.0, $s2['order_amount']);
        $this->assertSame(2, $s2['rank']);
        $this->assertEquals(38.10, $s2['revenue']);

        // 合计 = 各行之和，占比合计 100
        $this->assertSame(3, $data['totals']['order_count']);
        $this->assertSame(4, $data['totals']['goods_item_count']);
        $this->assertEquals(2100.0, $data['totals']['order_amount']);
        $this->assertEquals(100.0, $data['totals']['revenue']);
        $this->assertEquals(
            round(array_sum(array_column($data['list'], 'order_amount')), 2),
            $data['totals']['order_amount']
        );
    }

    public function test_salesman_and_customer_filters(): void
    {
        $this->seedOrders();
        $ids = $this->ids;

        $bySalesman = $this->getJson('/admin/perf/salesman-order/items?salesman_id='.$ids['s2'].'&page_size=50')
            ->assertOk()->json('data');
        $this->assertSame(1, $bySalesman['total']);
        $this->assertSame('业务员乙', $bySalesman['list'][0]['salesman']);
        $this->assertEquals(800.0, $bySalesman['totals']['amount']);

        $byCustomer = $this->getJson('/admin/perf/salesman-order/items?customer_id='.$ids['c1'].'&page_size=50')
            ->assertOk()->json('data');
        $this->assertSame(3, $byCustomer['total']);
        $this->assertEquals(1300.0, $byCustomer['totals']['amount']);

        // 筛选对客户商品汇总与业务员汇总同样生效
        $sm = $this->getJson('/admin/perf/salesman-order/salesman?salesman_id='.$ids['s2'])->assertOk()->json('data');
        $this->assertSame(1, $sm['total']);
        $this->assertEquals(800.0, $sm['totals']['order_amount']);
        $this->assertEquals(100.0, $sm['list'][0]['revenue'], '单人占比应为 100');
    }

    public function test_empty_result_returns_zero_totals(): void
    {
        $this->seedOrders();

        foreach (['items', 'customer-item', 'salesman'] as $path) {
            $data = $this->getJson('/admin/perf/salesman-order/'.$path.'?salesman_id=999999')
                ->assertOk()->assertJson(['code' => 200])->json('data');

            $this->assertSame([], $data['list'], $path.' 应为空列表');
            $this->assertSame(0, $data['total']);
            foreach ($data['totals'] as $key => $value) {
                $this->assertIsNumeric($value, $path.' totals.'.$key.' 必须是数值');
                $this->assertEquals(0, $value);
            }
        }
    }

    public function test_item_amount_falls_back_to_qty_times_price(): void
    {
        $this->seedOrders();

        $orderNo = 'SO-C7-FALLBACK';
        $this->makeOrder([
            'order_no' => $orderNo, 'status' => 'approved',
            'order_date' => '2026-07-01', 'total_amount' => 120,
        ], [
            ['product_id' => $this->ids['p2'], 'quantity' => 3, 'price' => 40, 'amount' => 0],
        ]);

        $row = collect($this->getJson('/admin/perf/salesman-order/items?apply_date_start=2026-07-01&page_size=50')
            ->assertOk()->json('data.list'))->firstWhere('order_no', $orderNo);

        $this->assertNotNull($row);
        $this->assertEquals(120.0, $row['amount'], 'amount 为 0 时回退 数量×单价');
    }

    public function test_export_returns_csv_matching_screen_numbers(): void
    {
        $this->seedOrders();

        $res = $this->get('/admin/perf/salesman-order/export')->assertOk();
        $body = $res->getContent();

        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", substr($body, 3), '仅首段允许 BOM');

        // 三段表头齐全
        foreach (['商品明细', '客户商品汇总', '业务员订单汇总'] as $title) {
            $this->assertStringContainsString($title, $body);
        }
        $this->assertStringContainsString('日期,单号,业务员,客户,商品编码,商品名称,品牌,数量,单价,金额,状态', $body);
        $this->assertStringContainsString('业务员,客户,商品名称,数量,金额,订单数', $body);
        $this->assertStringContainsString('业务员,订单数,商品行数,订单金额,占比%,排名', $body);

        // 金额与界面一致（2 位小数），且无效单金额 999 不出现
        $this->assertStringContainsString('500.00', $body);
        $this->assertStringContainsString('1300.00', $body);
        $this->assertStringContainsString('61.90', $body);
        $this->assertStringNotContainsString('999.00', $body);

        // 导出为全量明细（不受分页影响）：4 条明细 + 3 条汇总 + 2 条业务员 = 9 数据行
        $lines = array_values(array_filter(explode("\n", trim($body)), fn ($l) => trim($l) !== ''));
        $this->assertCount(3 * 2 + 9, $lines); // 每段 标题 + 表头
    }

    public function test_export_respects_filters(): void
    {
        $this->seedOrders();

        $body = $this->get('/admin/perf/salesman-order/export?apply_date_start=2026-06-01&apply_date_end=2026-06-05')
            ->assertOk()->getContent();

        $this->assertStringContainsString('500.00', $body);
        $this->assertStringNotContainsString('800.00', $body, '区间外的客户二单据不应导出');
        $this->assertStringNotContainsString('300.00', $body, '区间外的 06-10 单据不应导出');
    }
}
