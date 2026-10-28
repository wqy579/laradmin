<?php

namespace Tests\Cash\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashHandover;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Tests\TestCase;

/**
 * D2 验收：收入上交款（业务员把代收的客户货款上交出纳）
 *
 *  - 明细「本次上交」合计 ≠ 表头金额：服务端 422，且不落任何单据（不是只拦前端）
 *  - 单号自动生成为 SC 前缀，明细排序从 1 起，订单号/客户名冗余落库
 *  - 确认后按「表头金额」记一条 income 流水，且回写 ORDER 域订单已收与收款状态（不是本域自嗨账）
 *  - 确认幂等：重复确认不重复记流水、不重复累加订单已收
 *  - 列表 stat 的 pending_count 供财务月结 Step2 统计未确认上交款
 */
class CashHandoverTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $account;

    private Customer $customer;

    private Customer $customer2;

    private int $warehouseId = 0;

    private int $salesmanId = 0;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // 订单的 salesman_id 外键指向 auth_user，用登录用户本身当业务员
        $this->salesmanId = (int) $this->actingAsAdmin(['real_name' => '张三'])->id;

        $this->account = CashAccount::create([
            'name' => '现金主账户',
            'type' => CashAccount::TYPE_CASH,
            'account_no' => '6222020200112233445',
            'opening_balance' => 1000,
            'status' => CashAccount::STATUS_ENABLED,
            'sort' => 1,
        ]);

        $this->customer = Customer::create(['code' => 'C1', 'name' => '测试客户', 'is_active' => true]);
        $this->customer2 = Customer::create(['code' => 'C2', 'name' => '二号客户', 'is_active' => true]);
        // 仓库只是订单外键的最小夹具，裸表插入，测试不额外依赖 Stock 模块
        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'WH1',
            'name' => '主仓库',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(array $overrides = []): SalesOrder
    {
        $this->seq++;

        return SalesOrder::create(array_merge([
            'order_no' => 'XS'.date('Ymd').str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'order_type' => 'normal',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouseId,
            'order_date' => '2026-10-10',
            'total_amount' => 1000,
            'total_qty' => 10,
            'paid_amount' => 0,
            'status' => 'approved',
            'payment_status' => '未收款',
            'salesman_id' => $this->salesmanId,
            'salesman_name' => '张三',
        ], $overrides));
    }

    /**
     * @param  array<int, array{sales_order_id: int, handover_amount: float}>  $items
     */
    private function handoverPayload(array $items, ?float $amount = null, array $overrides = []): array
    {
        return array_merge([
            'handover_date' => '2026-10-20',
            'salesman_id' => $this->salesmanId,
            'salesman_name' => '张三',
            'account_id' => $this->account->id,
            'amount' => $amount ?? array_sum(array_column($items, 'handover_amount')),
            'remark' => '今日代收货款上交',
            'items' => $items,
        ], $overrides);
    }

    private function pendingHandover(SalesOrder $order, float $amount = 500): CashHandover
    {
        $resp = $this->postJson('/admin/cash/handover', $this->handoverPayload([
            ['sales_order_id' => $order->id, 'handover_amount' => $amount],
        ]))->assertOk();

        return CashHandover::findOrFail($resp->json('data.id'));
    }

    public function test_store_rejects_when_item_sum_not_equal_header_amount(): void
    {
        $order = $this->order();

        // 明细合计 400 ≠ 表头 500：服务端必须拦下，且不留脏单
        $resp = $this->postJson('/admin/cash/handover', $this->handoverPayload(
            [['sales_order_id' => $order->id, 'handover_amount' => 400]],
            500
        ))->assertStatus(422);

        $this->assertSame('明细「本次上交」合计需与表头金额一致', $resp->json('message'));
        $this->assertSame(0, CashHandover::query()->count());
        $this->assertSame(0, DB::table('cash_handover_item')->count());
        $this->assertSame(0.0, (float) $order->fresh()->paid_amount);
    }

    public function test_store_persists_header_and_items_with_sort_and_redundant_fields(): void
    {
        $o1 = $this->order(['total_amount' => 1000]);
        $o2 = $this->order(['customer_id' => $this->customer2->id, 'total_amount' => 600]);

        $resp = $this->postJson('/admin/cash/handover', $this->handoverPayload([
            ['sales_order_id' => $o1->id, 'handover_amount' => 1000],
            ['sales_order_id' => $o2->id, 'handover_amount' => 200],
        ], 1200))->assertOk();

        $no = $resp->json('data.no');
        $this->assertStringStartsWith('SC', $no);
        $this->assertSame('SC'.date('Ymd').'0001', $no);
        $this->assertSame('待确认', $resp->json('data.status_label'));
        $this->assertSame(2, $resp->json('data.order_count'));
        $this->assertSame('现金主账户', $resp->json('data.account_name'));

        $handover = CashHandover::query()->with('items')->findOrFail($resp->json('data.id'));
        $this->assertSame($no, $handover->handover_no);
        $this->assertSame(CashHandover::STATUS_PENDING, $handover->status);
        $this->assertSame('2026-10-20', $handover->handover_date->toDateString());
        $this->assertEquals(1200.00, (float) $handover->amount);
        $this->assertNull($handover->confirmed_at);

        // 明细排序从 1 起，订单号/客户名等冗余字段来自订单快照
        $this->assertSame([1, 2], $handover->items->pluck('sort')->all());
        $this->assertSame([$o1->id, $o2->id], $handover->items->pluck('sales_order_id')->all());
        $this->assertSame([$o1->order_no, $o2->order_no], $handover->items->pluck('order_no')->all());
        $this->assertSame(['测试客户', '二号客户'], $handover->items->pluck('customer_name')->all());
        $this->assertSame([1000.0, 200.0], array_map('floatval', $handover->items->pluck('handover_amount')->all()));
        $this->assertSame(1, (int) $handover->items[0]->sort);

        // 详情接口回带明细
        $show = $this->getJson("/admin/cash/handover/{$handover->id}")->assertOk();
        $this->assertSame($no, $show->json('data.no'));
        $this->assertCount(2, $show->json('data.items'));
        $this->assertSame($o1->order_no, $show->json('data.items.0.order_no'));
        $this->assertSame('测试客户', $show->json('data.items.0.customer_name'));
    }

    public function test_store_rejects_unknown_sales_order(): void
    {
        $order = $this->order();

        $resp = $this->postJson('/admin/cash/handover', $this->handoverPayload([
            ['sales_order_id' => 999999, 'handover_amount' => 500],
            ['sales_order_id' => $order->id, 'handover_amount' => 500],
        ], 1000))->assertStatus(422);

        $this->assertSame('关联订单不存在或已被删除', $resp->json('message'));
        $this->assertSame(0, CashHandover::query()->count());
    }

    public function test_confirm_creates_one_income_flow_of_header_amount(): void
    {
        $handover = $this->pendingHandover($this->order(['total_amount' => 1000]), 300);
        $this->assertSame(300.0, (float) $handover->amount);

        $resp = $this->postJson("/admin/cash/handover/{$handover->id}/confirm")->assertOk();
        $this->assertSame('已确认', $resp->json('message'));
        $this->assertSame(CashHandover::STATUS_CONFIRMED, $resp->json('data.status'));

        $flows = DB::table('cash_flow')->where('related_type', 'cash_handover')->get();
        $this->assertCount(1, $flows);
        $flow = $flows[0];
        // 验收口径：流水金额 = 表头金额，方向收入，挂到选定账户
        $this->assertEquals(300.00, (float) $flow->amount);
        $this->assertSame('income', $flow->direction);
        $this->assertSame($this->account->id, (int) $flow->account_id);
        $this->assertSame($handover->id, (int) $flow->related_id);
        $this->assertSame($handover->handover_no, $flow->flow_no);
        // 流水日期跟随单据上交日期（裸查询会带上 sqlite 的零值时间部分）
        $this->assertStringStartsWith('2026-10-20', (string) $flow->flow_date);

        $fresh = $handover->fresh();
        $this->assertSame(CashHandover::STATUS_CONFIRMED, $fresh->status);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertSame('已确认', CashHandover::statusLabel($fresh->status));
        $this->assertStringContainsString('收入上交款', (string) $flow->summary);
    }

    public function test_confirm_writes_back_order_paid_amount_and_payment_status(): void
    {
        // 整单收清：1000 全交 → 已收款
        $full = $this->order(['total_amount' => 1000, 'paid_amount' => 0]);
        // 部分收款：1000 已收 200，本次交 300 → 500 部分收款
        $partial = $this->order(['total_amount' => 1000, 'paid_amount' => 200, 'payment_status' => '部分收款']);

        $resp = $this->postJson('/admin/cash/handover', $this->handoverPayload([
            ['sales_order_id' => $full->id, 'handover_amount' => 1000],
            ['sales_order_id' => $partial->id, 'handover_amount' => 300],
        ], 1300))->assertOk();

        $id = $resp->json('data.id');
        $this->postJson("/admin/cash/handover/{$id}/confirm")->assertOk();

        $fullFresh = $full->fresh();
        $this->assertEquals(1000.00, (float) $fullFresh->paid_amount);
        $this->assertSame('已收款', $fullFresh->payment_status);
        $this->assertSame('已收款', $fullFresh->status);

        $partialFresh = $partial->fresh();
        $this->assertEquals(500.00, (float) $partialFresh->paid_amount);
        $this->assertSame('部分收款', $partialFresh->payment_status);
        $this->assertSame('approved', $partialFresh->status);
    }

    public function test_confirm_is_idempotent(): void
    {
        $order = $this->order(['total_amount' => 1000, 'paid_amount' => 0]);
        $handover = $this->pendingHandover($order, 1000);

        $this->postJson("/admin/cash/handover/{$handover->id}/confirm")->assertOk();
        // 第二次确认（重复点击 / 批量再确认）不报错，也不再记账
        $second = $this->postJson("/admin/cash/handover/{$handover->id}/confirm")->assertOk();
        $this->assertSame('已确认', $second->json('message'));
        $this->assertSame(CashHandover::STATUS_CONFIRMED, $second->json('data.status'));

        $this->assertSame(
            1,
            DB::table('cash_flow')->where('related_type', 'cash_handover')->where('related_id', $handover->id)->count()
        );
        $this->assertEquals(1000.00, (float) $order->fresh()->paid_amount);
        $this->assertSame('已收款', $order->fresh()->payment_status);
    }

    public function test_batch_confirm_confirms_all_pending_docs_and_returns_count(): void
    {
        $h1 = $this->pendingHandover($this->order(), 400);
        $h2 = $this->pendingHandover($this->order(), 600);
        $h3 = $this->pendingHandover($this->order(), 200);

        $resp = $this->postJson('/admin/cash/handover/batch-confirm', [
            'ids' => [$h1->id, $h2->id, $h3->id],
        ])->assertOk();

        $this->assertSame(3, $resp->json('data.confirmed'));
        $this->assertSame(3, CashHandover::query()->where('status', CashHandover::STATUS_CONFIRMED)->count());
        $this->assertSame(3, DB::table('cash_flow')->where('related_type', 'cash_handover')->count());

        // 再点一次：已确认的走幂等分支，不产生第二条流水
        $this->postJson('/admin/cash/handover/batch-confirm', ['ids' => [$h1->id, $h2->id]])->assertOk();
        $this->assertSame(3, DB::table('cash_flow')->where('related_type', 'cash_handover')->count());
    }

    public function test_list_stat_reports_pending_count_and_respects_status_filter(): void
    {
        $this->pendingHandover($this->order(), 400);
        $this->pendingHandover($this->order(), 600);
        $confirmed = $this->pendingHandover($this->order(), 250);
        $this->postJson("/admin/cash/handover/{$confirmed->id}/confirm")->assertOk();

        $data = $this->getJson('/admin/cash/handover')->assertOk()->json('data');
        $this->assertSame(3, $data['total']);
        $this->assertSame(3, $data['stat']['count']);
        // 财务月结 Step2 统计的就是这个未确认张数
        $this->assertSame(2, $data['stat']['pending_count']);
        $this->assertSame(1, $data['stat']['confirmed_count']);
        $this->assertEquals(1250.00, $data['stat']['amount_sum']);

        // status 过滤生效：只出已确认那一张，pending_count 随之归零
        $confirmedOnly = $this->getJson('/admin/cash/handover?status=confirmed')->assertOk()->json('data');
        $this->assertSame(1, $confirmedOnly['total']);
        $this->assertSame(CashHandover::STATUS_CONFIRMED, $confirmedOnly['list'][0]['status']);
        $this->assertSame(0, $confirmedOnly['stat']['pending_count']);
        $this->assertSame(1, $confirmedOnly['stat']['confirmed_count']);

        $pendingOnly = $this->getJson('/admin/cash/handover?status=pending')->assertOk()->json('data');
        $this->assertSame(2, $pendingOnly['total']);
        $this->assertSame(2, $pendingOnly['stat']['pending_count']);
    }

    public function test_orders_picker_excludes_ids(): void
    {
        $keep = $this->order(['total_amount' => 500]);
        $exclude = $this->order(['total_amount' => 700]);

        $all = $this->getJson('/admin/cash/handover/orders')->assertOk();
        $this->assertSame(2, $all->json('data.total'));

        $filtered = $this->getJson("/admin/cash/handover/orders?exclude_ids[]={$exclude->id}")->assertOk();
        $this->assertSame(1, $filtered->json('data.total'));
        $this->assertSame($keep->id, $filtered->json('data.list.0.id'));
        $this->assertSame($keep->order_no, $filtered->json('data.list.0.order_no'));
        $this->assertSame('测试客户', $filtered->json('data.list.0.customer_name'));
        $this->assertEquals(500.0, $filtered->json('data.list.0.unpaid_amount'));
    }

    public function test_orders_picker_hides_settled_and_red_flushed_orders(): void
    {
        $unpaid = $this->order(['total_amount' => 500]);
        $this->order(['total_amount' => 500, 'paid_amount' => 500]);          // 已收清，不再出现在选择列表
        $this->order(['total_amount' => 500, 'status' => '已红冲']);            // 已红冲
        $this->order(['total_amount' => 500, 'status' => 'cancelled']);       // 已作废

        $list = $this->getJson('/admin/cash/handover/orders')->assertOk()->json('data.list');

        $this->assertCount(1, $list);
        $this->assertSame($unpaid->id, $list[0]['id']);
    }
}
