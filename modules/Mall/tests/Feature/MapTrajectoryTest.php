<?php

namespace Tests\Mall\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Order\Models\VisitLog;
use Tests\TestCase;

/**
 * F5 验收 1/3：行程地图轨迹。
 *
 *  - 数据源仅 visit_logs（签到经纬度）+ customers 档案坐标，无假数据；
 *  - salesmen：当日有拜访记录的业务员下拉（去重，带员工编码）；
 *  - trajectory：当日按签到时间排序的点序列，seq 从 1 开始；
 *  - 无签到定位时回落客户档案坐标，has_location 标记是否可画点；
 *  - customers：全部带坐标的配送点位（非 0）。
 */
class MapTrajectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '地图管理员']);
    }

    public function test_salesmen_lists_only_date_scoped_distinct_visitors(): void
    {
        $visitor = $this->makeAdmin(['real_name' => '业务员甲']);
        $other = $this->makeAdmin(['real_name' => '业务员乙']);
        $customer = $this->makeCustomer();
        $today = now()->toDateString();

        $this->makeVisit($visitor->id, $customer->id, $today.' 08:00:00', 30.5, 120.1);
        $this->makeVisit($visitor->id, $customer->id, $today.' 11:00:00', 30.6, 120.2);
        $this->makeVisit($other->id, $customer->id, now()->subDay()->toDateString().' 09:00:00', 30.7, 120.3);

        $resp = $this->getJson('/admin/f5/map/salesmen?date='.$today)->assertOk();

        $salesmen = $resp->json('data.salesmen');
        $this->assertCount(1, $salesmen, '只应出现当天有拜访的业务员，且按业务员去重');
        $this->assertSame($visitor->id, $salesmen[0]['id']);
        $this->assertSame('业务员甲', $salesmen[0]['name']);
        $this->assertSame($today, $resp->json('data.date'));
    }

    public function test_trajectory_orders_points_by_checkin_time_and_sequences(): void
    {
        $visitor = $this->makeAdmin(['real_name' => '业务员甲']);
        $customer = $this->makeCustomer(['latitude' => 30.55, 'longitude' => 120.11]);
        $today = now()->toDateString();

        $late = $this->makeVisit($visitor->id, $customer->id, $today.' 10:00:00', 30.6, 120.2);
        $early = $this->makeVisit($visitor->id, $customer->id, $today.' 08:00:00', 30.5, 120.1);

        $resp = $this->getJson('/admin/f5/map/trajectory?employee_id='.$visitor->id.'&date='.$today)->assertOk();

        $points = $resp->json('data.points');
        $this->assertCount(2, $points);
        $this->assertSame($early->id, $points[0]['id'], '应按签到时间升序');
        $this->assertSame($late->id, $points[1]['id']);
        $this->assertSame([1, 2], array_column($points, 'seq'));
        $this->assertSame($today, $resp->json('data.date'));
        $this->assertSame('业务员甲', $resp->json('data.employee_name'));
        $this->assertSame(2, $resp->json('data.located_count'));
        $this->assertSame(2, $resp->json('data.total_count'));
    }

    public function test_trajectory_falls_back_to_customer_coords_when_no_checkin_gps(): void
    {
        $visitor = $this->makeAdmin(['real_name' => '业务员甲']);
        $customer = $this->makeCustomer(['latitude' => 30.88, 'longitude' => 121.66]);
        $today = now()->toDateString();

        // 无签到定位，但客户档案有坐标
        $this->makeVisit($visitor->id, $customer->id, $today.' 09:00:00', null, null);

        $resp = $this->getJson('/admin/f5/map/trajectory?employee_id='.$visitor->id.'&date='.$today)->assertOk();

        $point = $resp->json('data.points.0');
        $this->assertTrue($point['has_location']);
        $this->assertSame(30.88, $point['lat']);
        $this->assertSame(121.66, $point['lng']);
        $this->assertSame($customer->name, $point['customer_name']);
        $this->assertSame(1, $resp->json('data.located_count'));
    }

    public function test_trajectory_marks_unlocatable_point(): void
    {
        $visitor = $this->makeAdmin(['real_name' => '业务员甲']);
        $customer = $this->makeCustomer(); // 无坐标
        $today = now()->toDateString();

        $this->makeVisit($visitor->id, $customer->id, $today.' 09:00:00', null, null);

        $resp = $this->getJson('/admin/f5/map/trajectory?employee_id='.$visitor->id.'&date='.$today)->assertOk();

        $point = $resp->json('data.points.0');
        $this->assertFalse($point['has_location']);
        $this->assertNull($point['lat']);
        $this->assertNull($point['lng']);
        $this->assertSame(0, $resp->json('data.located_count'));
    }

    public function test_customers_returns_only_located_nonzero_points(): void
    {
        $this->makeCustomer(['name' => '有坐标客户', 'latitude' => 30.1, 'longitude' => 120.1]);
        $this->makeCustomer(['name' => '零坐标客户', 'latitude' => 0, 'longitude' => 0]);
        $this->makeCustomer(['name' => '无坐标客户']);

        $resp = $this->getJson('/admin/f5/map/customers')->assertOk();

        $names = array_column($resp->json('data.customers'), 'name');
        $this->assertSame(['有坐标客户'], $names);
        $this->assertSame(30.1, $resp->json('data.customers.0.lat'));
        $this->assertSame(120.1, $resp->json('data.customers.0.lng'));
    }

    public function test_trajectory_requires_employee(): void
    {
        $this->getJson('/admin/f5/map/trajectory')->assertStatus(422);
    }

    public function test_trajectory_single_point_renders_without_degenerate_polyline(): void
    {
        $visitor = $this->makeAdmin(['real_name' => '业务员甲']);
        $customer = $this->makeCustomer(['latitude' => 30.5, 'longitude' => 120.1]);
        $today = now()->toDateString();

        // 单点位：前端不得因 spanLat/spanLng 为 0 白屏或画退化的折线，需正常返回唯一可定位点。
        $this->makeVisit($visitor->id, $customer->id, $today.' 09:00:00', 30.5, 120.1);

        $resp = $this->getJson('/admin/f5/map/trajectory?employee_id='.$visitor->id.'&date='.$today)->assertOk();

        $this->assertFalse($resp->json('data.downsampled'), '单点不应抽稀');
        $this->assertSame(1, $resp->json('data.total_count'));
        $this->assertSame(1, $resp->json('data.rendered_count'));
        $this->assertSame(1, $resp->json('data.located_count'));

        $point = $resp->json('data.points.0');
        $this->assertCount(1, $resp->json('data.points'));
        $this->assertSame([1], array_column($resp->json('data.points'), 'seq'), '单点 seq 从 1 连续编号');
        $this->assertTrue($point['has_location']);
        $this->assertSame(30.5, $point['lat']);
        $this->assertSame(120.1, $point['lng']);
        $this->assertSame($customer->name, $point['customer_name']);
    }

    public function test_trajectory_downsamples_over_thousand_points_and_keeps_ends(): void
    {
        $visitor = $this->makeAdmin(['real_name' => '业务员甲']);
        $customer = $this->makeCustomer(['latitude' => 30.5, 'longitude' => 120.1]);
        $today = now()->toDateString();

        // 1500 点：超过 MAX_TRAJECTORY_POINTS=1000，应抽稀且保留首尾。
        $count = 1500;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $log = $this->makeVisit(
                $visitor->id,
                $customer->id,
                $today.' '.sprintf('08:%02d:%02d', intdiv($i, 60), $i % 60),
                30.5 + $i * 0.0001,
                120.1 + $i * 0.0001
            );
            $ids[] = $log->id;
        }

        $resp = $this->getJson('/admin/f5/map/trajectory?employee_id='.$visitor->id.'&date='.$today)->assertOk();

        $this->assertTrue($resp->json('data.downsampled'), '超过阈值应标记抽稀');
        $this->assertSame($count, $resp->json('data.total_count'), '原始总数不丢');
        $this->assertLessThanOrEqual(1000, $resp->json('data.rendered_count'), '渲染点数不得超过阈值');
        $this->assertGreaterThan(0, $resp->json('data.rendered_count'));

        $points = $resp->json('data.points');
        // 首尾点必保留（不丢关键端点）
        $this->assertSame($ids[0], $points[0]['id'], '首点必须保留');
        $this->assertSame($ids[$count - 1], $points[count($points) - 1]['id'], '尾点必须保留');
        // seq 仍连续编号
        $this->assertSame(range(1, count($points)), array_column($points, 'seq'));
    }

    public function test_trajectory_empty_when_no_visits(): void
    {
        $visitor = $this->makeAdmin(['real_name' => '业务员甲']);
        $today = now()->toDateString();

        // 该业务员当天没有拜访记录：应 200 + 空 points，且不报错、不白屏（前端走 FinEmpty 空态）。
        $resp = $this->getJson('/admin/f5/map/trajectory?employee_id='.$visitor->id.'&date='.$today)->assertOk();

        $this->assertSame('未知', $resp->json('data.employee_name'), '无记录时回退占位文案');
        $this->assertSame([], $resp->json('data.points'), '应为空点序列');
        $this->assertSame(0, $resp->json('data.total_count'));
        $this->assertSame(0, $resp->json('data.rendered_count'));
        $this->assertFalse($resp->json('data.downsampled'));
        $this->assertSame(0, $resp->json('data.located_count'));
    }

    public function test_trajectory_under_threshold_not_downsampled(): void
    {
        $visitor = $this->makeAdmin(['real_name' => '业务员甲']);
        $customer = $this->makeCustomer(['latitude' => 30.5, 'longitude' => 120.1]);
        $today = now()->toDateString();

        for ($i = 0; $i < 5; $i++) {
            $this->makeVisit($visitor->id, $customer->id, $today.' '.sprintf('09:%02d:00', $i), 30.5 + $i, 120.1 + $i);
        }

        $resp = $this->getJson('/admin/f5/map/trajectory?employee_id='.$visitor->id.'&date='.$today)->assertOk();

        $this->assertFalse($resp->json('data.downsampled'));
        $this->assertSame(5, $resp->json('data.rendered_count'));
        $this->assertSame(5, $resp->json('data.total_count'));
    }

    private function makeVisit(int $employeeId, int $customerId, string $checkinTime, ?float $lat, ?float $lng): VisitLog
    {
        return VisitLog::create([
            'visit_no' => 'V'.strtoupper(bin2hex(random_bytes(5))),
            'employee_id' => $employeeId,
            'customer_id' => $customerId,
            'checkin_time' => $checkinTime,
            'checkin_lat' => $lat,
            'checkin_lng' => $lng,
            'status' => 2,
            'created_by' => $employeeId,
        ]);
    }
}
