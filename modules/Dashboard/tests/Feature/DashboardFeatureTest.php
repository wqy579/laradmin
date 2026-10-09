<?php

namespace Tests\Dashboard\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

class DashboardFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_endpoints_return_ok(): void
    {
        $this->actingAsAdmin();

        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => '主仓库', 'type' => 'normal', 'is_active' => true]);
        $customer = Customer::create(['code' => 'C1', 'name' => '客户', 'is_active' => true]);
        $p = Product::create(['name' => '可乐', 'code' => 'P1', 'price_small' => 3, 'price_unit_small' => '瓶', 'is_active' => true]);
        DB::table('stocks')->insert(['product_id' => $p->id, 'warehouse_id' => $warehouse->id, 'quantity' => 50, 'cost_price' => 2]);

        DB::table('sales_orders')->insert([
            'order_no' => 'XS'.date('Ymd').'000001',
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'total_qty' => 10,
            'total_amount' => 100,
            'status' => 'approved',
        ]);

        $this->getJson('/admin/dashboard/metrics')->assertOk();
        $this->getJson('/admin/dashboard/realtime-orders')->assertOk();
        $this->getJson('/admin/dashboard/category-proportion')->assertOk();
        $this->getJson('/admin/dashboard/sales-trend')->assertOk();
        $this->getJson('/admin/dashboard/customer-rank')->assertOk();
        $this->getJson('/admin/dashboard/inventory-overview')->assertOk();
        $this->getJson('/admin/dashboard/inventory-warning')->assertOk();
        $this->getJson('/admin/dashboard/salesman-rank')->assertOk();
    }
}
