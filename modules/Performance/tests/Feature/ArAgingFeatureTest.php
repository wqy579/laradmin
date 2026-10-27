<?php

namespace Tests\Performance\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C4 应收账龄分析 + 发货未收款 验收测试。
 *
 * 覆盖验收标准（真实数据，禁止 mock）：
 *  - 账龄按逾期天数分桶（未到期 / 1~30 / 31~60 / 61~90 / 91~180 / 180+），部分收款取剩余应收
 *  - 客户 / 业务员双维度：各桶合计 + 汇总行；业务员维度按业务员分组并带客户数
 *  - 桶合计 = 应收总额（balance 恒等式）
 *  - details 端点按维度下钻单据明细（customer 按客户、salesman 按业务员）
 *  - 发货未收款：已发货未收齐款项单据清单（应收/已收/未收/账龄），未收 = 发货金额 - 订单已收
 */
class ArAgingFeatureTest extends TestCase
{
    use RefreshDatabase;

    private string $asOf = '2026-06-30';

    /** 造一笔已审核销售订单（仅 C4 需要的字段；items 不参与 C4 查询） */
    private function makeOrder(int $salesmanId, int $customerId, int $warehouseId, array $overrides = []): int
    {
        return DB::table('sales_orders')->insertGetId(array_merge([
            'order_no' => 'SO-C4-'.substr(str_shuffle('0123456789'), 0, 4),
            'customer_id' => $customerId, 'warehouse_id' => $warehouseId,
            'order_date' => $this->asOf.'-01', 'total_qty' => 10, 'total_amount' => 1000,
            'paid_amount' => 0, 'status' => 'approved', 'salesman_id' => $salesmanId,
        ], $overrides));
    }

    /** 造一笔发货单（已发货），关联销售单 */
    private function makeDelivery(int $orderId, string $deliveryDate, float $amount): void
    {
        DB::table('deliveries')->insert([
            'delivery_no' => 'DLV-C4-'.substr(str_shuffle('0123456789'), 0, 6),
            'order_id' => $orderId, 'total_amount' => $amount,
            'delivery_date' => $deliveryDate, 'status' => 1,
        ]);
    }

    /** 造测试数据：2 业务员 × 2 客户 + 3 笔订单 + 3 笔发货 */
    private function seedAging(): array
    {
        $s1 = $this->actingAsAdmin(['real_name' => '业务员甲'])->id;
        $s2 = $this->actingAsAdmin(['real_name' => '业务员乙'])->id;
        $c1 = $this->makeCustomer(['name' => '客户一'])->id;
        $c2 = $this->makeCustomer(['name' => '客户二'])->id;
        $wh = $this->makeWarehouse()->id;

        // 业务员甲 / 客户一：两笔未结单（账龄 29 天→1~30 桶，60 天→31~60 桶）
        $o1 = $this->makeOrder($s1, $c1, $wh, ['order_date' => '2026-06-01', 'total_amount' => 1000, 'paid_amount' => 300, 'total_qty' => 10]);
        $o2 = $this->makeOrder($s1, $c1, $wh, ['order_date' => '2026-05-01', 'total_amount' => 500, 'paid_amount' => 0, 'total_qty' => 5]);
        // 业务员乙 / 客户二：一笔未结单（账龄 121 天→91~180 桶）
        $o3 = $this->makeOrder($s2, $c2, $wh, ['order_date' => '2026-03-01', 'total_amount' => 800, 'paid_amount' => 0, 'total_qty' => 8]);

        // 三单均已发货（发货未收款数据源）
        $this->makeDelivery($o1, '2026-06-05', 1000);
        $this->makeDelivery($o2, '2026-05-05', 500);
        $this->makeDelivery($o3, '2026-03-05', 800);

        return compact('s1', 's2', 'c1', 'c2', 'o1', 'o2', 'o3');
    }

