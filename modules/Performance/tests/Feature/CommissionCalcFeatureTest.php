<?php

namespace Tests\Performance\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Models\CommissionOrder;
use Modules\Performance\Models\CommissionRule;
use Modules\Performance\Support\CommissionCalculator;
use Tests\TestCase;

/**
 * C2 提成计算 + 提成报表（commission / commission-orders）验收测试。
 *
 * 覆盖验收标准：
 *  - 手工抽算 3 例一致：按销售额比例 / 按毛利比例 / 按件固定
 *  - 退货扣减生效：deduct_return 按退货/销售比例扣减应发
 *  - 发放后状态锁定：pending→audited→paid 单向；pending→rejected 终态；
 *    已审核只能标记发放、已发放不可再动；同月同业务员幂等。
 */
class CommissionCalcFeatureTest extends TestCase
{
    use RefreshDatabase;

    private string $period = '2026-05';

    /** 造已审核销售订单：产品进价 60 / 售价 100 × 10，销售额 1000、成本 600（DB 直插，避免跨模块模型依赖） */
    private function makeOrder(int $salesmanId): array
    {
        $customerId = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => '测试客户', 'is_active' => 1]);
        $warehouseId = DB::table('warehouses')->insertGetId(['code' => 'WH1', 'name' => '主仓', 'is_active' => 1]);
        $productId = DB::table('products')->insertGetId(['name' => '可乐', 'code' => 'P1', 'price_small' => 100, 'cost_price' => 60, 'is_active' => 1]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO-C2-'.substr(str_shuffle('0123456789'), 0, 4),
            'customer_id' => $customerId, 'warehouse_id' => $warehouseId,
            'order_date' => $this->period.'-15', 'total_qty' => 10, 'total_amount' => 1000,
            'status' => 'approved', 'salesman_id' => $salesmanId,
        ]);
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $orderId, 'product_id' => $productId,
            'quantity' => 10, 'price' => 100, 'amount' => 1000,
        ]);

        return ['order_id' => $orderId, 'product_id' => $productId];
    }

    /** 指定业务员专属方案，避免多方案互相干扰 */
    private function makeRule(int $salesmanId, array $overrides = []): CommissionRule
    {
        return CommissionRule::create(array_merge([
            'code' => 'CR'.substr(str_shuffle('0123456789'), 0, 4),
            'name' => '提成方案',
            'type' => 'sales', 'calc_type' => 'sale_rate',
            'rule' => ['type' => 'sale_rate', 'rate' => 1.5],
            'scope' => 'specified', 'employee_ids' => [$salesmanId],
            'apply_employees' => [$salesmanId],
            'rate' => 1.5, 'base_on_payment' => 1, 'deduct_return' => 1,
            'exclude_baddebt' => 0, 'cap_amount' => 0, 'floor_amount' => 0, 'allow_stack' => 0,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active',
        ], $overrides));
    }

    /** 单业务员的试算结果 */
    private function preview(int $salesmanId): array
    {
        $result = app(CommissionCalculator::class)->preview($this->period, [$salesmanId]);
        $this->assertNotEmpty($result['groups'], '应命中试算分组');

        return $result['groups'][0];
    }

    public function test_manual_sample_sale_rate(): void
    {
        $this->actingAsAdmin();
        $salesman = $this->makeAdmin(['real_name' => '业务员甲']);
        $order = $this->makeOrder($salesman->id);
        $this->makeRule($salesman->id, ['type' => 'sales', 'calc_type' => 'sale_rate', 'rate' => 1.5, 'base_on_payment' => 1]);

        // 回款 800（已审核收款单）
        DB::table('receives')->insert([
            'receive_no' => 'RC-'.substr(str_shuffle('0123456789'), 0, 6),
            'customer_id' => 1, 'sales_order_id' => $order['order_id'],
            'amount' => 800, 'receive_date' => $this->period.'-20', 'status' => 1,
        ]);

        $g = $this->preview($salesman->id);
        // base = 回款 800；提成 = 800 × 1.5% = 12
        $this->assertEquals(1000.0, $g['sales_amount']);
        $this->assertEquals(800.0, $g['received_amount']);
        $this->assertEquals(800.0, $g['base_amount']);
        $this->assertEquals(12.0, $g['commission_amount']);
        $this->assertEquals(0.0, $g['deduction']);
        $this->assertEquals(12.0, $g['actual_amount']);
    }

    public function test_manual_sample_profit_rate(): void
    {
        $this->actingAsAdmin();
        $salesman = $this->makeAdmin(['real_name' => '业务员乙']);
        $order = $this->makeOrder($salesman->id);
        $this->makeRule($salesman->id, ['type' => 'profit', 'calc_type' => 'profit_rate', 'rate' => 5.0, 'base_on_payment' => 1]);

        DB::table('receives')->insert([
            'receive_no' => 'RC-'.substr(str_shuffle('0123456789'), 0, 6),
            'customer_id' => 1, 'sales_order_id' => $order['order_id'],
            'amount' => 800, 'receive_date' => $this->period.'-20', 'status' => 1,
        ]);

        $g = $this->preview($salesman->id);
        // 毛利 = 1000 - 600 = 400；base = paid - (paid/sale)×gross = 800 - 0.8×400 = 480
        // 提成 = 480 × 5% = 24
        $this->assertEquals(400.0, $g['profit_amount']);
        $this->assertEquals(480.0, $g['base_amount']);
        $this->assertEquals(24.0, $g['commission_amount']);
        $this->assertEquals(24.0, $g['actual_amount']);
    }

    public function test_manual_sample_fixed_per_unit(): void
    {
        $this->actingAsAdmin();
        $salesman = $this->makeAdmin(['real_name' => '业务员丙']);
        $this->makeOrder($salesman->id);
        $this->makeRule($salesman->id, ['type' => 'piece', 'calc_type' => 'fixed_per_unit', 'unit_amount' => 2.0, 'base_on_payment' => 0, 'exclude_baddebt' => 0]);

        $g = $this->preview($salesman->id);
        // 按件固定：base = 销量 10；提成 = 10 × ¥2 = 20
        $this->assertEquals(20.0, $g['commission_amount']);
        $this->assertEquals(20.0, $g['actual_amount']);
    }

    public function test_return_deduction_applies(): void
    {
        $this->actingAsAdmin();
        $salesman = $this->makeAdmin(['real_name' => '业务员丁']);
        $order = $this->makeOrder($salesman->id);
        $this->makeRule($salesman->id, ['type' => 'sales', 'calc_type' => 'sale_rate', 'rate' => 1.5, 'base_on_payment' => 1, 'deduct_return' => 1]);

        DB::table('receives')->insert([
            'receive_no' => 'RC-'.substr(str_shuffle('0123456789'), 0, 6),
            'customer_id' => 1, 'sales_order_id' => $order['order_id'],
            'amount' => 800, 'receive_date' => $this->period.'-20', 'status' => 1,
        ]);

        // 退货 200（已审核退货单，关联原订单）
        $returnId = DB::table('sales_returns')->insertGetId([
            'return_no' => 'TH'.substr(str_shuffle('0123456789'), 0, 8),
            'order_id' => $order['order_id'], 'customer_id' => 1,
            'return_date' => $this->period.'-22', 'status' => 'approved', 'total_qty' => 2, 'total_amount' => 200,
        ]);
        DB::table('sales_return_items')->insert([
            'return_id' => $returnId, 'product_id' => $order['product_id'],
            'return_qty' => 2, 'return_price' => 100, 'return_amount' => 200,
        ]);

        $g = $this->preview($salesman->id);
        // base = 回款 800 → 应发 12；扣减 = 12 × (200/1000) = 2.4；实发 9.6
        $this->assertEquals(200.0, $g['return_amount']);
        $this->assertEquals(12.0, $g['commission_amount']);
        $this->assertEquals(2.4, $g['deduction']);
        $this->assertEquals(9.6, $g['actual_amount']);
    }

    public function test_status_machine_and_idempotency(): void
    {
        $this->actingAsAdmin();
        $salesman = $this->makeAdmin(['real_name' => '业务员戊']);
        $orderData = $this->makeOrder($salesman->id);
        $this->makeRule($salesman->id);
        // 回款 800，保证应发非零可生成
        DB::table('receives')->insert([
            'receive_no' => 'RC-'.substr(str_shuffle('0123456789'), 0, 6),
            'customer_id' => 1, 'sales_order_id' => $orderData['order_id'],
            'amount' => 800, 'receive_date' => $this->period.'-20', 'status' => 1,
        ]);

        // 生成 → pending
        $gen = $this->postJson('/admin/perf/commission/generate', ['period' => $this->period, 'employee_ids' => [$salesman->id]]);
        $gen->assertOk()->assertJson(['code' => 200]);
        $order = CommissionOrder::where('period', $this->period)->where('employee_id', $salesman->id)->firstOrFail();
        $this->assertSame('pending', $order->status, '生成后应为待审核');
        $this->assertEquals(12.0, (float) $order->actual_amount, '回款基数 800 × 1.5% = 12');

        // 同月同业务员幂等：再次生成被拒
        $this->postJson('/admin/perf/commission/generate', ['period' => $this->period, 'employee_ids' => [$salesman->id]])
            ->assertStatus(400);

        // pending → audited
        $this->postJson('/admin/perf/commission-orders/'.$order->id.'/audit')->assertOk();
        $this->assertSame('audited', CommissionOrder::find($order->id)->status);

        // audited → paid
        $this->postJson('/admin/perf/commission-orders/'.$order->id.'/pay')->assertOk();
        $this->assertSame('paid', CommissionOrder::find($order->id)->status);
        $this->assertNotNull(CommissionOrder::find($order->id)->paid_at);

        // paid 后再审核 / 驳回 / 再发放均被拒
        $this->postJson('/admin/perf/commission-orders/'.$order->id.'/audit')->assertStatus(400);
        $this->postJson('/admin/perf/commission-orders/'.$order->id.'/reject')->assertStatus(400);
        $this->postJson('/admin/perf/commission-orders/'.$order->id.'/pay')->assertStatus(400);
    }

    public function test_reject_is_terminal(): void
    {
        $this->actingAsAdmin();
        $salesman = $this->makeAdmin(['real_name' => '业务员己']);
        $orderData = $this->makeOrder($salesman->id);
        $this->makeRule($salesman->id);
        DB::table('receives')->insert([
            'receive_no' => 'RC-'.substr(str_shuffle('0123456789'), 0, 6),
            'customer_id' => 1, 'sales_order_id' => $orderData['order_id'],
            'amount' => 800, 'receive_date' => $this->period.'-20', 'status' => 1,
        ]);

        $this->postJson('/admin/perf/commission/generate', ['period' => $this->period, 'employee_ids' => [$salesman->id]])->assertOk();
        $order = CommissionOrder::where('period', $this->period)->where('employee_id', $salesman->id)->firstOrFail();

        // pending → rejected
        $this->postJson('/admin/perf/commission-orders/'.$order->id.'/reject')->assertOk();
        $this->assertSame('rejected', CommissionOrder::find($order->id)->status);

        // rejected 终态：不可再审核 / 发放
        $this->postJson('/admin/perf/commission-orders/'.$order->id.'/audit')->assertStatus(400);
        $this->postJson('/admin/perf/commission-orders/'.$order->id.'/pay')->assertStatus(400);
    }
}
