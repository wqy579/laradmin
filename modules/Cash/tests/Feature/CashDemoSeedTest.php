<?php

namespace Tests\Cash\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Seeders\CashDemoSeeder;
use Modules\Order\Models\SalesOrder;
use Tests\TestCase;

/**
 * QA-02 数据面回归：CashDemoSeeder 跑完后，简报里那 11 个页面的接口必须返回
 * 真正的 JSON 且 data.list 非空（简报原文的自查口径：「F12 确认接口返回 JSON
 * 且 data.list 非空」）。
 *
 * 为什么把断言压在 HTTP 接口而不是表行数：本 QA 报的现象是页面空列表，
 * 表里有数而接口没吐出来一样算没修好。
 *
 * 上交款区块依赖「未收讫销售订单 ≥ 2 张」（缺则整块跳过，不去伪造订单行以外的
 * 业务链路），所以这里先铺两张裸订单把前置立起来，
 * 顺带把「跳过」和「铺满」两条分支都踩到。
 */
class CashDemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_seeders_make_every_cash_page_return_rows(): void
    {
        $this->seedUnpaidOrders(2);
        $this->seed(CashDemoSeeder::class);

        foreach ([
            '资金账户/收支查询的账户下拉' => '/admin/cash/accounts/options',
            '刷卡流水 /cashier/card' => '/admin/cash/card-txns',
            // 上交款录入页与查询页共用 cash/handover 列表接口（无独立 query 路由）
            '上交款 /cash/handover' => '/admin/cash/handover',
            '积分规则 /points/rule' => '/admin/cash/integral/rules',
            '积分发放 /points/grant' => '/admin/cash/integral/grants',
            '积分核销 /points/use' => '/admin/cash/integral/verifies',
            '积分查询-会员汇总 /points/query' => '/admin/cash/integral/ledger/members',
        ] as $label => $url) {
            $res = $this->getJson($url)->assertOk();
            $this->assertNotEmpty(
                $res->json('data.list'),
                "{$label}（{$url}）返回空列表，QA-02 的「data.list 非空」未达成"
            );
        }

        // 收支查询页：选中账户后的流水列表
        $accountId = (int) $this->getJson('/admin/cash/accounts/options')
            ->assertOk()->json('data.list.0.id');
        $this->assertGreaterThan(0, $accountId);
        $res = $this->getJson("/admin/cash/accounts/{$accountId}/flows")->assertOk();
        $this->assertNotEmpty($res->json('data.list'), '收支查询选定账户后仍为空列表');

        // 刷卡页筛选下拉：结构是 {customers, salesmen, ...}，不是 data.list
        $opts = $this->getJson('/admin/cash/card-txns/options')->assertOk()->json('data');
        $this->assertNotEmpty($opts['customers'] ?? [], '刷卡页客户下拉为空');
        $this->assertNotEmpty($opts['salesmen'] ?? [], '刷卡页业务员下拉为空');

        // 积分流水必须先选会员（未选时服务端 422「请选择会员」，这是设计而非 bug）
        $memberId = (int) $this->getJson('/admin/cash/integral/ledger/members')
            ->assertOk()->json('data.list.0.member_id');
        $this->assertGreaterThan(0, $memberId, '积分汇总没出会员，流水页无从选起');
        $res = $this->getJson('/admin/cash/integral/ledger/flows?member_id='.$memberId)->assertOk();
        $this->assertNotEmpty($res->json('data.list'), '选定会员后积分流水仍为空');
    }

    /** 工作台的当日卡片依赖种子写进「今天」的流水，历史日期不算。 */
    public function test_workbench_summary_is_non_zero_after_seeding(): void
    {
        $this->seedUnpaidOrders(2);
        $this->seed(CashDemoSeeder::class);

        $data = $this->getJson('/admin/cash/dashboard/summary')->assertOk()->json('data');

        $this->assertGreaterThan(0, (float) $data['today_income'], '工作台今日收入为 0，说明流水没落在今天');
        $this->assertGreaterThan(0, (float) $data['today_card_amount'], '工作台今日刷卡为 0');
        $this->assertGreaterThan(0, (float) $data['account_total']);
        $this->assertGreaterThan(0, (int) $data['pending_handover_count'], '待确认上交款卡片应有样本');
    }

    public function test_seeding_twice_does_not_duplicate_rows(): void
    {
        $this->seedUnpaidOrders(2);
        $this->seed(CashDemoSeeder::class);

        $before = [
            'cash_flow' => DB::table('cash_flow')->count(),
            'cash_card_txn' => DB::table('cash_card_txn')->count(),
            'cash_integral_rule' => DB::table('cash_integral_rule')->count(),
            'cash_handover' => DB::table('cash_handover')->count(),
        ];

        $this->seed(CashDemoSeeder::class);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "重跑后 {$table} 行数翻倍");
        }
    }

    /**
     * 积分必须走服务落账：members.points 与台账由 CashIntegralService 写，
     * 而不是种子手插——这是「演示数据口径与真实业务同源」的那条承诺。
     */
    public function test_integral_points_are_settled_through_the_service(): void
    {
        $this->seedUnpaidOrders(2);
        $this->seed(CashDemoSeeder::class);

        $member = DB::table('members')->where('member_no', 'HYDEMO00000001')->first();
        $this->assertNotNull($member, '演示会员未建');
        $this->assertGreaterThan(0, (int) $member->points, '演示会员积分未入账');

        $logs = DB::table('member_consume_logs')->where('member_id', $member->id)
            ->whereIn('consume_type', ['points_grant', 'points_use'])->get();
        $this->assertNotEmpty($logs, '积分台账没写，积分查询页会是空的');

        $granted = (int) $logs->where('consume_type', 'points_grant')->sum('earn_points');
        $used = (int) $logs->where('consume_type', 'points_use')->sum('use_points');
        $this->assertLessThan($granted, $used, '核销额应小于已发放，否则「≤ 可用余额」这条判据没被真实走通');
        $this->assertSame($granted - $used, (int) $member->points, '积分余额与台账累计不一致');
    }

    /** 前置订单不足时上交款整块跳过：种子不该因此报错，也不该伪造订单。 */
    public function test_handover_block_is_skipped_without_unpaid_orders(): void
    {
        $this->seed(CashDemoSeeder::class);

        $this->assertSame(0, DB::table('cash_handover')->count(), '没有未收讫订单时不应造上交款');
        // 其余区块不受影响
        $this->assertGreaterThan(0, DB::table('cash_flow')->count());
        $this->assertGreaterThan(0, DB::table('cash_card_txn')->count());
    }

    /**
     * 铺未收讫订单：只测「种子读得到订单」，不复用 Order 域的下单流程。
     *
     * 客户/仓库用 TestCase 现成的 helper 自建（都在 Order/Stock 里，Cash 本来就
     * 允许依赖），不去 seed Business 的主数据：那会让 Cash 多出一条 Cash->Business
     * 依赖边，ArchitectureTest 的白名单是为运行时代码设的，不该被测试图省事拖宽。
     */
    private function seedUnpaidOrders(int $count): void
    {
        // 默认种子链路只保证「存在超级管理员用户」，不保证 username=admin，
        // 故业务员用 makeAdmin 现造，避免 FK 悬空。
        $salesman = $this->makeAdmin(['real_name' => '演示业务员']);
        $customer = $this->makeCustomer(['name' => '演示客户']);
        $warehouse = $this->makeWarehouse();

        for ($i = 1; $i <= $count; $i++) {
            SalesOrder::create([
                'order_no' => 'XSTEST'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'order_type' => 'normal',
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'order_date' => now()->subDays(3)->toDateString(),
                'total_amount' => 1000 * $i,
                'total_qty' => 10,
                'paid_amount' => 0,
                'status' => 'approved',
                'payment_status' => '未收款',
                'salesman_id' => $salesman->id,
                'salesman_name' => '演示业务员',
            ]);
        }
    }
}