    public function test_aging_customer_dimension_buckets_and_totals(): void
    {
        $this->seedAging();

        $res = $this->getJson('/admin/perf/ar/aging?dimension=customer&as_of_date='.$this->asOf)
            ->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');

        $this->assertSame('customer', $data['dimension']);
        $this->assertSame(2, $data['total']);

        // 客户一：应收 1200 = 1000-300 + 500-0；分桶 1~30=700、31~60=500
        $c1 = collect($data['list'])->firstWhere('customer_name', '客户一');
        $this->assertNotNull($c1, '客户一应有账龄行');
        $this->assertEquals(1200.0, $c1['outstanding']);
        $this->assertEquals(700.0, $c1['b1_30']);
        $this->assertEquals(500.0, $c1['b31_60']);

        // 客户二：应收 800，账龄 121 天 → 91~180 桶
        $c2 = collect($data['list'])->firstWhere('customer_name', '客户二');
        $this->assertNotNull($c2, '客户二应有账龄行');
        $this->assertEquals(800.0, $c2['outstanding']);
        $this->assertEquals(800.0, $c2['b91_180']);

        // 恒等式：桶合计 = 应收总额
        $this->assertEquals(2000.0, $data['totals']['outstanding']);
        $this->assertEquals(700.0, $data['totals']['b1_30']);
        $this->assertEquals(500.0, $data['totals']['b31_60']);
        $this->assertEquals(800.0, $data['totals']['b91_180']);
        $this->assertEquals(0.0, $data['totals']['unmatured']);

        // 统计卡
        $this->assertEquals(2000.0, $data['cards']['total']);
        $this->assertEquals(0.0, $data['cards']['unmatured']);
        $this->assertEquals(2000.0, $data['cards']['overdue']);
        $this->assertEquals(800.0, $data['cards']['overdue_90']);
    }

    public function test_aging_salesman_dimension_groups_by_salesman(): void
    {
        $this->seedAging();

        $res = $this->getJson('/admin/perf/ar/aging?dimension=salesman&as_of_date='.$this->asOf)
            ->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');

        $this->assertSame('salesman', $data['dimension']);
        $this->assertSame(2, $data['total']);

        // 业务员甲：客户数 1、应收 1200、分桶 1~30=700、31~60=500
        $s1 = collect($data['list'])->firstWhere('salesman_name', '业务员甲');
        $this->assertNotNull($s1, '业务员甲应有账龄行');
        $this->assertEquals(1, $s1['customer_count']);
        $this->assertEquals(1200.0, $s1['outstanding']);
        $this->assertEquals(700.0, $s1['b1_30']);
        $this->assertEquals(500.0, $s1['b31_60']);

        // 业务员乙：应收 800、91~180 桶
        $s2 = collect($data['list'])->firstWhere('salesman_name', '业务员乙');
        $this->assertNotNull($s2, '业务员乙应有账龄行');
        $this->assertEquals(1, $s2['customer_count']);
        $this->assertEquals(800.0, $s2['outstanding']);
        $this->assertEquals(800.0, $s2['b91_180']);

        // 汇总行与客户维度一致
        $this->assertEquals(2000.0, $data['totals']['outstanding']);
    }

    public function test_aging_details_drilldown_both_dimensions(): void
    {
        $seed = $this->seedAging();

        // 客户维度下钻：客户一 → 2 条明细
        $res = $this->getJson('/admin/perf/ar/aging/'.$seed['c1'].'/details?dimension=customer&as_of_date='.$this->asOf)
            ->assertOk()->assertJson(['code' => 200]);
        $list = $res->json('data.list');
        $this->assertCount(2, $list);
        $this->assertEquals(700.0, collect($list)->firstWhere('order_id', $seed['o1'])['outstanding']);
        $this->assertEquals(500.0, collect($list)->firstWhere('order_id', $seed['o2'])['outstanding']);
        $this->assertEquals('b1_30', collect($list)->firstWhere('order_id', $seed['o1'])['bucket']);
        $this->assertEquals('b31_60', collect($list)->firstWhere('order_id', $seed['o2'])['bucket']);

        // 业务员维度下钻：业务员甲 → 2 条明细（含客户名）
        $res2 = $this->getJson('/admin/perf/ar/aging/'.$seed['s1'].'/details?dimension=salesman&as_of_date='.$this->asOf)
            ->assertOk()->assertJson(['code' => 200]);
        $list2 = $res2->json('data.list');
        $this->assertCount(2, $list2);
        $this->assertSame('客户一', collect($list2)->first()['customer_name']);
    }

    public function test_delivery_uncollected_real_data(): void
    {
        $seed = $this->seedAging();

        $res = $this->getJson('/admin/perf/delivery/uncollected')
            ->assertOk()->assertJson(['code' => 200]);
        $data = $res->json('data');

        // 3 张已发货未收齐单
        $this->assertSame(3, $data['total']);

        // 未收 = 发货金额 - 订单已收
        $rows = collect($data['list'])->keyBy('order_id');
        $this->assertEquals(700.0, $rows[$seed['o1']]['uncollected']); // 1000 - 300
        $this->assertEquals(500.0, $rows[$seed['o2']]['uncollected']); // 500 - 0
        $this->assertEquals(800.0, $rows[$seed['o3']]['uncollected']); // 800 - 0
        $this->assertEquals(1000.0, $rows[$seed['o1']]['total_amount']);

        // 总未收
        $this->assertEquals(2000.0, $data['cards']['total_uncollected']);
        $this->assertEquals(2000.0, $data['totals']['total_uncollected']);
    }
}
