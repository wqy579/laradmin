<?php

namespace Tests\Performance\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Models\CommissionRule;
use Modules\Performance\Support\CommissionCalculator;
use Tests\TestCase;

/**
 * C1 提成方案（commission-rule）验收测试（对应 C-PROJECT-EXEC C1 与任务书 §七）。
 *
 * 覆盖验收标准：
 *  - 方案 CRUD（增删改查 + 停用）
 *  - 复制生成副本：名称带「副本」、状态为停用（inactive）
 *  - 停用方案不可被试算引用：试算只取 status=active
 *  - 梯度区间连续性校验：区间重叠或断层则保存被拒（422）
 */
class CommissionRuleFeatureTest extends TestCase
{
    use RefreshDatabase;

    private string $period = '2026-05';

    /** 造一份已审核销售订单 + 回款，业务员关联 auth_user（用 DB 直插，避免跨模块模型依赖） */
    private function makeSalesScenario(int $salesmanId): array
    {
        $customerId = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => '测试客户', 'is_active' => 1]);
        $warehouseId = DB::table('warehouses')->insertGetId(['code' => 'WH1', 'name' => '主仓', 'is_active' => 1]);
        $productId = DB::table('products')->insertGetId(['name' => '可乐', 'code' => 'P1', 'price_small' => 100, 'cost_price' => 60, 'is_active' => 1]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO-T1-0001', 'customer_id' => $customerId, 'warehouse_id' => $warehouseId,
            'order_date' => $this->period.'-15', 'total_qty' => 10, 'total_amount' => 1000,
            'status' => 'approved', 'salesman_id' => $salesmanId, 'salesman_name' => '业务员甲',
        ]);
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $orderId, 'product_id' => $productId,
            'quantity' => 10, 'price' => 100, 'amount' => 1000,
        ]);

        return ['order_id' => $orderId, 'product_id' => $productId];
    }

    private function makeRule(array $overrides = []): CommissionRule
    {
        return CommissionRule::create(array_merge([
            'code' => 'CR'.substr(str_shuffle('0123456789'), 0, 4),
            'name' => '销售额提成方案',
            'type' => 'sales', 'calc_type' => 'sale_rate',
            'rule' => ['type' => 'sale_rate', 'rate' => 1.5],
            'scope' => 'all', 'employee_ids' => [],
            'rate' => 1.5, 'base_on_payment' => 1, 'deduct_return' => 0,
            'exclude_baddebt' => 0, 'cap_amount' => 0, 'floor_amount' => 0, 'allow_stack' => 0,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active',
        ], $overrides));
    }

    public function test_rule_crud_via_http(): void
    {
        $this->actingAsAdmin();

        // 新增
        $resp = $this->postJson('/admin/perf/commission-rules', [
            'name' => '毛利提成方案', 'type' => 'profit', 'rate' => 5.0,
            'base_on_payment' => true, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);
        $resp->assertOk()->assertJson(['code' => 200]);
        $id = $resp->json('data.id');
        $this->assertNotNull($id);
        // type ↔ calc_type 双写
        $this->assertSame('profit_rate', CommissionRule::find($id)->calc_type);

        // 查详情 / 列表
        $this->getJson('/admin/perf/commission-rules/'.$id)->assertOk()->assertJsonPath('data.name', '毛利提成方案');
        $this->getJson('/admin/perf/commission-rules?name=毛利')->assertOk()->assertJson(['code' => 200]);

        // 更新
        $this->putJson('/admin/perf/commission-rules/'.$id, [
            'name' => '毛利提成方案(改)', 'type' => 'profit', 'rate' => 6.0,
        ])->assertOk();
        $this->assertEquals(6.0, (float) CommissionRule::find($id)->rate);

        // 删除
        $this->deleteJson('/admin/perf/commission-rules/'.$id)->assertOk();
        $this->assertNull(CommissionRule::find($id));
    }

    public function test_copy_produces_inactive_副本(): void
    {
        $this->actingAsAdmin();
        $rule = $this->makeRule();

        $resp = $this->postJson('/admin/perf/commission-rules/'.$rule->id.'/copy');
        $resp->assertOk()->assertJson(['message' => '复制成功']);
        $copyId = $resp->json('data.id');
        $this->assertNotSame($rule->id, $copyId);

        $copy = CommissionRule::find($copyId);
        $this->assertStringContainsString('副本', $copy->name);
        $this->assertSame('inactive', $copy->status, '副本应默认停用，避免误计');
    }

    public function test_inactive_rule_not_referenced_by_calc(): void
    {
        $this->actingAsAdmin();
        $salesman = $this->makeAdmin(['real_name' => '业务员甲']);
        $scenario = $this->makeSalesScenario($salesman->id);
        // 回款让提成非零，否则应发=0 不产生分组
        DB::table('receives')->insert([
            'receive_no' => 'RC-'.substr(str_shuffle('0123456789'), 0, 6),
            'customer_id' => 1, 'sales_order_id' => $scenario['order_id'],
            'amount' => 800, 'receive_date' => $this->period.'-20', 'status' => 1,
        ]);

        // 只造一条启用方案 → 试算有结果
        $this->makeRule();
        $result = app(CommissionCalculator::class)->preview($this->period, [$salesman->id]);
        $this->assertNotEmpty($result['groups'], '启用方案应命中试算');

        // 停用全部 → 试算不再引用
        CommissionRule::query()->update(['status' => 'inactive']);
        $result = app(CommissionCalculator::class)->preview($this->period, [$salesman->id]);
        $this->assertEmpty($result['groups'], '停用方案不可被试算引用');
    }

    public function test_tier_validation_rejects_gap_and_overlap(): void
    {
        $this->actingAsAdmin();

        // 断层：0~5000 后直接 8000~10000，5000~8000 缺失
        $gap = $this->postJson('/admin/perf/commission-rules', [
            'name' => '阶梯断层', 'type' => 'ladder',
            'tier_data' => [
                ['min' => 0, 'max' => 5000, 'rate' => 1],
                ['min' => 8000, 'max' => 10000, 'rate' => 2],
            ],
        ]);
        $gap->assertStatus(422)->assertJsonValidationErrors('tier_data');

        // 重叠：0~5000 与 4000~8000
        $overlap = $this->postJson('/admin/perf/commission-rules', [
            'name' => '阶梯重叠', 'type' => 'ladder',
            'tier_data' => [
                ['min' => 0, 'max' => 5000, 'rate' => 1],
                ['min' => 4000, 'max' => 8000, 'rate' => 2],
            ],
        ]);
        $overlap->assertStatus(422)->assertJsonValidationErrors('tier_data');

        // 连续不重叠 → 可保存
        $ok = $this->postJson('/admin/perf/commission-rules', [
            'name' => '阶梯正常', 'type' => 'ladder',
            'tier_data' => [
                ['min' => 0, 'max' => 5000, 'rate' => 1],
                ['min' => 5000, 'max' => 10000, 'rate' => 2],
            ],
        ]);
        $ok->assertOk()->assertJson(['code' => 200]);
        $this->assertSame('ladder', CommissionRule::find($ok->json('data.id'))->type);
    }
}