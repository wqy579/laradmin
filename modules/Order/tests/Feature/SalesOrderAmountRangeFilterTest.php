<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

/**
 * 销售订单金额范围筛选（B 域假按钮清除 · 任务 3）。
 *
 * 钉住闭区间边界：等于上限/下限的订单必须命中，略越界的必须排除；
 * 只传单边时另一边不限。
 */
class SalesOrderAmountRangeFilterTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'code' => 'C'.Str::random(6),
            'name' => '边界客户',
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::create(['code' => 'WH'.Str::random(5), 'name' => '主仓', 'is_active' => true]);
    }

    private function makeOrder(string $orderNo, string $totalAmount): SalesOrder
    {
        return SalesOrder::create([
            'order_no' => $orderNo,
            'order_type' => 'normal',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => '2026-10-15',
            'total_amount' => $totalAmount,
            'status' => 'pending',
        ]);
    }

    /** @return array<int, string> */
    private function listedOrderNos(string $query): array
    {
        $this->actingAsAdmin();
        $res = $this->get('/admin/business/sales-order?'.$query);
        $res->assertOk();

        return array_column($res->json('data.list') ?? [], 'order_no');
    }

    public function test_amount_range_is_closed_interval_on_both_ends(): void
    {
        $this->makeOrder('AMT-AT-MIN', '100.00');
        $this->makeOrder('AMT-IN', '250.00');
        $this->makeOrder('AMT-AT-MAX', '500.00');
        $this->makeOrder('AMT-BELOW', '99.99');
        $this->makeOrder('AMT-ABOVE', '500.01');

        $nos = $this->listedOrderNos('min_amount=100&max_amount=500&quick_date=');

        $this->assertContains('AMT-AT-MIN', $nos);
        $this->assertContains('AMT-IN', $nos);
        $this->assertContains('AMT-AT-MAX', $nos);
        $this->assertNotContains('AMT-BELOW', $nos);
        $this->assertNotContains('AMT-ABOVE', $nos);
    }

    public function test_single_side_amount_limit_is_open_on_the_other_end(): void
    {
        $this->makeOrder('AMT-LOW', '10.00');
        $this->makeOrder('AMT-HIGH', '9999.00');

        $nos = $this->listedOrderNos('min_amount=5000&quick_date=');
        $this->assertSame(['AMT-HIGH'], $nos);

        $nos = $this->listedOrderNos('max_amount=1000&quick_date=');
        $this->assertSame(['AMT-LOW'], $nos);
    }
}
