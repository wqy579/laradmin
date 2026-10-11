<?php

namespace Tests\Member\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Modules\Member\Models\MemberLevel;
use Modules\Member\Models\MemberRecharge;
use Modules\Member\Models\PointProduct;
use Modules\Member\Services\MemberService;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * 会员管理模块端到端测试。
 *
 * 覆盖：
 *   - 菜单契约（顶级 icon + 分组 component 空串 + path 指向第一个子路由）
 *   - 会员档案：创建 / 客户唯一约束 / 冻结解冻 / 有业务数据禁止删除
 *   - 积分规则：默认 10 条 / 更新 / 重置
 *   - 会员等级：内置 4 档 / LV0 不可禁用
 *   - 储值充值：审核到账 + 现金流水 + 台账 + 冲正幂等
 *   - 积分兑换：下单不扣分（审核时扣）/ 驳回退积分退库存
 *   - 订单联动：结算（扣储值+扣积分+发积分+累计消费+升级）/ 红冲对称退款
 *   - 资金安全：超额抵扣抛错 / points_settled 幂等
 */
class MemberFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    private int $customerId;

    private int $warehouseId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->actingAsAdmin()->id;

        // 会员管理迁移依赖 customers / warehouses / products / stocks 表，
        // 这里直接复用 TestCase 的工厂方法。
        $this->customerId = $this->makeCustomer()->id;
        $this->warehouseId = $this->makeWarehouse()->id;
        $this->productId = $this->makeProduct(['price_small' => 1, 'price_unit_small' => '个', 'unit_conversion' => 0, 'unit_conversion_medium' => 0])->id;
        Stock::create(['product_id' => $this->productId, 'warehouse_id' => $this->warehouseId, 'quantity' => 100000, 'frozen_qty' => 0]);
    }

    // ------------------------------------------------------------------ 菜单

    /** 顶级菜单必须带 ElIcon 前缀图标（MenuIconTest 契约） */
    public function test_member_menu_has_icon(): void
    {
        $meta = json_decode(DB::table('auth_permission')->where('name', 'member')->value('meta') ?? 'null', true) ?? [];

        $this->assertSame('ElIconUser', $meta['icon'] ?? '');
    }

    /** 分组菜单 component 必须为空串，path 指向第一个子路由 */
    public function test_member_menu_group_points_to_first_child_route(): void
    {
        $group = DB::table('auth_permission')->where('name', 'member')->firstOrFail();

        $this->assertEmpty($group->component, '分组菜单不得自带 component');

        $firstChild = DB::table('auth_permission')
            ->where('parent_id', $group->id)
            ->orderBy('sort')
            ->firstOrFail();

        $this->assertSame($firstChild->path, $group->path);
        $this->assertNotEmpty($firstChild->component);
    }

    /** 6 个子菜单齐全，路径与前端路由约定一致 */
    public function test_member_children_all_present(): void
    {
        $group = DB::table('auth_permission')->where('name', 'member')->firstOrFail();
        $children = DB::table('auth_permission')
            ->where('parent_id', $group->id)
            ->orderBy('sort')
            ->get()
            ->pluck('name')
            ->all();

        $this->assertSame(
            ['member.list', 'member.level', 'member.point-rule', 'member.recharge', 'member.exchange', 'member.consume-log'],
            $children
        );
    }

    // ------------------------------------------------------------------ 基础数据

    /** 迁移应播种 4 档等级 + 10 条积分规则 */
    public function test_default_levels_and_rules_seeded(): void
    {
        $this->assertSame(4, DB::table('member_levels')->count());
        $this->assertSame(10, DB::table('point_rules')->count());

        $lv0 = DB::table('member_levels')->where('level_no', 'LV0')->firstOrFail();
        $this->assertSame(0, (int) $lv0->upgrade_amount);
        $this->assertSame(100.0, (float) $lv0->discount_rate);

        // consume_ratio: 1 元得 1 积分
        $consumeRatio = json_decode(DB::table('point_rules')->where('rule_key', 'consume_ratio')->value('rule_value'), true);
        $this->assertSame(['amount' => 1, 'points' => 1], $consumeRatio);

        // deduct_ratio: 100 积分抵 1 元
        $deductRatio = json_decode(DB::table('point_rules')->where('rule_key', 'deduct_ratio')->value('rule_value'), true);
        $this->assertSame(['points' => 100, 'amount' => 1], $deductRatio);
    }

    /** 销售单必须带会员相关字段 */
    public function test_sales_orders_has_member_columns(): void
    {
        foreach (['member_id', 'use_balance', 'use_points', 'points_settled'] as $col) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('sales_orders', $col),
                "sales_orders 缺少 {$col}"
            );
        }
    }

    // ------------------------------------------------------------------ 会员档案

    /** 创建会员：单号 HY+Ymd+6位流水，默认 LV0 */
    public function test_member_store_generates_number_and_defaults(): void
    {
        $response = $this->postJson('/admin/business/member', [
            'customer_id' => $this->customerId,
            'name' => '张三',
            'phone' => '13800138000',
        ]);

        $response->assertOk()->assertJsonPath('message', '会员创建成功');

        $member = $response->json('data');
        $this->assertStringStartsWith('HY'.now()->format('Ymd'), $member['member_no']);
        $this->assertSame('张三', $member['name']);
        $this->assertSame($this->customerId, $member['customer_id']);
        $this->assertSame(0, (int) $member['points']);
        $this->assertSame('0.00', $member['balance']);
        $this->assertSame(1, (int) $member['level_id']);
        $this->assertSame('普通会员', $member['level']['name']);
    }

    /** customer_id 唯一：同一客户第二次创建返回已有会员 */
    public function test_member_store_dedups_by_customer(): void
    {
        $payload = ['customer_id' => $this->customerId, 'name' => '张三'];

        $first = $this->postJson('/admin/business/member', $payload)->json('data');
        $second = $this->postJson('/admin/business/member', ['customer_id' => $this->customerId, 'name' => '李四']);

        $this->assertSame('该客户已存在会员档案', $second->json('message'));
        $this->assertSame($first['id'], $second->json('data.id'));
        // 名字保持第一次创建的值
        $this->assertSame('张三', Member::find($first['id'])->name);
    }

    /** 冻结 / 解冻 */
    public function test_member_freeze_and_unfreeze(): void
    {
        $member = $this->makeMember();

        $this->postJson("/admin/business/member/{$member->id}/freeze")->assertOk();
        $this->assertSame(0, (int) $member->fresh()->status);

        $this->postJson("/admin/business/member/{$member->id}/unfreeze")->assertOk();
        $this->assertSame(1, (int) $member->fresh()->status);
    }

    /** 有储值 / 积分 / 消费记录的会员禁止物理删除，应改为冻结 */
    public function test_member_destroy_refused_when_has_business_data(): void
    {
        $member = $this->makeMember(['balance' => 100.00, 'points' => 500]);

        $this->deleteJson("/admin/business/member/{$member->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '该会员存在消费记录、储值或积分，不能删除，请改为冻结');

        $this->assertNotNull(Member::find($member->id));
    }

    /** 无业务数据的会员可删除 */
    public function test_member_destroy_allowed_when_empty(): void
    {
        $member = $this->makeMember();

        $this->deleteJson("/admin/business/member/{$member->id}")
            ->assertOk()
            ->assertJsonPath('message', '会员已删除');

        $this->assertNull(Member::find($member->id));
    }

    /** 客户一键转会员：by-customer 查询 + 搜索下拉 */
    public function test_member_by_customer_and_search(): void
    {
        $member = $this->makeMember(['customer_id' => $this->customerId, 'name' => '张三']);

        $byCustomer = $this->getJson("/admin/business/member/by-customer?customer_id={$this->customerId}");
        $byCustomer->assertOk();
        $this->assertSame($member->id, $byCustomer->json('data.id'));

        // 冻结会员不应出现在下单选择下拉里
        $frozen = $this->makeMember(['name' => '李四']);
        $this->postJson("/admin/business/member/{$frozen->id}/freeze")->assertOk();

        $this->getJson('/admin/business/member/search?keyword=三')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 冻结的会员查不到
        $this->getJson('/admin/business/member/search?keyword=李')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------ 积分规则

    /** 更新规则：只改已存在的 rule_key，不能新增 */
    public function test_point_rule_update_and_reset(): void
    {
        // 改 consume_ratio 为「2 元得 1 积分」
        $this->putJson('/admin/business/point-rule', [
            'rules' => [[
                'rule_key' => 'consume_ratio',
                'rule_value' => ['amount' => 2, 'points' => 1],
            ]],
        ])->assertOk()->assertJsonPath('data.updated', 1);

        $ratio = MemberService::ruleValue('consume_ratio');
        $this->assertSame(2, $ratio['amount']);
        $this->assertSame(1, $ratio['points']);

        // 不存在的新 key 被忽略
        $this->putJson('/admin/business/point-rule', [
            'rules' => [['rule_key' => 'nonexistent_key', 'rule_value' => ['x' => 1]]],
        ])->assertJsonPath('data.updated', 0);

        // 重置恢复默认
        $this->postJson('/admin/business/point-rule/reset')->assertOk();
        $ratio = MemberService::ruleValue('consume_ratio');
        $this->assertSame(['amount' => 1, 'points' => 1], $ratio);
    }

    // ------------------------------------------------------------------ 会员等级

    /** LV0（默认等级）禁止禁用 */
    public function test_member_level_lv0_cannot_be_disabled(): void
    {
        $lv0 = MemberLevel::where('level_no', 'LV0')->firstOrFail();

        $this->postJson("/admin/business/member-level/{$lv0->id}/disable")
            ->assertStatus(422)
            ->assertJsonPath('message', '默认等级（LV0）不能禁用');
    }

    /** 新建等级可删除，内置等级禁止删除 */
    public function test_member_level_system_level_not_deletable(): void
    {
        $lv1 = MemberLevel::where('level_no', 'LV1')->firstOrFail();

        $this->deleteJson("/admin/business/member-level/{$lv1->id}")
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------ 储值充值

    /** 审核充值：到账 + 写现金流水 + 写台账 */
    public function test_recharge_approve_credits_balance_and_writes_cash_flow(): void
    {
        $member = $this->makeMember(['balance' => 0.00, 'customer_id' => $this->customerId]);

        $response = $this->postJson('/admin/business/member-recharge', [
            'member_id' => $member->id,
            'recharge_amount' => 200.00,
            'gift_amount' => 20.00,
            'pay_method' => 'wechat',
        ]);

        $response->assertOk()->assertJsonPath('message', '充值单已创建，待审核');

        $rechargeId = $response->json('data.id');

        // 审核前不改变余额
        $this->assertSame('0.00', $member->fresh()->balance);

        // 审核通过
        $this->postJson("/admin/business/member-recharge/{$rechargeId}/approve")
            ->assertOk()
            ->assertJsonPath('message', '审核通过，储值已到账');

        // 总到账 = 充值 + 赠送
        $this->assertSame('220.00', $member->fresh()->balance);

        // 现金流水只记 recharge_amount（赠送金额未真实到账）
        $flow = DB::table('cash_flows')
            ->where('related_id', $rechargeId)
            ->where('related_type', 'MemberRecharge')
            ->firstOrFail();
        $this->assertSame('receive', $flow->flow_type);
        $this->assertSame(200.00, (float) $flow->amount);
        $this->assertSame($this->customerId, $flow->customer_id);

        // 台账
        $this->assertDatabaseHas('member_consume_logs', [
            'member_id' => $member->id,
            'consume_type' => MemberConsumeLog::TYPE_RECHARGE,
            'use_balance' => '220.00',
            'balance_after' => '220.00',
        ]);

        // 重复审核被拒绝
        $this->postJson("/admin/business/member-recharge/{$rechargeId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', '该单据已审核通过');

        // 已审核单据不可编辑/删除
        $this->putJson("/admin/business/member-recharge/{$rechargeId}", ['member_id' => $member->id, 'recharge_amount' => 100])
            ->assertStatus(422);
        $this->deleteJson("/admin/business/member-recharge/{$rechargeId}")
            ->assertStatus(422);
    }

    /** 驳回 / 取消的充值单不能审核 */
    public function test_recharge_rejected_cannot_be_approved(): void
    {
        $member = $this->makeMember();
        $rechargeId = $this->postJson('/admin/business/member-recharge', [
            'member_id' => $member->id,
            'recharge_amount' => 100,
        ])->json('data.id');

        $this->postJson("/admin/business/member-recharge/{$rechargeId}/reject", ['reason' => '资料不全'])->assertOk();

        $this->postJson("/admin/business/member-recharge/{$rechargeId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', '已驳回/取消的单据不能审核，请删除后重新创建');
    }

    /** 冻结会员不能充值 */
    public function test_recharge_refused_for_frozen_member(): void
    {
        $member = $this->makeMember(['status' => 0]);

        $this->postJson('/admin/business/member-recharge', [
            'member_id' => $member->id,
            'recharge_amount' => 100,
        ])->assertStatus(422)->assertJsonPath('message', '会员已冻结，无法充值');
    }

    // ------------------------------------------------------------------ 积分兑换

    /** 兑换下单不扣分（审核时才扣）；审核扣积分 + 扣库存 + 写台账 */
    public function test_point_order_approve_deducts_points_and_stock(): void
    {
        $member = $this->makeMember(['points' => 500]);

        $productId = $this->postJson('/admin/business/point-product', [
            'name' => '保温杯',
            'points' => 200,
            'stock' => 10,
            'status' => 1,
        ])->json('data.id');

        // 下单
        $orderId = $this->postJson('/admin/business/point-order', [
            'member_id' => $member->id,
            'product_id' => $productId,
            'quantity' => 1,
        ])->json('data.id');

        // 下单不扣分
        $this->assertSame(500, (int) $member->fresh()->points);
        $this->assertSame(10, (int) PointProduct::find($productId)->stock);

        // 审核
        $this->postJson("/admin/business/point-order/{$orderId}/approve")->assertOk();

        $this->assertSame(300, (int) $member->fresh()->points);
        $this->assertSame(9, (int) PointProduct::find($productId)->stock);
        $this->assertSame(1, (int) PointProduct::find($productId)->exchanged);

        // 台账
        $this->assertDatabaseHas('member_consume_logs', [
            'member_id' => $member->id,
            'consume_type' => MemberConsumeLog::TYPE_POINTS_DEDUCT,
            'use_points' => 200,
            'points_after' => 300,
        ]);
    }

    /** 驳回已审核兑换单：退积分 + 退库存 */
    public function test_point_order_reject_returns_points_and_stock(): void
    {
        $member = $this->makeMember(['points' => 500]);

        $productId = $this->postJson('/admin/business/point-product', [
            'name' => '笔记本', 'points' => 200, 'stock' => 5, 'status' => 1,
        ])->json('data.id');

        $orderId = $this->postJson('/admin/business/point-order', [
            'member_id' => $member->id,
            'product_id' => $productId,
            'quantity' => 2,
        ])->json('data.id');

        $this->postJson("/admin/business/point-order/{$orderId}/approve")->assertOk();
        $this->assertSame(100, (int) $member->fresh()->points);
        $this->assertSame(3, (int) PointProduct::find($productId)->stock);

        // 驳回
        $this->postJson("/admin/business/point-order/{$orderId}/reject", ['reason' => '缺货'])->assertOk();

        $this->assertSame(500, (int) $member->fresh()->points);
        $this->assertSame(5, (int) PointProduct::find($productId)->stock);
        $this->assertSame(0, (int) PointProduct::find($productId)->exchanged);
    }

    /** 审核时积分不足被拒绝 */
    public function test_point_order_approve_refused_when_insufficient_points(): void
    {
        $member = $this->makeMember(['points' => 100]);

        $productId = $this->postJson('/admin/business/point-product', [
            'name' => '手机壳', 'points' => 200, 'stock' => 10, 'status' => 1,
        ])->json('data.id');

        // 下单阶段就拦截（pre-validation）
        $this->postJson('/admin/business/point-order', [
            'member_id' => $member->id,
            'product_id' => $productId,
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonPath('message', '会员积分不足，当前积分 100，本次需要 200 积分');
    }

    /** 每人兑换上限 */
    public function test_point_order_limit_per_person_enforced(): void
    {
        $member = $this->makeMember(['points' => 1000]);

        $productId = $this->postJson('/admin/business/point-product', [
            'name' => '限量周边', 'points' => 100, 'stock' => 100, 'status' => 1, 'limit_per_person' => 2,
        ])->json('data.id');

        $this->postJson('/admin/business/point-order', [
            'member_id' => $member->id, 'product_id' => $productId, 'quantity' => 1,
        ])->assertOk();

        $this->postJson('/admin/business/point-order', [
            'member_id' => $member->id, 'product_id' => $productId, 'quantity' => 1,
        ])->assertOk();

        $this->postJson('/admin/business/point-order', [
            'member_id' => $member->id, 'product_id' => $productId, 'quantity' => 1,
        ])->assertStatus(422)->assertJsonPath('message', '该会员已达兑换上限（每人限 2 件）');
    }

    // ------------------------------------------------------------------ 订单联动

    /** 下单带会员 + 储值/积分抵扣，结算时扣减 + 发积分 + 累计消费 */
    public function test_order_settlement_deducts_and_earns(): void
    {
        // 会员：储值 200 元，积分 300
        $member = $this->makeMember(['balance' => 200.00, 'points' => 300]);

        // 下单：商品总价 150.00（100 × 1.5），申请用 50 元储值 + 100 积分抵 1 元
        $orderResponse = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'member_id' => $member->id,
            'use_balance' => 50.00,
            'use_points' => 100,
            'items' => [
                ['product_id' => $this->productId, 'quantity' => 100, 'price' => 1.5],
            ],
        ]);

        $orderResponse->assertOk();
        $orderId = $orderResponse->json('data.id');

        // 结算前：余额/积分不变
        $member->refresh();
        $this->assertSame('200.00', $member->balance);
        $this->assertSame(300, (int) $member->points);

        // 推进到结算：pending → 配货中 → 待调度 → 待配送 → 配送中 → 已收款
        $this->advanceOrder($orderId, '已收款');

        $member->refresh();
        // 100 积分抵 1 元，净实付 = 150 - 50 - 1 = 99 元
        // 得积分 = 99（consume_ratio: 1元1积分）
        $this->assertSame('150.00', $member->balance, '余额：200 - 50 = 150');
        $this->assertSame(299, (int) $member->points, '积分：300 - 100 + 99 = 299');
        $this->assertSame('99.00', $member->total_consume, '累计消费 = 净实付 99');
        $this->assertSame(1, (int) $member->total_orders);
        $this->assertTrue((bool) DB::table('sales_orders')->find($orderId)->points_settled, 'points_settled 已置位');

        // 台账
        $this->assertDatabaseHas('member_consume_logs', [
            'member_id' => $member->id,
            'related_order_no' => DB::table('sales_orders')->find($orderId)->order_no,
            'consume_type' => MemberConsumeLog::TYPE_ORDER,
            'consume_amount' => '99.00',
            'use_balance' => '50.00',
            'use_points' => 100,
            'earn_points' => 99,
            'balance_after' => '150.00',
            'points_after' => 299,
        ]);

        // 应收款 = 150 - 0(已付) - 50(储值) - 1(积分) = 99
        $receivable = DB::table('receives')->where('sales_order_id', $orderId)->first();
        $this->assertNotNull($receivable, '应生成应收款记录');
        $this->assertSame(99.00, (float) $receivable->amount);
    }

    /** points_settled 幂等：重复结算（已收款→待收款→已收款）不重复扣分 */
    public function test_order_settlement_idempotent(): void
    {
        $member = $this->makeMember(['balance' => 100.00, 'points' => 200]);

        $orderId = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'member_id' => $member->id,
            'use_balance' => 30.00,
            'use_points' => 50,
            'items' => [['product_id' => $this->productId, 'quantity' => 50, 'price' => 1.0]],
        ])->json('data.id');

        // 第一次结算：配送中 → 已收款
        $this->advanceOrder($orderId, '已收款');
        $this->assertSame(70.00, (float) $member->fresh()->balance);

        // 模拟对账：已收款 → 待收款 → 已收款（第二次结算应被 points_settled 拦截）
        $this->postJson("/admin/business/sales-order/{$orderId}/approve", ['target' => '待收款'])->assertOk();
        $this->postJson("/admin/business/sales-order/{$orderId}/approve", ['target' => '已收款'])->assertOk();

        $this->assertSame(70.00, (float) $member->fresh()->balance, '重复结算不应再次扣储值');
        $this->assertSame(1, DB::table('member_consume_logs')->where('member_id', $member->id)->count(), '台账只有一条');
    }

    /** 红冲退款对称冲正：退回储值+积分，回滚累计消费与订单数 */
    public function test_order_red_flush_refunds_member(): void
    {
        $member = $this->makeMember(['balance' => 200.00, 'points' => 300]);

        $orderId = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'member_id' => $member->id,
            'use_balance' => 50.00,
            'use_points' => 100,
            'items' => [['product_id' => $this->productId, 'quantity' => 100, 'price' => 1.5]],
        ])->json('data.id');

        // 结算：150 元单，用 50 储值 + 100 积分(抵1元) = 净 99 元，得 99 积分
        $this->advanceOrder($orderId, '已收款');

        $member->refresh();
        $this->assertSame(150.00, (float) $member->balance);
        $this->assertSame(299, (int) $member->points);
        $this->assertSame(99.00, (float) $member->total_consume);
        $this->assertSame(1, (int) $member->total_orders);

        // 红冲
        $this->postJson('/admin/business/sales-order/batch-red-flush', [
            'ids' => [$orderId],
            'type' => 'cancel',
            'reason' => '客户取消订单',
        ])->assertOk();

        $member->refresh();
        // 对称冲正：余额 +50，积分 +100 - 99（扣回结算时发的积分），累计消费 -99
        $this->assertSame(200.00, (float) $member->balance, '储值已退回');
        $this->assertSame(300, (int) $member->points, '积分已退回（含扣回结算所得）');
        $this->assertSame('0.00', $member->total_consume, '累计消费已回滚');
        $this->assertSame(0, (int) $member->total_orders, '订单数已回滚');

        // 红冲台账
        $this->assertDatabaseHas('member_consume_logs', [
            'member_id' => $member->id,
            'consume_type' => MemberConsumeLog::TYPE_ORDER_REFUND,
            'consume_amount' => '-99.00',
            'use_balance' => '50.00',
            'use_points' => 100,
            'earn_points' => -99,
            'balance_after' => '200.00',
            'points_after' => 300,
        ]);
    }

    /** 超额抵扣抛错：储值+积分抵扣合计超过订单金额 */
    public function test_settlement_rejected_when_deduction_exceeds_order_amount(): void
    {
        $member = $this->makeMember(['balance' => 500.00, 'points' => 50000]);

        $orderId = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'member_id' => $member->id,
            'use_balance' => 500.00, // 订单只有 100 元，抵扣超过订单金额
            'items' => [['product_id' => $this->productId, 'quantity' => 100, 'price' => 1.0]],
        ])->json('data.id');

        // 结算时应被拒绝，订单状态不应推进
        $this->postJson("/admin/business/sales-order/{$orderId}/approve")
            ->assertOk(); // pending → 配货中

        // 推进到配送中
        $this->postJson("/admin/business/sales-order/{$orderId}/approve")->assertOk();
        $this->postJson("/admin/business/sales-order/{$orderId}/approve")->assertOk();
        $this->postJson("/admin/business/sales-order/{$orderId}/approve")->assertOk();

        // 配送中 → 已收款：结算失败
        $this->postJson("/admin/business/sales-order/{$orderId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', '状态流转失败：储值+积分抵扣合计 500.00 元超过订单金额 100.00 元');

        // 状态未推进，余额/积分未变动
        $this->assertSame('配送中', DB::table('sales_orders')->find($orderId)->status);
        $this->assertSame(500.00, (float) $member->fresh()->balance);
        $this->assertSame(50000, (int) $member->fresh()->points);
    }

    /** 储值余额不足时结算失败 */
    public function test_settlement_rejected_when_insufficient_balance(): void
    {
        $member = $this->makeMember(['balance' => 10.00]);

        $orderId = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'member_id' => $member->id,
            'use_balance' => 100.00,
            'items' => [['product_id' => $this->productId, 'quantity' => 200, 'price' => 1.0]],
        ])->json('data.id');

        $this->advanceOrder($orderId, '已收款', 422);

        $this->assertSame('配送中', DB::table('sales_orders')->find($orderId)->status);
        $this->assertSame(10.00, (float) $member->fresh()->balance);
    }

    /** 冻结会员的订单结算被拒绝 */
    public function test_settlement_rejected_for_frozen_member(): void
    {
        $member = $this->makeMember(['balance' => 200.00, 'status' => 0]);

        $orderId = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'member_id' => $member->id,
            'use_balance' => 30.00,
            'items' => [['product_id' => $this->productId, 'quantity' => 100, 'price' => 1.0]],
        ])->json('data.id');

        $this->advanceOrder($orderId, '已收款', 422);

        $this->assertSame('配送中', DB::table('sales_orders')->find($orderId)->status);
        $this->assertSame(200.00, (float) $member->fresh()->balance);
    }

    /** 无会员的订单结算正常（仅置幂等标记） */
    public function test_order_without_member_settles_only_flag(): void
    {
        $orderId = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->productId, 'quantity' => 100, 'price' => 1.0]],
        ])->json('data.id');

        $this->advanceOrder($orderId, '已收款');

        $order = DB::table('sales_orders')->find($orderId);
        $this->assertSame('已收款', $order->status);
        $this->assertTrue((bool) $order->points_settled, '无会员也应置幂等标记');

        // 应收款 = 全额（无会员抵扣）
        $receivable = DB::table('receives')->where('sales_order_id', $orderId)->first();
        $this->assertNotNull($receivable);
        $this->assertSame(100.00, (float) $receivable->amount);
    }

    /** 累计消费达到等级门槛自动升级 */
    public function test_member_level_auto_upgrade_on_settlement(): void
    {
        // 把 consume_ratio 调到 100:1（100 元得 1 分），不影响门槛；
        // 直接构造大额订单触发 LV1（门槛 5000 元）
        $lv1 = MemberLevel::where('level_no', 'LV1')->firstOrFail();
        $member = $this->makeMember(['balance' => 5000.00]);

        $this->assertSame('普通会员', $member->fresh()->level->name);

        $orderId = $this->postJson('/admin/business/sales-order', [
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'member_id' => $member->id,
            'items' => [['product_id' => $this->productId, 'quantity' => 5000, 'price' => 1.0]],
        ])->json('data.id');

        $this->advanceOrder($orderId, '已收款');

        $member->refresh();
        $this->assertSame(5000.0, (float) $member->total_consume, '累计消费达 LV1 门槛');
        $this->assertSame($lv1->id, (int) $member->level_id, '应自动升级到 LV1');
        $this->assertSame('银卡会员', $member->level->name);
    }

    /** 会员列表筛选：关键词 / 等级 / 状态 */
    public function test_member_index_filters(): void
    {
        $m1 = $this->makeMember(['name' => '张三', 'phone' => '13800000001']);
        $m2 = $this->makeMember(['name' => '李四', 'phone' => '13800000002', 'status' => 0]);

        $this->getJson('/admin/business/member?keyword=张三')
            ->assertOk()
            ->assertJsonCount(1, 'data.list');

        $this->getJson('/admin/business/member?status=0')
            ->assertOk()
            ->assertJsonCount(1, 'data.list');
        $this->assertSame($m2->id, $this->getJson('/admin/business/member?status=0')->json('data.list.0.id'));
    }

    /** 会员统计概览 */
    public function test_member_statistics(): void
    {
        $this->makeMember(['balance' => 100.00, 'points' => 500]);
        $this->makeMember(['balance' => 200.00, 'points' => 300, 'status' => 0]);

        $stats = $this->getJson('/admin/business/member/statistics')->assertOk()->json('data');

        $this->assertSame(2, (int) $stats['total_members']);
        $this->assertSame(1, (int) $stats['active_members']);
        $this->assertSame(1, (int) $stats['frozen_members']);
        $this->assertSame(300.00, (float) $stats['total_balance']);
        $this->assertSame(800, (int) $stats['total_points']);
        $this->assertArrayHasKey('consume_trend', $stats);
        $this->assertArrayHasKey('level_distribution', $stats);
    }

    /** 消费台账只读：按会员 / 类型筛选 */
    public function test_consume_log_index_and_statistics(): void
    {
        $member = $this->makeMember(['balance' => 50.00]);

        $rechargeId = $this->postJson('/admin/business/member-recharge', [
            'member_id' => $member->id, 'recharge_amount' => 50,
        ])->json('data.id');

        $this->postJson("/admin/business/member-recharge/{$rechargeId}/approve")->assertOk();

        $logs = $this->getJson('/admin/business/member-consume-log?member_id='.$member->id);
        $logs->assertOk();
        $this->assertNotEmpty($logs->json('data.list'));

        // 按类型筛选
        $this->getJson('/admin/business/member-consume-log?consume_type='.MemberConsumeLog::TYPE_RECHARGE)
            ->assertOk();

        $this->getJson('/admin/business/member-consume-log/statistics')->assertOk();
    }

    // ------------------------------------------------------------------ 辅助

    /**
     * 推进销售单到目标状态，逐步 approve。
     *
     * 状态机：pending → 配货中 → 待调度 → 待配送 → 配送中 → 已收款
     * 期望返回码默认 200，最后一步可传 422 验证结算失败。
     */
    private function advanceOrder(int $orderId, string $target, int $finalExpectedStatus = 200): void
    {
        $flow = ['pending', '配货中', '待调度', '待配送', '配送中', $target];
        foreach ($flow as $i => $state) {
            if ($i === 0) {
                continue;
            }

            $expected = ($i === count($flow) - 1) ? $finalExpectedStatus : 200;
            $resp = $this->postJson("/admin/business/sales-order/{$orderId}/approve");
            if ($resp->status() !== $expected) {
                $this->fail(sprintf(
                    '推进失败 (step %d → %s)：期望 %d 实际 %d，响应：%s',
                    $i, $state, $expected, $resp->status(),
                    $resp->getContent()
                ));
            }

            if ($expected === 200 && DB::table('sales_orders')->find($orderId)->status !== $state) {
                $this->fail("推进失败：期望到「{$state}」，实际「".DB::table('sales_orders')->find($orderId)->status.'」');
            }
        }
    }

    /** 创建一个会员（默认 LV0，状态正常） */
    private function makeMember(array $attributes = []): Member
    {
        return Member::create(array_merge([
            'member_no' => 'HY'.now()->format('Ymd').str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => null,
            'name' => '会员'.random_int(1000, 9999),
            'level_id' => MemberLevel::where('level_no', 'LV0')->firstOrFail()->id,
            'points' => 0,
            'balance' => 0,
            'total_consume' => 0,
            'total_orders' => 0,
            'status' => 1,
            'register_date' => now()->toDateString(),
        ], $attributes));
    }
}
