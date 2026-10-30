<?php

namespace Tests\Stock\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Tests\TestCase;

/**
 * stock:warning 预警阈值边界测试
 *
 * 预警口径（StockWarning 命令 docblock，明确定义）：
 *   stock_qty < safety_stock 且 safety_stock > 0
 * 即「严格小于」才触发；等于阈值不触发；safety_stock=0 视为未设预警线不触发。
 *
 * 本类守住该口径的三条边界：
 *   B1 等于阈值（stock_qty == safety_stock）不触发；
 *   B2 低于阈值 1 件触发；
 *   B3 safety_stock = 0（未设线）不触发，哪怕库存为 0。
 */
class StockWarningCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeStockKeeper(): int
    {
        // 造一个持有 inventory.stock-check 权限的库管用户，供命令反查收件人
        $user = $this->makeAdmin();
        $roleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        $permId = (int) DB::table('auth_permission')->where('name', 'inventory.stock-check')->value('id');
        if (! $permId) {
            $permId = (int) DB::table('auth_permission')->insertGetId([
                'name' => 'inventory.stock-check',
                'title' => '库存核对',
                'type' => 'menu',
                'parent_id' => 0,
                'sort' => 0,
                'status' => 1,
            ]);
        }
        if (! DB::table('auth_role_permission')->where('role_id', $roleId)->where('permission_id', $permId)->exists()) {
            DB::table('auth_role_permission')->insert([
                'role_id' => $roleId,
                'permission_id' => $permId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $user->id;
    }

    private function seedProduct(int $stockQty, int $safetyStock): int
    {
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'WHWARN'],
            ['name' => '预警仓', 'type' => 'normal', 'is_active' => true]
        );
        $product = Product::create([
            'name' => '预警商品_'.fake()->unique()->numerify('###'),
            'code' => 'PW'.fake()->unique()->numerify('####'),
            'is_active' => true,
            'is_online' => true,
            'cost_price' => 1.0,
        ]);
        // safety_stock / stock_qty 不在 $fillable 里（属 Dashboard 迁移字段），直接落库
        DB::table('products')->where('id', $product->id)->update([
            'stock_qty' => $stockQty,
            'safety_stock' => $safetyStock,
        ]);
        Stock::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $stockQty,
        ]);

        return (int) $product->id;
    }

    public function test_warning_not_triggered_when_stock_equals_threshold(): void
    {
        $this->makeStockKeeper();
        // B1：恰好等于阈值（stock_qty == safety_stock）→ 严格小于才触发，故不触发
        $this->seedProduct(20, 20);

        $this->artisan('stock:warning', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('messages')->count(), '等于阈值不应触发预警');
    }

    public function test_warning_triggered_one_below_threshold(): void
    {
        $this->makeStockKeeper();
        // B2：低于阈值 1 件 → 触发
        $this->seedProduct(19, 20);

        $this->artisan('stock:warning', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(1, DB::table('messages')->count(), '低于阈值 1 件应触发预警');
    }

    public function test_warning_not_triggered_when_safety_stock_is_zero(): void
    {
        $this->makeStockKeeper();
        // B3：safety_stock = 0 视为未设线，即使库存为 0 也不触发
        $this->seedProduct(0, 0);

        $this->artisan('stock:warning', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('messages')->count(), 'safety_stock=0 不应触发预警');
    }
}
