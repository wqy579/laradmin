<?php

namespace Tests\Member\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberLevel;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * 回归测试：覆盖生产报告的 P0-1 / P0-2 / P1-1。
 *
 * P0-1 会员更新 500 —— MemberController::update 第 150 行在「未传 customer_id」时
 *      访问 $validated['customer_id'] 触发 Undefined array key（生产 ErrorException → 500）。
 * P0-2 会员等级新增 500 —— validateLevel 的 unique 规则返回数组 ['unique:...']，嵌进
 *      规则数组后变成 [['unique:...']]，被 Validator 当成 [object, method] 回调，
 *      报 BadMethodCallException: validateUnique:memberLevels,levelNo。
 * P1-1 创建会员时 balance/points 被静默忽略 —— 设计上不允许直接设置（资金安全），
 *      现改为显式 422 拒绝。
 */
class MemberP0RegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $cid = $this->makeCustomer()->id;
        $wid = $this->makeWarehouse()->id;
        $pid = $this->makeProduct(['price_small' => 1, 'price_unit_small' => '个', 'unit_conversion' => 0, 'unit_conversion_medium' => 0])->id;
        Stock::create(['product_id' => $pid, 'warehouse_id' => $wid, 'quantity' => 100, 'frozen_qty' => 0]);
    }

    /** P0-1: PUT /admin/business/member/{id} 在不传 customer_id 时不再 500。 */
    public function test_member_update_without_customer_id(): void
    {
        $m = Member::create([
            'member_no' => 'HY'.date('Ymd').'000001',
            'customer_id' => 1,
            'name' => '张三',
            'level_id' => 1,
            'points' => 0,
            'balance' => 0,
            'total_consume' => 0,
            'total_orders' => 0,
            'register_date' => now()->toDateString(),
            'status' => 1,
        ]);

        // 不传 customer_id：不应再触发 Undefined array key
        $resp = $this->putJson('/admin/business/member/'.$m->id, [
            'name' => '张三改',
            'phone' => '13800000000',
            'gender' => 1,
        ]);

        $resp->assertStatus(200);
        $this->assertSame('张三改', Member::find($m->id)->name);
        $this->assertSame('13800000000', Member::find($m->id)->phone);
        // customer_id 保持原值（未被置空）
        $this->assertSame(1, (int) Member::find($m->id)->customer_id);
    }

    /** P0-1 补充：显式传 customer_id=null 解绑客户。 */
    public function test_member_update_unbind_customer(): void
    {
        $m = Member::create([
            'member_no' => 'HY'.date('Ymd').'000002',
            'customer_id' => 1,
            'name' => '李四',
            'level_id' => 1,
            'register_date' => now()->toDateString(),
            'status' => 1,
        ]);

        $resp = $this->putJson('/admin/business/member/'.$m->id, [
            'customer_id' => null,
        ]);

        $resp->assertStatus(200);
        $this->assertNull(Member::find($m->id)->customer_id);
    }

    /** P0-2: POST /admin/business/member-level 不再 500。 */
    public function test_member_level_store(): void
    {
        $resp = $this->postJson('/admin/business/member-level', [
            'level_no' => 'LV9',
            'name' => '钻石会员',
            'upgrade_amount' => 100000,
            'discount_rate' => 80,
            'color' => '#FFD700',
            'description' => '顶级会员',
            'sort' => 9,
            'status' => 1,
        ]);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('member_levels', ['level_no' => 'LV9', 'name' => '钻石会员']);
    }

    /** P0-2 补充：level_no 唯一性仍生效。 */
    public function test_member_level_unique_level_no(): void
    {
        MemberLevel::create(['level_no' => 'LV5', 'name' => '测试', 'is_system' => false]);

        $resp = $this->postJson('/admin/business/member-level', [
            'level_no' => 'LV5',
            'name' => '重复',
        ]);

        $this->assertSame(422, $resp->status());
    }

    /** P1-1: 创建会员时传 balance/points 被明确拒绝（422）。 */
    public function test_member_store_rejects_balance_and_points(): void
    {
        $resp = $this->postJson('/admin/business/member', [
            'name' => '王五',
            'balance' => 100,
            'points' => 50,
        ]);

        $this->assertSame(422, $resp->status());
        $body = $resp->getContent();
        $this->assertStringContainsString('balance', $body);
        $this->assertStringContainsString('points', $body);
    }

    /** P1-1 补充：不传 balance/points 时正常创建，余额/积分均为 0。 */
    public function test_member_store_without_balance_points(): void
    {
        $resp = $this->postJson('/admin/business/member', [
            'name' => '赵六',
            'phone' => '13900000000',
        ]);

        $resp->assertStatus(200);
        $m = Member::where('name', '赵六')->first();
        $this->assertSame(0, (int) $m->balance);
        $this->assertSame(0, (int) $m->points);
    }
}
