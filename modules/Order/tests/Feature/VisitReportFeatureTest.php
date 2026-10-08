<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 模块八 / 模块九：访店达成率与业务员行程。
 *
 * 这两个接口的坑都在「按什么切桶」上：年度要切 12 个月、月度要切 5 个周段。
 * 桶是在 PHP 里切的（MySQL 的 MONTH() 与 SQLite 的 strftime() 不通用），
 * 所以用例直接断言桶的数量与数值，方言换了也不会悄悄算错。
 */
class VisitReportFeatureTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->seedVisitData();
    }

    public function test_year_mode_returns_12_month_buckets(): void
    {
        $res = $this->getJson('admin/business/visit/achievement?mode=year&date=2026-01-01&salesman_ids[]='.$this->ids['salesman']);

        $res->assertOk();
        $this->assertSame('year', $res->json('data.mode'));
        $row = $res->json('data.list.0');
        $this->assertCount(12, $row['buckets']);
        $this->assertSame('1月', $row['buckets'][0]['label']);
        // 1 月 2 次拜访
        $this->assertSame(2, $row['buckets'][0]['value']);
        // 3 月 1 次
        $this->assertSame(1, $row['buckets'][2]['value']);
        // 计划客户数来自线路下的客户数
        $this->assertSame(2, $row['plan_customers']);
        $this->assertSame(3, $row['actual_visits']);
    }

    public function test_month_mode_returns_5_week_buckets(): void
    {
        $res = $this->getJson('admin/business/visit/achievement?mode=month&date=2026-01-01&salesman_ids[]='.$this->ids['salesman']);

        $res->assertOk();
        $row = $res->json('data.list.0');
        $this->assertCount(5, $row['buckets']);
        $this->assertSame('第1周', $row['buckets'][0]['label']);
        // 1/5 与 1/12 落在第 1 周（1-7 日 / 8-14 日）
        $this->assertSame(1, $row['buckets'][0]['value']);
        $this->assertSame(1, $row['buckets'][1]['value']);
    }

    public function test_trend_returns_series_for_one_salesman(): void
    {
        $res = $this->getJson('admin/business/visit/trend?employee_id='.$this->ids['salesman'].'&mode=year&date=2026-01-01');

        $res->assertOk();
        $this->assertCount(12, $res->json('data.series'));
        $this->assertSame($this->ids['salesman'], (int) $this->ids['salesman']);
        $this->assertNotNull($res->json('data.employee_name'));
    }

    public function test_schedule_returns_list_and_summary(): void
    {
        $res = $this->getJson('admin/business/visit/schedule?date_start=2026-01-01&date_end=2026-12-31&salesman_ids[]='.$this->ids['salesman']);

        $res->assertOk();
        $this->assertSame(3, $res->json('data.total'));
        $this->assertSame(3, $res->json('data.summary.visit_count'));
        $this->assertSame(2, $res->json('data.summary.customer_count'));
        // employee_id 必须回传，前端点「轨迹」要用
        $this->assertSame($this->ids['salesman'], $res->json('data.list.0.employee_id'));
    }

    public function test_trajectory_returns_ordered_points_with_location_flag(): void
    {
        $res = $this->getJson('admin/business/visit/trajectory?employee_id='.$this->ids['salesman'].'&date=2026-01-05');

        $res->assertOk();
        $this->assertSame(1, $res->json('data.total_count'));
        $this->assertSame(1, $res->json('data.located_count'));
        $this->assertTrue($res->json('data.points.0.has_location'));
        $this->assertSame(1, $res->json('data.points.0.seq'));
    }

    public function test_visit_report_endpoints_require_auth(): void
    {
        $this->withToken('');
        $this->getJson('admin/business/visit/achievement')->assertStatus(401);
        $this->getJson('admin/business/visit/schedule')->assertStatus(401);
    }

    private function seedVisitData(): void
    {
        $now = now();

        $this->ids['salesman'] = DB::table('auth_user')->insertGetId([
            'username' => 'salesman_'.bin2hex(random_bytes(4)),
            'password' => bcrypt('secret123'),
            'real_name' => '王业务员',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->ids['route'] = DB::table('routes')->insertGetId([
            'code' => 'R'.strtoupper(bin2hex(random_bytes(3))),
            'name' => '城东线路',
            'employee_id' => $this->ids['salesman'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 线路下 2 个客户 → 计划客户数 = 2
        $customerIds = [];
        foreach (['甲客户', '乙客户'] as $i => $name) {
            $customerIds[] = DB::table('customers')->insertGetId([
                'code' => 'C'.strtoupper(bin2hex(random_bytes(3))),
                'name' => $name,
                'route_id' => $this->ids['route'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->ids['customers'] = $customerIds;

        // 1/5、1/12（第 1、2 周）、3/8 —— 覆盖月度与年度两种切桶
        $visits = [
            ['2026-01-05 09:00:00', $customerIds[0], 30.0, 120.0],
            ['2026-01-12 10:00:00', $customerIds[1], 31.0, 121.0],
            ['2026-03-08 11:00:00', $customerIds[0], 32.0, 122.0],
        ];
        foreach ($visits as [$time, $customerId, $lat, $lng]) {
            DB::table('visit_logs')->insert([
                'visit_no' => 'V'.strtoupper(bin2hex(random_bytes(4))),
                'employee_id' => $this->ids['salesman'],
                'customer_id' => $customerId,
                'route_id' => $this->ids['route'],
                'checkin_time' => $time,
                'checkout_time' => $time,
                'visit_duration' => 20,
                'checkin_address' => '测试地址',
                'checkin_lat' => $lat,
                'checkin_lng' => $lng,
                'visit_result' => '成交',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
