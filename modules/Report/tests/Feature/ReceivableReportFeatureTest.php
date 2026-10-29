<?php

namespace Tests\Report\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * E7 应收+余额+考勤+访店报表接口冒烟。
 *
 * 沿用 E6 的「SQL 真的能跑」价值主张：应收按客户聚合出六档账龄、余额读
 * customers.balance、考勤逐条、访店按业务员聚合覆盖率——全是多表 join +
 * 方言差异（julianday / DATEDIFF）最容易炸的地方，页面一翻页才 500。
 */
class ReceivableReportFeatureTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    private int $salesman1;

    private int $salesman2;

    private int $customerA;

    private int $customerB;

    private int $customerC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->seedBase();
        $this->seedReceivableData();
    }

    // ==================== 数据准备 ====================

    private function seedBase(): void
    {
        $product = $this->makeProduct(['code' => 'E7_PROD', 'cost_price' => 5, 'price_small' => 12]);
        $warehouse = $this->makeWarehouse();
        DB::table('stocks')->insert([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'quantity' => 50, 'frozen_qty' => 0, 'cost_price' => 5, 'total_amount' => 250,
        ]);
        $this->ids = ['product' => $product->id, 'warehouse' => $warehouse->id];
    }

    private function seedReceivableData(): void
    {
        // 两个业务员（auth_user）
        $this->salesman1 = $this->makeAdmin(['real_name' => '业务员一'])->id;
        $this->salesman2 = $this->makeAdmin(['real_name' => '业务员二'])->id;

        // 员工档案（考勤用）：employee.user_id → auth_user
        DB::table('employees')->insert([
            'code' => 'E7EMP01', 'name' => '业务员一', 'department' => '销售一部',
            'position' => '业务员', 'user_id' => $this->salesman1, 'role' => 'salesman', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'code' => 'E7EMP02', 'name' => '业务员二', 'department' => '销售二部',
            'position' => '业务员', 'user_id' => $this->salesman2, 'role' => 'salesman', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 线路（访店计划点位归属）：routes.employee_id → auth_user
        $route1 = DB::table('routes')->insertGetId([
            'code' => 'RTE01', 'name' => '线路一', 'employee_id' => $this->salesman1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $route2 = DB::table('routes')->insertGetId([
            'code' => 'RTE02', 'name' => '线路二', 'employee_id' => $this->salesman2, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 客户：A/B 挂线路一（业务员一，2 个点位）；C/D/E 挂线路二（业务员二，3 个点位）
        $this->customerA = $this->makeCustomer(['code' => 'E7_C_A', 'name' => '客户甲', 'route_id' => $route1, 'credit_limit' => 1000, 'balance' => 1500])->id;
        $this->customerB = $this->makeCustomer(['code' => 'E7_C_B', 'name' => '客户乙', 'route_id' => $route1, 'credit_limit' => 500, 'balance' => 200])->id;
        $this->customerC = $this->makeCustomer(['code' => 'E7_C_C', 'name' => '客户丙', 'route_id' => $route2, 'credit_limit' => 2000, 'balance' => -200])->id; // 预付款 → 负余额红
        $customerD = $this->makeCustomer(['code' => 'E7_C_D', 'name' => '客户丁', 'route_id' => $route2, 'credit_limit' => 500, 'balance' => 0])->id;
        $customerE = $this->makeCustomer(['code' => 'E7_C_E', 'name' => '客户戊', 'route_id' => $route2, 'credit_limit' => 500, 'balance' => 0])->id;

        // 销售订单（应收）：客户甲两笔未结清（39 天 / 19 天），客户丙一笔 28 天
        $this->makeReceivableOrder('E7_SO_001', $this->customerA, '2026-09-20', 1000, 400, $this->salesman1); // 未收 600 → 39 天
        $this->makeReceivableOrder('E7_SO_002', $this->customerA, '2026-10-10', 800, 0, $this->salesman1); // 未收 800 → 19 天
        $this->makeReceivableOrder('E7_SO_003', $this->customerC, '2026-10-01', 500, 0, $this->salesman2); // 未收 500 → 28 天

        // 红冲单（original_order_id 非空）与草稿：都不计入应收
        $this->makeReceivableOrder('E7_SO_004', $this->customerB, '2026-10-05', 300, 0, $this->salesman1, ['status' => 'draft']);
        $redFlushId = $this->makeReceivableOrder('E7_SO_005', $this->customerB, '2026-10-06', 400, 0, $this->salesman1);
        DB::table('sales_orders')->where('id', $redFlushId)->update(['original_order_id' => 99999, 'status' => 'approved']);

        // 考勤：业务员一 正常/迟到 各一天；业务员二 请假一天
        $emp1 = DB::table('employees')->where('user_id', $this->salesman1)->value('id');
        $emp2 = DB::table('employees')->where('user_id', $this->salesman2)->value('id');
        DB::table('attendances')->insert([
            'employee_id' => $emp1, 'date' => '2026-10-28', 'clock_in' => '2026-10-28 08:55:00',
            'clock_out' => '2026-10-28 17:30:00', 'status' => 1, 'visit_count' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attendances')->insert([
            'employee_id' => $emp1, 'date' => '2026-10-29', 'clock_in' => '2026-10-29 09:20:00',
            'clock_out' => null, 'status' => 2, 'visit_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attendances')->insert([
            'employee_id' => $emp2, 'date' => '2026-10-28', 'clock_in' => null,
            'clock_out' => null, 'status' => 5, 'visit_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 访店：业务员一 拜访 2 个点位（甲、乙），业务员二 只拜访 1 个（丙）
        foreach ([
            ['E7_V_001', $this->salesman1, $this->customerA, '2026-10-28 09:30:00', 30],
            ['E7_V_002', $this->salesman1, $this->customerB, '2026-10-28 14:00:00', 40],
            ['E7_V_003', $this->salesman2, $this->customerC, '2026-10-28 10:00:00', 25],
        ] as [$no, $sm, $cust, $time, $dur]) {
            DB::table('visit_logs')->insert([
                'visit_no' => $no, 'employee_id' => $sm, 'customer_id' => $cust,
                'checkin_time' => $time, 'checkout_time' => date('Y-m-d H:i:s', strtotime($time) + $dur * 60),
                'visit_duration' => $dur, 'visit_result' => '成交', 'status' => 2,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** 销售订单：status 默认 approved，非红冲，可指定覆盖（测试红冲/草稿排除） */
    private function makeReceivableOrder(string $orderNo, int $customerId, string $date, float $total, float $paid, int $salesmanId, array $overrides = []): int
    {
        $id = DB::table('sales_orders')->insertGetId(array_merge([
            'order_no' => $orderNo, 'order_type' => 'normal', 'source' => 'admin',
            'customer_id' => $customerId, 'warehouse_id' => $this->ids['warehouse'],
            'order_date' => $date, 'total_amount' => $total, 'total_qty' => 1,
            'paid_amount' => $paid, 'status' => 'approved', 'salesman_id' => $salesmanId,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    // ==================== 用例 ====================

    public function test_receivable_every_dimension_runs(): void
    {
        $dimensions = ['receivable', 'balance', 'attendance', 'visit'];

        foreach ($dimensions as $dimension) {
            $res = $this->getJson('admin/business/report/receivable?dimension='.$dimension);
            $res->assertOk("应收访店维度 {$dimension} 查询失败");
            $this->assertNotEmpty($res->json('data.columns'), "维度 {$dimension} 没有返回列定义");
            $this->assertIsArray($res->json('data.list'), "维度 {$dimension} list 不是数组");
            $this->assertIsArray($res->json('data.summary'), "维度 {$dimension} summary 不是数组");
        }
    }

    public function test_receivable_aging_buckets_and_as_of_date(): void
    {
        // 截止今天(2026-10-29)：客户甲 39 天 + 19 天两笔，客户丙 28 天
        $res = $this->getJson('admin/business/report/receivable?dimension=receivable&as_of_date=2026-10-29');
        $res->assertOk();

        $rowA = collect($res->json('data.list'))->firstWhere('customer_id', $this->customerA);
        $this->assertNotNull($rowA, '客户甲应出现在应收表');
        $this->assertEquals(2, (int) $rowA['order_count']);
        $this->assertEquals(1400, round((float) $rowA['outstanding'], 2));
        $this->assertEquals(800, round((float) $rowA['b1_30'], 2), '19 天那笔进 1~30 天桶');
        $this->assertEquals(600, round((float) $rowA['b31_60'], 2), '39 天那笔进 31~60 天桶');
        $this->assertEquals(0, round((float) $rowA['unmatured'], 2));
        $this->assertEquals(39, (int) $rowA['max_age']);

        $rowC = collect($res->json('data.list'))->firstWhere('customer_id', $this->customerC);
        $this->assertEquals(500, round((float) $rowC['outstanding'], 2));
        $this->assertEquals(500, round((float) $rowC['b1_30'], 2));

        // 合计行 = 客户甲 1400 + 客户丙 500
        $summary = $res->json('data.summary');
        $this->assertEquals(1900, round((float) $summary['outstanding'], 2));
        $this->assertEquals(1300, round((float) $summary['b1_30'], 2));
        $this->assertEquals(600, round((float) $summary['b31_60'], 2));

        // 截止 10-01：10-10 那笔(19天)被剔除（订单日在截止日后），9-20 那笔 11 天 → 1~30
        $res2 = $this->getJson('admin/business/report/receivable?dimension=receivable&as_of_date=2026-10-01');
        $res2->assertOk();
        $rowA2 = collect($res2->json('data.list'))->firstWhere('customer_id', $this->customerA);
        $this->assertEquals(600, round((float) $rowA2['outstanding'], 2), '截止日前移应只剩 9-20 一笔');
        $this->assertEquals(600, round((float) $rowA2['b1_30'], 2), '11 天进 1~30 天桶');
    }

    public function test_receivable_excludes_red_flush_and_draft(): void
    {
        $res = $this->getJson('admin/business/report/receivable?dimension=receivable&as_of_date=2026-10-29');

        // 客户乙：只挂草稿 + 红冲单，都不计入 → 不出现
        $rowB = collect($res->json('data.list'))->firstWhere('customer_id', $this->customerB);
        $this->assertNull($rowB, '草稿/红冲单不应计入应收');
    }

    public function test_receivable_customer_filters(): void
    {
        $res = $this->getJson('admin/business/report/receivable?dimension=receivable&customer_ids[]='.$this->customerA.'&as_of_date=2026-10-29');
        $res->assertOk();
        $this->assertCount(1, $res->json('data.list'));
        $this->assertEquals($this->customerA, $res->json('data.list.0.customer_id'));

        $bySalesman = $this->getJson('admin/business/report/receivable?dimension=receivable&salesman_ids[]='.$this->salesman2.'&as_of_date=2026-10-29');
        $bySalesman->assertOk();
        $this->assertCount(1, $bySalesman->json('data.list'), '业务员二只挂客户丙');
    }

    public function test_receivable_drill_returns_customer_documents(): void
    {
        $res = $this->getJson('admin/business/report/receivable/drill?customer_id='.$this->customerA.'&as_of_date=2026-10-29');
        $res->assertOk();

        $items = $res->json('data.items');
        $this->assertCount(2, $items);
        $this->assertEquals('E7_SO_001', $items[0]['order_no']);
        $this->assertEquals(600, round((float) $items[0]['outstanding'], 2));
        $this->assertEquals(39, (int) $items[0]['age_days']);
        $this->assertEquals('31~60天', $items[0]['bucket_label']);
        $this->assertEquals(1400, round((float) $res->json('data.header.totals.0.value'), 2));
    }

    public function test_balance_shows_negative_red_semantics(): void
    {
        $res = $this->getJson('admin/business/report/receivable?dimension=balance');
        $res->assertOk();

        // 客户甲：欠款 1500，额度 1000 → 超额度
        $rowA = collect($res->json('data.list'))->firstWhere('customer_id', $this->customerA);
        $this->assertEquals(1500, round((float) $rowA['balance'], 2));
        $this->assertEquals(150, round((float) $rowA['credit_usage_rate'], 2));
        $this->assertSame('over', $rowA['status']);

        // 客户丙：预付款 -200 → 负余额（前端 negative_red 标红）
        $rowC = collect($res->json('data.list'))->firstWhere('customer_id', $this->customerC);
        $this->assertEquals(-200, round((float) $rowC['balance'], 2));
        $this->assertSame('normal', $rowC['status'], '负余额不算超额度');

        // 负余额列定义应标 negative_red
        $col = collect($res->json('data.columns'))->firstWhere('prop', 'balance');
        $this->assertTrue((bool) $col['negative_red'], '余额列应配置 negative_red');
    }

    public function test_attendance_matches_records_exactly(): void
    {
        $res = $this->getJson('admin/business/report/receivable?dimension=attendance&start_date=2026-10-28&end_date=2026-10-29');
        $res->assertOk();

        $rows = $res->json('data.list');
        $this->assertCount(3, $rows, '三条考勤记录逐条呈现');

        $normal = collect($rows)->first(fn ($r) => $r['date'] === '2026-10-28' && $r['status'] === 1);
        $this->assertNotNull($normal, '10-28 应有一条正常考勤');
        $this->assertSame('正常', $normal['status_label']);
        $this->assertEquals(3, (int) $normal['visit_count']);

        $late = collect($rows)->firstWhere('status', 2);
        $this->assertSame('迟到', $late['status_label']);
        $this->assertEquals('业务员一', $late['employee_name']);

        $leave = collect($rows)->firstWhere('status', 5);
        $this->assertSame('请假', $leave['status_label']);

        // 员工筛选：只留业务员一（2 条）
        $bySm = $this->getJson('admin/business/report/receivable?dimension=attendance&salesman_ids[]='.$this->salesman1.'&start_date=2026-10-28&end_date=2026-10-29');
        $bySm->assertOk();
        $this->assertCount(2, $bySm->json('data.list'));
    }

    public function test_visit_coverage_rate(): void
    {
        $res = $this->getJson('admin/business/report/receivable?dimension=visit&start_date=2026-10-01&end_date=2026-10-31');
        $res->assertOk();

        // 业务员一：计划 2（线路一挂甲乙），实际拜访 2，到达 2 → 100%
        $row1 = collect($res->json('data.list'))->firstWhere('salesman_id', $this->salesman1);
        $this->assertEquals(2, (int) $row1['plan_customers']);
        $this->assertEquals(2, (int) $row1['actual_visits']);
        $this->assertEquals(2, (int) $row1['reached_customers']);
        $this->assertEquals(100.0, (float) $row1['coverage_rate']);
        $this->assertEquals(70, (int) $row1['total_duration'], '30+40 分钟');

        // 业务员二：计划 3（线路二挂丙丁戊），实际拜访 1 → 覆盖率 33.3%（<50 红）
        $row2 = collect($res->json('data.list'))->firstWhere('salesman_id', $this->salesman2);
        $this->assertEquals(3, (int) $row2['plan_customers']);
        $this->assertEquals(1, (int) $row2['actual_visits']);
        $this->assertEquals(1, (int) $row2['reached_customers']);
        $this->assertEquals(33.3, (float) $row2['coverage_rate']);

        $col = collect($res->json('data.columns'))->firstWhere('prop', 'coverage_rate');
        $this->assertEquals(50, (int) $col['danger_below'], '覆盖率列应配置 <50% 红');

        $summary = $res->json('data.summary');
        $this->assertEquals(5, (int) $summary['plan_customers']);
        $this->assertEquals(3, (int) $summary['actual_visits']);
        $this->assertEquals(3, (int) $summary['reached_customers']);
    }

    public function test_visit_drill_returns_salesman_schedule(): void
    {
        $res = $this->getJson('admin/business/report/receivable/drill?dimension=visit&salesman_id='.$this->salesman1.'&start_date=2026-10-01&end_date=2026-10-31');
        $res->assertOk();

        $items = $res->json('data.items');
        $this->assertCount(2, $items);
        // 控制器按签到时间倒序：14:00（E7_V_002）在前，09:30（E7_V_001）在后
        $this->assertEquals('E7_V_002', $items[0]['visit_no']);
        $this->assertEquals('客户乙', $items[0]['customer_name']);
        $this->assertEquals(40, (int) $items[0]['visit_duration']);
        $this->assertEquals('E7_V_001', $items[1]['visit_no']);
        $this->assertEquals('客户甲', $items[1]['customer_name']);
    }

    public function test_attendance_drill_returns_daily_visits(): void
    {
        $res = $this->getJson('admin/business/report/receivable/drill?dimension=attendance&employee_user_id='.$this->salesman1.'&date=2026-10-28');
        $res->assertOk();

        $items = $res->json('data.items');
        $this->assertCount(2, $items, '业务员一 10-28 有 2 条拜访');
        $this->assertEquals(70, (int) $res->json('data.header.totals.1.value'), '总时长 30+40');
    }

    public function test_receivable_export_returns_blob(): void
    {
        $res = $this->getJson('admin/business/report/receivable/export?dimension=receivable&as_of_date=2026-10-29');
        $res->assertOk();
        $this->assertStringContainsString('application/vnd.ms-excel', $res->headers->get('content-type'));
    }
}
