<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T5.1 客户订货核对
 *
 * 订货量 vs 实际数量的逐行核对：diff = actual - ordered，
 * diff_rate = diff / ordered * 100（ordered=0 时定义为：有实际数量即 100%，否则 0%），
 * risk = |diff_rate| > 30（±30% 阈值，前端标红 #F56C6C 加粗）。
 */
class OrderCheckFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->seedOrderData();
    }

    public function test_index_lists_items_with_diff_rate_and_risk_flags(): void
    {
        $res = $this->getJson('admin/business/order-check');

        $res->assertOk();
        $rows = collect($res->json('data.list'));

        // 超发 +50%：风险
        $over = $rows->first(fn ($r) => (int) $r['ordered_qty'] === 100 && (int) $r['actual_qty'] === 150);
        $this->assertNotNull($over);
        $this->assertSame('SO-CHECK-0001', $over['order_no']);
        $this->assertSame('甲客户', $over['customer']);
        $this->assertSame('商品A', $over['product']);
        $this->assertSame(50.0, (float) $over['diff']);
        $this->assertSame(50.0, (float) $over['diff_rate']);
        $this->assertTrue((bool) $over['risk']);

        // +10%：安全
        $safe = $rows->first(fn ($r) => (int) $r['ordered_qty'] === 100 && (int) $r['actual_qty'] === 110);
        $this->assertNotNull($safe);
        $this->assertSame(10.0, (float) $safe['diff_rate']);
        $this->assertFalse((bool) $safe['risk']);

        // 订货量 0、实际 5：除零保护定义为 100%，风险
        $zero = $rows->first(fn ($r) => (int) $r['ordered_qty'] === 0);
        $this->assertNotNull($zero);
        $this->assertSame(100.0, (float) $zero['diff_rate']);
        $this->assertTrue((bool) $zero['risk']);
    }

    public function test_index_only_risk_filter_returns_flagged_rows_only(): void
    {
        $res = $this->getJson('admin/business/order-check?only_risk=1');

        $res->assertOk();
        $rows = $res->json('data.list');
        $this->assertNotEmpty($rows);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertTrue((bool) $row['risk']);
        }
    }

    public function test_index_keyword_filters_by_order_no_and_customer(): void
    {
        $res = $this->getJson('admin/business/order-check?keyword=SO-CHECK-0002');
        $res->assertOk();
        $rows = $res->json('data.list');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('SO-CHECK-0002', $row['order_no']);
        }

        $res2 = $this->getJson('admin/business/order-check?keyword=乙客户');
        $res2->assertOk();
        $rows = $res2->json('data.list');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('乙客户', $row['customer']);
        }
    }

    public function test_show_returns_order_summary_and_operation_history(): void
    {
        $res = $this->getJson('admin/business/order-check/1');

        $res->assertOk();
        $this->assertSame('SO-CHECK-0001', $res->json('data.order_no'));
        $this->assertSame('甲客户', $res->json('data.customer'));
        $this->assertCount(3, $res->json('data.items'));

        // 操作历史必须回传（order_operation_logs, order_type='sales_order'，倒序）
        $history = $res->json('data.history');
        $this->assertIsArray($history);
        $this->assertNotEmpty($history);
        $this->assertSame('submit', $history[0]['action']);
        $this->assertSame('sales_order', $history[0]['order_type']);
    }

    public function test_show_returns_404_for_missing_order(): void
    {
        $this->getJson('admin/business/order-check/999999')->assertStatus(404);
    }

    public function test_index_requires_auth(): void
    {
        $this->withToken('');
        $this->getJson('admin/business/order-check')->assertStatus(401);
    }

    private function seedOrderData(): void
    {
        $now = now();
        $warehouse = $this->makeWarehouse();

        $customerA = DB::table('customers')->insertGetId([
            'code' => 'C'.Str::upper(Str::random(6)),
            'name' => '甲客户',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $customerB = DB::table('customers')->insertGetId([
            'code' => 'C'.Str::upper(Str::random(6)),
            'name' => '乙客户',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $products = [];
        foreach (['商品A', '商品B', '商品C'] as $name) {
            $products[] = DB::table('products')->insertGetId([
                'name' => $name,
                'code' => 'P'.Str::upper(Str::random(6)),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO-CHECK-0001',
            'order_type' => 'wholesale',
            'customer_id' => $customerA,
            'warehouse_id' => $warehouse->id,
            'order_date' => '2026-10-20',
            'total_qty' => 200,
            'total_amount' => 1000.00,
            'status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // A：订 100 实 150（+50% 风险）；B：100/110（+10% 安全）；C：0/5（除零保护 → 100% 风险）
        $items = [
            [$products[0], 100, 150],
            [$products[1], 100, 110],
            [$products[2], 0, 5],
        ];
        foreach ($items as [$productId, $ordered, $actual]) {
            DB::table('sales_order_items')->insert([
                'sales_order_id' => $orderId,
                'product_id' => $productId,
                'quantity' => $ordered,
                'actual_qty' => $actual,
                'price' => 5.00,
                'amount' => $ordered * 5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 第二张订单：乙客户，正常范围，用于 keyword 过滤断言
        $orderId2 = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO-CHECK-0002',
            'order_type' => 'wholesale',
            'customer_id' => $customerB,
            'warehouse_id' => $warehouse->id,
            'order_date' => '2026-10-25',
            'total_qty' => 20,
            'total_amount' => 100.00,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $orderId2,
            'product_id' => $products[1],
            'quantity' => 20,
            'actual_qty' => 20,
            'price' => 5.00,
            'amount' => 100.00,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 操作历史（detail 接口时间线）
        DB::table('order_operation_logs')->insert([
            'order_id' => $orderId,
            'order_no' => 'SO-CHECK-0001',
            'order_type' => 'sales_order',
            'user_id' => 1,
            'user_name' => '测试管理员',
            'action' => 'submit',
            'action_label' => '提交',
            'from_status' => 'pending',
            'to_status' => 'pending',
            'detail' => '提交订单',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
