<?php

namespace Tests\VanSales\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 车销上交货款端到端流程测试
 *
 * 纯台账型设计验证：
 * ① pendingSummary 实时计算待上交（approved 销售单 paid_amount − confirmed 上交）；
 * ② store 校验不超过待上交，FIFO 关联销售单到 items；
 * ③ confirm 写一条 cash_flows(related_type=VanRemit)，与 approve 的 VanSaleOrder 区分；
 * ④ reject 不写流水、销售单回到可上交池。
 */
class VanRemitFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_summary_computes_received_minus_remitted(): void
    {
        $admin = $this->actingAsAdmin();

        // 两张已确认销售单：现金 100 + 微信 50
        $this->insertApprovedSaleOrder($admin->id, 'cash', 100);
        $this->insertApprovedSaleOrder($admin->id, 'wechat', 50);

        $res = $this->getJson('/admin/business/van-remit/pending-summary?salesman_id='.$admin->id);
        $res->assertOk();

        $summary = $res->json('data.summary');
        $this->assertEquals(100.0, $summary['现金']['pending'], '现金待上交应为 100');
        $this->assertEquals(50.0, $summary['微信']['pending'], '微信待上交应为 50');
        $this->assertEquals(150.0, $res->json('data.total_pending'));
    }

    public function test_store_rejects_amount_exceeding_pending(): void
    {
        $admin = $this->actingAsAdmin();
        $this->insertApprovedSaleOrder($admin->id, 'cash', 100);

        $res = $this->postJson('/admin/business/van-remit', [
            'salesman_id' => $admin->id,
            'cash_amount' => 150, // 超过待上交 100
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('不能超过待上交', $res->json('message'));
        $this->assertSame(0, DB::table('van_remit')->count(), '超额不应创建上交单');
    }

    public function test_confirm_writes_cash_flow_with_vanremit_related_type(): void
    {
        $admin = $this->actingAsAdmin();
        $this->insertApprovedSaleOrder($admin->id, 'cash', 100);

        // 上交 100 现金
        $storeRes = $this->postJson('/admin/business/van-remit', [
            'salesman_id' => $admin->id,
            'cash_amount' => 100,
        ]);
        $storeRes->assertStatus(200);
        $remitId = $storeRes->json('data.id');

        // store 不写 cash_flow
        $this->assertSame(0, DB::table('cash_flows')->where('related_type', 'VanRemit')->count(), 'store 阶段不应写流水');

        // 确认
        $confirmRes = $this->postJson("/admin/business/van-remit/{$remitId}/confirm");
        $confirmRes->assertStatus(200);

        // confirm 写一条 cash_flows(related_type=VanRemit)
        $flow = DB::table('cash_flows')->where('related_type', 'VanRemit')->where('related_id', $remitId)->first();
        $this->assertNotNull($flow, 'confirm 应写一条 VanRemit 流水');
        $this->assertSame('receive', $flow->flow_type);
        $this->assertEquals(100.0, (float) $flow->amount);
        $this->assertSame('confirmed', DB::table('van_remit')->where('id', $remitId)->value('status'));

        // 与销售收款流水区分：approve 写的是 related_type=VanSaleOrder，不是 VanRemit
        $this->assertSame(0, DB::table('cash_flows')->where('related_type', 'VanSaleOrder')->count(), '本测试未走 approve，不应有 VanSaleOrder 流水');
    }

    public function test_reject_does_not_write_cash_flow_and_returns_to_pool(): void
    {
        $admin = $this->actingAsAdmin();
        $this->insertApprovedSaleOrder($admin->id, 'cash', 100);

        $storeRes = $this->postJson('/admin/business/van-remit', [
            'salesman_id' => $admin->id,
            'cash_amount' => 100,
        ]);
        $remitId = $storeRes->json('data.id');

        $this->postJson("/admin/business/van-remit/{$remitId}/reject", ['reject_reason' => '金额不符'])->assertStatus(200);

        $this->assertSame('rejected', DB::table('van_remit')->where('id', $remitId)->value('status'));
        $this->assertSame(0, DB::table('cash_flows')->where('related_type', 'VanRemit')->count(), '驳回不应写流水');

        // 驳回后待上交回到 100（已上交 confirmed 为 0）
        $res = $this->getJson('/admin/business/van-remit/pending-summary?salesman_id='.$admin->id);
        $this->assertEquals(100.0, $res->json('data.summary.现金.pending'), '驳回后现金应回到可上交池');
    }

    public function test_store_fifo_links_oldest_sale_orders(): void
    {
        $admin = $this->actingAsAdmin();
        // 三张现金销售单：30、30、40，共 100
        $oldId = $this->insertApprovedSaleOrder($admin->id, 'cash', 30);
        $this->insertApprovedSaleOrder($admin->id, 'cash', 30);
        $this->insertApprovedSaleOrder($admin->id, 'cash', 40);

        // 上交 50 现金 → FIFO 应先消费最旧的两张（30+30=60，但只需 50，故 30+20）
        $storeRes = $this->postJson('/admin/business/van-remit', [
            'salesman_id' => $admin->id,
            'cash_amount' => 50,
        ]);
        $storeRes->assertStatus(200);
        $remitId = $storeRes->json('data.id');

        $items = DB::table('van_remit_items')->where('remit_id', $remitId)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(1, $items->count(), '应有至少一条明细');

        // FIFO：第一条应关联最旧销售单
        $this->assertSame((string) $oldId, (string) $items->first()->sale_order_id, 'FIFO 应先关联最旧销售单');

        // 明细金额合计 = 50
        $this->assertEquals(50.0, (float) $items->sum('amount'));
    }

    /**
     * 直接插入一张已确认车销销售单（跳过 approve 流程，remit 只依赖数据状态）。
     */
    private function insertApprovedSaleOrder(int $salesmanId, string $method, float $paidAmount): int
    {
        return DB::table('van_sale_orders')->insertGetId([
            'order_no' => 'VXS'.date('Ymd').str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'salesman_id' => $salesmanId,
            'salesman_name' => '测试业务员',
            'customer_id' => null,
            'customer_name' => '测试客户',
            'sale_date' => now()->toDateString(),
            'total_qty' => 1,
            'total_amount' => $paidAmount,
            'paid_amount' => $paidAmount,
            'payment_method' => $method,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
