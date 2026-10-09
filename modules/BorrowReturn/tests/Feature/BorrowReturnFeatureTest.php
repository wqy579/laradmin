<?php

namespace Tests\BorrowReturn\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\BorrowReturn\Models\BorrowOrder;
use Modules\BorrowReturn\Models\BorrowReturnOrder;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

class BorrowReturnFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): array
    {
        $admin = $this->actingAsAdmin();
        $customer = Customer::create(['code' => 'C1', 'name' => '测试客户', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => '主仓库', 'type' => 'normal', 'is_active' => true]);

        $p1 = Product::create(['name' => '可乐', 'code' => 'P1', 'price_small' => 3, 'price_unit_small' => '瓶', 'is_active' => true]);
        $p2 = Product::create(['name' => '薯片', 'code' => 'P2', 'price_small' => 5, 'price_unit_small' => '包', 'is_active' => true]);

        DB::table('stocks')->insert([
            ['product_id' => $p1->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100, 'cost_price' => 2],
            ['product_id' => $p2->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100, 'cost_price' => 3],
        ]);

        return ['admin' => $admin, 'customer' => $customer, 'warehouse' => $warehouse, 'p1' => $p1, 'p2' => $p2];
    }

    /** 库存行：quantity=账面总量，frozen_qty=冻结量，可用=两者之差 */
    private function stockRow(int $productId, int $warehouseId)
    {
        return DB::table('stocks')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
    }

    /**
     * 菜单图标：借还货管理挂的是循环箭头。缺 meta.icon 时前端 <component :is="">
     * 解析不到，侧边栏静默渲染成空 <el-icon>，且不报错——只能靠这条用例钉住。
     * MenuIconTest 只校验 parent_id=0 的顶层菜单，借还货是 inventory 的子菜单，
     * 正好不在它的覆盖范围内。
     */
    public function test_borrow_menu_has_icon(): void
    {
        $meta = json_decode(DB::table('auth_permission')->where('name', 'borrow-return')->value('meta') ?? 'null', true) ?? [];

        $this->assertSame('ElIconRefresh', $meta['icon'] ?? '');
    }

    /**
     * 分组菜单的路由约定（2026-10-10 生产事故）：分组自带 component 时，子页面会变成
     * 它的嵌套路由，必须靠分组组件内部的 <router-view> 才能渲染——当时那个落地页没写，
     * 结果点进去永远卡在 loading 文案上。约定是：component 留空 + path 指向第一个子路由。
     */
    public function test_borrow_menu_group_points_to_first_child_route(): void
    {
        $group = DB::table('auth_permission')->where('name', 'borrow-return')->firstOrFail();

        $this->assertEmpty($group->component, '分组菜单不得自带 component，否则子页面会被当成它的嵌套路由');

        $firstChild = DB::table('auth_permission')
            ->where('parent_id', $group->id)
            ->orderBy('sort')
            ->firstOrFail();

        $this->assertSame($firstChild->path, $group->path, '分组 path 必须指向第一个子路由');
        $this->assertNotEmpty($firstChild->component, '第一个子菜单必须带 component');
    }

    public function test_borrow_confirm_freezes_stock_and_records_balance(): void
    {
        $d = $this->seedData();

        $store = $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [
                ['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3],
                ['product_id' => $d['p2']->id, 'borrow_qty' => 5, 'unit_price' => 5],
            ],
        ]);
        $store->assertOk();
        $id = $store->json('data.id');

        $this->assertEquals(100, (int) $this->stockRow($d['p1']->id, $d['warehouse']->id)->quantity, '草稿不动库存');
        $this->assertEquals(0, (int) $this->stockRow($d['p1']->id, $d['warehouse']->id)->frozen_qty, '草稿不冻结');

        $confirm = $this->postJson("/admin/business/borrow-order/{$id}/confirm");
        $confirm->assertOk();

        // 方案口径：借货确认只「冻结」库存，账面总量 quantity 不减
        $row = $this->stockRow($d['p1']->id, $d['warehouse']->id);
        $this->assertEquals(100, (int) $row->quantity, '借货确认不扣减账面库存');
        $this->assertEquals(10, (int) $row->frozen_qty, '借货确认冻结 10');
        $this->assertEquals(BorrowOrder::STATUS_UNRETURNED, $confirm->json('data.status'));

        $this->assertEquals(10, (int) DB::table('customer_borrow_balances')->where('product_id', $d['p1']->id)->where('customer_id', $d['customer']->id)->value('qty'));
    }

    public function test_borrow_cancel_releases_frozen_stock(): void
    {
        $d = $this->seedData();

        $id = $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3]],
        ])->json('data.id');
        $this->postJson("/admin/business/borrow-order/{$id}/confirm")->assertOk();
        $this->assertEquals(10, (int) $this->stockRow($d['p1']->id, $d['warehouse']->id)->frozen_qty);

        $cancel = $this->postJson("/admin/business/borrow-order/{$id}/cancel", ['cancel_reason' => '客户取消']);
        $cancel->assertOk();

        $row = $this->stockRow($d['p1']->id, $d['warehouse']->id);
        $this->assertEquals(0, (int) $row->frozen_qty, '取消借货应释放冻结');
        $this->assertEquals(100, (int) $row->quantity, '取消不改变账面总量');
        $this->assertEquals(BorrowOrder::STATUS_CANCELLED, DB::table('borrow_orders')->where('id', $id)->value('status'));
    }

    public function test_return_approve_unfreezes_stock_and_updates_status(): void
    {
        $d = $this->seedData();

        $borrow = $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3]],
        ])->json('data.id');
        $this->postJson("/admin/business/borrow-order/{$borrow}/confirm")->assertOk();
        $this->assertEquals(10, (int) $this->stockRow($d['p1']->id, $d['warehouse']->id)->frozen_qty, '借货后冻结 10');

        $pending = $this->getJson("/admin/business/return-order/pending-borrow-items?borrow_order_id={$borrow}");
        $pending->assertOk();
        $boItemId = $pending->json('data.items.0.borrow_order_item_id');

        $return = $this->postJson('/admin/business/return-order', [
            'borrow_order_id' => $borrow,
            'items' => [
                ['borrow_order_item_id' => $boItemId, 'product_id' => $d['p1']->id, 'return_qty' => 4, 'good_qty' => 4, 'bad_qty' => 0],
            ],
        ]);
        $return->assertOk();
        $rid = $return->json('data.id');

        $approve = $this->postJson("/admin/business/return-order/{$rid}/approve");
        $approve->assertOk();
        $this->assertEquals(BorrowReturnOrder::STATUS_APPROVED, $approve->json('data.status'));

        // 还货 4：只解冻 4（借货未扣减 quantity，完好归还无需回库），冻结余 6
        $row = $this->stockRow($d['p1']->id, $d['warehouse']->id);
        $this->assertEquals(100, (int) $row->quantity, '还货只解冻，账面总量不变');
        $this->assertEquals(6, (int) $row->frozen_qty, '还 4 后冻结余 6');
        $this->assertEquals(BorrowOrder::STATUS_PARTIAL, DB::table('borrow_orders')->where('id', $borrow)->value('status'), '部分还');
    }

    public function test_convert_creates_sales_order_and_increases_ar(): void
    {
        $d = $this->seedData();

        $borrow = $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3]],
        ])->json('data.id');
        $this->postJson("/admin/business/borrow-order/{$borrow}/confirm")->assertOk();

        $arBefore = (float) DB::table('customers')->where('id', $d['customer']->id)->value('balance');
        $convert = $this->postJson("/admin/business/borrow-order/{$borrow}/convert");
        $convert->assertOk();
        $this->assertEquals(BorrowOrder::STATUS_CONVERTED, $convert->json('data.status'));
        $this->assertSame(1, DB::table('sales_orders')->where('order_type', 'borrow_convert')->count());
        $this->assertEquals($arBefore + 30, (float) DB::table('customers')->where('id', $d['customer']->id)->value('balance'), '应收账款+30');

        // 转销售 = 解冻 + 实际出库：账面总量减 10、冻结清零
        $row = $this->stockRow($d['p1']->id, $d['warehouse']->id);
        $this->assertEquals(90, (int) $row->quantity, '转销售应实际出库');
        $this->assertEquals(0, (int) $row->frozen_qty, '转销售后冻结清零');
    }

    public function test_borrow_summary_endpoints(): void
    {
        $d = $this->seedData();
        $this->postJson('/admin/business/borrow-order', [
            'customer_id' => $d['customer']->id,
            'warehouse_id' => $d['warehouse']->id,
            'items' => [['product_id' => $d['p1']->id, 'borrow_qty' => 10, 'unit_price' => 3]],
        ]);
        $this->getJson('/admin/business/borrow-summary')->assertOk();
        $this->getJson('/admin/business/borrow-summary/customer-detail')->assertOk();
        $this->getJson('/admin/business/borrow-summary/trend')->assertOk();
    }
}
