<?php

namespace Tests\Dashboard\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Brand;
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
        $this->getJson('/admin/dashboard/core-metrics')->assertOk();
        $this->getJson('/admin/dashboard/product-rank')->assertOk();
        $this->getJson('/admin/dashboard/region-sales')->assertOk();
        $this->getJson('/admin/dashboard/delivery-status')->assertOk();
        $this->getJson('/admin/dashboard/finance-overview')->assertOk();
        $this->getJson('/admin/dashboard/brand-proportion')->assertOk();
        $this->getJson('/admin/dashboard/brand-top10')->assertOk();
    }

    /**
     * 品牌销售占比 / TOP10 必须真实按品牌汇总，而不是返回空或演示数据。
     *
     * 前端仪表盘在数据为空时会回退到硬编码演示值（电子产品/服装…），
     * 后端这两个端点必须保证：有销售数据时返回真实品牌金额，断言内容而非只断言 200。
     */
    public function test_brand_proportion_and_top10_aggregate_by_brand(): void
    {
        $this->actingAsAdmin();

        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => '主仓库', 'type' => 'normal', 'is_active' => true]);
        $customer = Customer::create(['code' => 'C1', 'name' => '客户', 'is_active' => true]);

        $brandA = Brand::create(['name' => '品牌A', 'code' => 'BR0001', 'is_active' => true]);
        $brandB = Brand::create(['name' => '品牌B', 'code' => 'BR0002', 'is_active' => true]);

        $pA = Product::create(['name' => '可乐', 'code' => 'PA', 'price_small' => 10, 'price_unit_small' => '瓶', 'is_active' => true, 'brand_id' => $brandA->id]);
        $pB = Product::create(['name' => '雪碧', 'code' => 'PB', 'price_small' => 10, 'price_unit_small' => '瓶', 'is_active' => true, 'brand_id' => $brandB->id]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'XS1', 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(), 'total_qty' => 20, 'total_amount' => 300, 'status' => '已收款',
        ]);
        DB::table('sales_order_items')->insert([
            ['sales_order_id' => $orderId, 'product_id' => $pA->id, 'quantity' => 10, 'price' => 10, 'amount' => 100],
            ['sales_order_id' => $orderId, 'product_id' => $pB->id, 'quantity' => 20, 'price' => 10, 'amount' => 200],
        ]);

        $prop = $this->getJson('/admin/dashboard/brand-proportion')->assertOk();
        $this->assertSame(300.0, (float) $prop->json('data.total'), '品牌占比总额应为三个金额之和');
        $names = collect($prop->json('data.list'))->pluck('name');
        $this->assertContains('品牌A', $names->all());
        $this->assertContains('品牌B', $names->all());

        $top = $this->getJson('/admin/dashboard/brand-top10')->assertOk();
        $topList = $top->json('data.list');
        $this->assertCount(2, $topList, '只有两个品牌有销售，TOP10 应返回 2 条');
        // TOP10 按金额降序，品牌B(200) 应在品牌A(100) 前
        $this->assertSame('品牌B', $topList[0]['category']);
        $this->assertSame(200.0, (float) $topList[0]['value']);
        $this->assertSame('品牌A', $topList[1]['category']);
    }

    /**
     * 智慧大屏必须作为独立顶级菜单存在，且不能再用 /dashboard —— 该路径在
     * systemRoutes.js 里静态绑给"首页"，同一路径静态路由先命中，点大屏会打开首页。
     * meta.icon 还必须是 ElIcon 前缀（前端 boot.js 只注册带该前缀的图标组件）。
     */
    public function test_bigscreen_menu_is_registered(): void
    {
        $menu = DB::table('auth_permission')->where('name', 'bigscreen')->where('type', 'menu')->first();

        $this->assertNotNull($menu, '智慧大屏菜单缺失');
        $this->assertSame('/big-screen', $menu->path);
        $this->assertSame('dashboard/index', $menu->component);
        $this->assertSame(0, (int) $menu->parent_id);

        $meta = json_decode($menu->meta ?? 'null', true) ?? [];
        $this->assertStringStartsWith('ElIcon', $meta['icon'] ?? '');

        $legacy = DB::table('auth_permission')
            ->where('name', 'dashboard')
            ->where('component', 'dashboard/index')
            ->exists();
        $this->assertFalse($legacy, '指向 /dashboard 的旧大屏菜单必须清掉');
    }

    /**
     * 销售单实际用的是中文状态（已收款/待收款/配送中…），早期大屏只认 approved，
     * 导致正常销售单一条都统计不到、今日销售额恒为 0。这里守住这个口径。
     */
    public function test_metrics_count_chinese_status_sales_orders(): void
    {
        $this->actingAsAdmin();

        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => '主仓库', 'type' => 'normal', 'is_active' => true]);
        $customer = Customer::create(['code' => 'C1', 'name' => '客户', 'address' => '广东省深圳市南山区', 'is_active' => true]);
        // cost_price 必须设在商品上：利润按 products.cost_price 计算（口径同 ProfitController）
        $p = Product::create(['name' => '可乐', 'code' => 'P1', 'price_small' => 3, 'price_unit_small' => '瓶', 'cost_price' => 2, 'is_active' => true]);
        DB::table('stocks')->insert(['product_id' => $p->id, 'warehouse_id' => $warehouse->id, 'quantity' => 50, 'cost_price' => 2]);

        // 必须取回真实自增 id：MySQL 的 AUTO_INCREMENT 在 RefreshDatabase 的事务回滚后
        // 不会回退，硬编码 1 会在第二个用例里撞上外键约束（SQLite 每次重建内存库，
        // 所以本地跑不出来，只有 CI 的 MySQL 会红）。
        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'XS1',
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'total_qty' => 10,
            'total_amount' => 100,
            'status' => '已收款',
        ]);
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $orderId,
            'product_id' => $p->id,
            'quantity' => 10,
            'price' => 10,
            'amount' => 100,
        ]);

        $res = $this->getJson('/admin/dashboard/metrics');
        $res->assertOk();
        $this->assertEquals(100, (float) $res->json('data.today_sales'), '中文状态(已收款)的销售单必须计入今日销售额');
        $this->assertEquals(1, (int) $res->json('data.today_order_count'));

        // 核心指标：总订单数/总销售额/总利润（利润 = 销售额 - 数量×成本价 = 100 - 10×2）
        $core = $this->getJson('/admin/dashboard/core-metrics');
        $core->assertOk();
        $this->assertEquals(1, (int) $core->json('data.total_order_count'));
        $this->assertEquals(100, (float) $core->json('data.total_sales_amount'));
        $this->assertEquals(80, (float) $core->json('data.total_profit'));

        // 地区销售：从客户地址里解析出「广东」
        $region = $this->getJson('/admin/dashboard/region-sales');
        $region->assertOk();
        $this->assertContains('广东', collect($region->json('data.list'))->pluck('name')->all());

        // 配送状态：已收款归入「已完成」
        $delivery = $this->getJson('/admin/dashboard/delivery-status');
        $delivery->assertOk();
        $done = collect($delivery->json('data.list'))->firstWhere('name', '已完成');
        $this->assertEquals(1, (int) $done['value']);
    }
}
