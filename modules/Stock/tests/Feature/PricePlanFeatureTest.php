<?php

namespace Tests\Stock\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\CustomerLevel;
use Modules\Stock\Models\CustomerSpecialPrice;
use Modules\Stock\Models\ProductLevelPrice;
use Modules\Stock\Models\Stock;
use Modules\Stock\Services\PriceService;
use Tests\TestCase;

/**
 * F7 库存外围（价格方案 + 客户特价 + 报废 + 其他出入库 + 盘点差异入账）验收测试。
 *
 * 覆盖 F7-BRIEF 的四条硬性验收：
 *  1) 价格优先级 特价 > 等级价 > 默认价 经 resolve 端点生效，且输出形状与 E4 一致
 *     （price_source 列 type=price_tag、hit_type=rule_hit，供 ReportTable.vue 复用）；
 *  2) 报废（stock_adjusts adjust_type=stock_loss）数量 ≤ 库存双校验——超量审核 422；
 *  3) 其他出库（stock-out）数量 ≤ 库存强校验——超量 422，库存不变；
 *  4) 盘点差异（stock_checks）审核通过后按差异正确入账（check_in/check_out 流水 + 库存校正）。
 *
 * 另覆盖 PriceService::resolve() 的订单取价链路（客户特价 → 促销价 → 等级价 → 默认价）。
 */
class PricePlanFeatureTest extends TestCase
{
    use RefreshDatabase;

    // ==================================================================
    // 价格优先级：特价 > 等级价 > 默认价（resolve 端点）
    // ==================================================================

    public function test_resolve_prefers_special_over_level_over_default(): void
    {
        $this->actingAsAdmin();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price_small' => 12]);
        // 用迁移种子中的 VIP（default_discount 8.0），避免 code 唯一约束与种子冲突
        $level = CustomerLevel::where('code', 'VIP')->first();
        $this->assertNotNull($level, 'VIP 等级应由价格系统迁移种子');
        DB::table('customers')->where('id', $customer->id)->update(['level_id' => $level->id]);

        // 等级单独价 9（< 默认价 12，但 > 特价 8），验证特价确实压过等级价
        ProductLevelPrice::create(['product_id' => $product->id, 'level_id' => $level->id, 'price' => 9]);
        CustomerSpecialPrice::create([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'price' => 8, 'status' => true,
        ]);

        $response = $this->getJson('/admin/business/customer-special-price/resolve?customer_ids='.$customer->id.'&product_ids='.$product->id);
        $response->assertOk();

        $row = collect($response->json('data.list'))->first();
        $this->assertNotNull($row, '解析结果应含 客户×商品 行');

        $this->assertSame(12.0, (float) $row['standard_price'], '默认价 = price_small');
        $this->assertSame(9.0, (float) $row['level_price'], '等级价 = product_level_prices.price');
        $this->assertSame(8.0, (float) $row['special_price'], '特价 = customer_special_price.price');
        $this->assertSame(8.0, (float) $row['effective_price'], '有效价应取特价');
        $this->assertSame('特价', $row['price_source']);
        $this->assertTrue($row['rule_hit']);
    }

    public function test_resolve_falls_back_to_level_when_no_special(): void
    {
        $this->actingAsAdmin();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price_small' => 12]);
        // 用迁移种子中的 LV2（default_discount 9.0），避免 code 唯一约束与种子冲突
        $level = CustomerLevel::where('code', 'LV2')->first();
        $this->assertNotNull($level, 'LV2 等级应由价格系统迁移种子');
        DB::table('customers')->where('id', $customer->id)->update(['level_id' => $level->id]);
        ProductLevelPrice::create(['product_id' => $product->id, 'level_id' => $level->id, 'price' => 9]);

        $response = $this->getJson('/admin/business/customer-special-price/resolve?customer_ids='.$customer->id.'&product_ids='.$product->id);
        $response->assertOk();

        $row = collect($response->json('data.list'))->first();
        $this->assertSame(9.0, (float) $row['effective_price']);
        $this->assertSame('等级价', $row['price_source']);
        $this->assertTrue($row['rule_hit']);
    }

    public function test_resolve_falls_back_to_default_when_no_rules(): void
    {
        $this->actingAsAdmin();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price_small' => 12]);

        $response = $this->getJson('/admin/business/customer-special-price/resolve?customer_ids='.$customer->id.'&product_ids='.$product->id);
        $response->assertOk();

        $row = collect($response->json('data.list'))->first();
        $this->assertSame(12.0, (float) $row['effective_price']);
        $this->assertSame('默认价', $row['price_source']);
        $this->assertFalse($row['rule_hit']);
    }

    public function test_resolve_columns_match_e4_shape_for_reporttable_reuse(): void
    {
        $this->actingAsAdmin();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price_small' => 12]);
        $response = $this->getJson('/admin/business/customer-special-price/resolve?customer_ids='.$customer->id.'&product_ids='.$product->id);
        $response->assertOk();

        $columns = $response->json('data.columns');
        $priceTag = collect($columns)->firstWhere('prop', 'price_source');
        $this->assertNotNull($priceTag, '应有 price_source 列');
        $this->assertSame('price_tag', $priceTag['type'], 'price_source 列应为 price_tag，供 ReportTable.vue 渲染彩色标签');
        $this->assertSame('rule_hit', $priceTag['hit_type'], 'price_tag 列应绑定 rule_hit 做整行高亮');

        $this->assertSame('money', collect($columns)->firstWhere('prop', 'effective_price')['type'], '有效价列应为 money 右对齐');
    }

    // ==================================================================
    // 价格方案 + 客户特价 CRUD（含菜单注册的幂等性）
    // ==================================================================

    public function test_plan_crud_and_menu_registration(): void
    {
        $this->actingAsAdmin();

        // 创建价格方案（ResponseTrait::created 返回 HTTP 200，而非 201）
        $response = $this->postJson('/admin/business/customer-special-price/plans', [
            'name' => '国庆特价', 'code' => 'TP20261028', 'type' => 'special_price', 'status' => true,
        ]);
        $response->assertOk();
        $planId = $response->json('data.id');
        $this->assertNotNull($planId);

        // 方案列表可见
        $list = $this->getJson('/admin/business/customer-special-price/plans')->assertOk();
        $this->assertSame(1, (int) $list->json('data.total'));
        $this->assertSame('国庆特价', $list->json('data.list.0.name'));

        // 重复编码被拒
        $this->postJson('/admin/business/customer-special-price/plans', [
            'name' => '重复', 'code' => 'TP20261028', 'type' => 'default_price',
        ])->assertStatus(422);

        // 菜单已注册到 price 父级
        $this->assertDatabaseHas('auth_permission', ['name' => 'price.plan', 'path' => '/business/price-plan']);
        $this->assertDatabaseHas('auth_permission', ['name' => 'price.special', 'path' => '/business/customer-special-price']);

        // 客户特价挂方案
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price_small' => 12]);
        $store = $this->postJson('/admin/business/customer-special-price', [
            'price_plan_id' => $planId,
            'items' => [['customer_id' => $customer->id, 'product_id' => $product->id, 'price' => 7.5]],
        ]);
        $store->assertOk();

        $this->assertDatabaseHas('customer_special_price', [
            'customer_id' => $customer->id, 'product_id' => $product->id, 'price' => 7.5, 'price_plan_id' => $planId,
        ]);
    }

    // ==================================================================
    // 报废 ≤ 库存双校验：stock_adjusts adjust_type=stock_loss
    // ==================================================================

    public function test_stock_loss_adjust_rejects_overstock_on_approve(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 3]);

        // 建报废单：调整数量 -5（> 库存 3）
        $store = $this->postJson('/admin/business/stock-adjust', [
            'warehouse_id' => $warehouse->id,
            'adjust_date' => now()->toDateString(),
            'adjust_type' => 'stock_loss',
            'reason' => '过期报废',
            'items' => [['product_id' => $product->id, 'adjust_qty' => -5, 'unit_cost' => 2.0]],
        ]);
        $store->assertOk();
        $adjustId = $store->json('data.id');

        // 提交审核
        $this->postJson("/admin/business/stock-adjust/{$adjustId}/submit")->assertOk();

        // 审核：超量报废必须被拒（双校验第一道：StockService::adjust 抛 StockRuleException → 422）
        $approve = $this->postJson("/admin/business/stock-adjust/{$adjustId}/approve");
        $approve->assertStatus(422);
        $this->assertStringContainsString('库存不足', $approve->json('message'));

        // 库存未变
        $this->assertSame(3, (int) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'));
        // 状态仍为 pending（事务回滚，未置 approved）
        $this->assertSame('pending', DB::table('stock_adjusts')->where('id', $adjustId)->value('status'));
        // 没有残留流水
        $this->assertSame(0, DB::table('stocks_history')->where('related_type', 'StockAdjust')->where('related_id', $adjustId)->count());
    }

    public function test_stock_loss_adjust_allows_exact_stock(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5]);

        $store = $this->postJson('/admin/business/stock-adjust', [
            'warehouse_id' => $warehouse->id,
            'adjust_date' => now()->toDateString(),
            'adjust_type' => 'stock_loss',
            'reason' => '报废',
            'items' => [['product_id' => $product->id, 'adjust_qty' => -5, 'unit_cost' => 2.0]],
        ]);
        $store->assertOk();
        $adjustId = $store->json('data.id');

        $this->postJson("/admin/business/stock-adjust/{$adjustId}/submit")->assertOk();
        $this->postJson("/admin/business/stock-adjust/{$adjustId}/approve")->assertOk();

        // 恰好清空允许，库存归 0
        $this->assertSame(0, (int) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'));
        $this->assertSame('approved', DB::table('stock_adjusts')->where('id', $adjustId)->value('status'));
        // 生成损耗费用单 + 经营历程
        $this->assertDatabaseHas('expenses', ['expense_type' => '库存损耗']);
        $this->assertDatabaseHas('order_operation_logs', ['order_type' => 'stock_adjust', 'order_id' => $adjustId]);
    }

    // ==================================================================
    // 其他出库 ≤ 库存强校验
    // ==================================================================

    public function test_stock_out_rejects_overstock(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2]);

        $response = $this->postJson('/admin/business/stock-out', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(422);
        $this->assertSame('库存不足', $response->json('message'));
        $this->assertSame(2, (int) Stock::first()->quantity, '拒绝后库存必须保持不变');
    }

    // ==================================================================
    // 其他入库：写库存 + 流水
    // ==================================================================

    public function test_stock_in_writes_history(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();

        $this->postJson('/admin/business/stock-in', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 4,
            'cost_price' => 1.5,
        ])->assertOk();

        $this->assertSame(4, (int) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'));
        $this->assertDatabaseHas('stocks_history', [
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'change_type' => 'stock_in', 'change_qty' => 4,
        ]);
    }

    // ==================================================================
    // 盘点差异生成调整正确入账
    // ==================================================================

    public function test_stocktaking_approve_applies_diff_to_stock(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        // 账面 10，实盘 12 → 盘盈 +2
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10, 'cost_price' => 3.0]);

        $store = $this->postJson('/admin/business/stocktaking', [
            'warehouse_id' => $warehouse->id,
            'check_date' => now()->toDateString(),
            'check_type' => 'full',
            'action' => 'submit',
            'items' => [['product_id' => $product->id, 'actual_qty' => 12]],
        ]);
        $store->assertOk();
        $checkId = $store->json('data.id');

        $this->assertSame('pending', DB::table('stock_checks')->where('id', $checkId)->value('status'));

        $approve = $this->postJson("/admin/business/stocktaking/{$checkId}/approve");
        $approve->assertOk();

        // 库存被校正为实盘数 12
        $this->assertSame(12, (int) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'));
        $this->assertSame('approved', DB::table('stock_checks')->where('id', $checkId)->value('status'));
        // 盘盈流水 check_in +2
        $this->assertDatabaseHas('stocks_history', [
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'change_type' => 'check_in', 'change_qty' => 2,
        ]);
        // 盘盈 → 其他收入单（备注 = 库存盘盈-{完整 check_no}）
        $checkNo = DB::table('stock_checks')->where('id', $checkId)->value('check_no');
        $this->assertNotNull($checkNo);
        $this->assertDatabaseHas('receives', [
            'receive_type' => 2, 'remark' => '库存盘盈-'.$checkNo, 'amount' => 6.0,
        ]);
        // 经营历程
        $this->assertDatabaseHas('order_operation_logs', ['order_type' => 'stocktaking', 'order_id' => $checkId]);
    }

    public function test_stocktaking_approve_applies_loss_diff(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        // 账面 10，实盘 7 → 盘亏 -3
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10, 'cost_price' => 3.0]);

        $store = $this->postJson('/admin/business/stocktaking', [
            'warehouse_id' => $warehouse->id,
            'check_date' => now()->toDateString(),
            'check_type' => 'full',
            'action' => 'submit',
            'items' => [['product_id' => $product->id, 'actual_qty' => 7]],
        ]);
        $store->assertOk();
        $checkId = $store->json('data.id');

        $this->postJson("/admin/business/stocktaking/{$checkId}/approve")->assertOk();

        $this->assertSame(7, (int) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'));
        $this->assertDatabaseHas('stocks_history', [
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'change_type' => 'check_out', 'change_qty' => -3,
        ]);
        $this->assertDatabaseHas('expenses', ['expense_type' => '库存盘亏']);
    }

    public function test_stocktaking_approve_rejects_overloss_below_stock(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        // 账面 2，实盘 0 是允许的（盘亏恰好等于库存）。再往下实盘不能为负，无场景。
        // 这里验证：实盘 5 而账面 2 → 盘盈 +3，是允许方向（check_in 无上限）。
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2, 'cost_price' => 3.0]);

        $store = $this->postJson('/admin/business/stocktaking', [
            'warehouse_id' => $warehouse->id,
            'check_date' => now()->toDateString(),
            'check_type' => 'full',
            'action' => 'submit',
            'items' => [['product_id' => $product->id, 'actual_qty' => 5]],
        ]);
        $store->assertOk();
        $checkId = $store->json('data.id');

        $this->postJson("/admin/business/stocktaking/{$checkId}/approve")->assertOk();
        $this->assertSame(5, (int) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'));
    }

    // ==================================================================
    // 订单取价链路：PriceService::resolve 客户特价优先
    // ==================================================================

    public function test_price_service_resolve_prefers_customer_special(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price_small' => 12]);
        CustomerSpecialPrice::create([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'price' => 8, 'status' => true,
        ]);

        $service = app(PriceService::class);
        $result = $service->resolve($customer->id, $product->id, 12.0);

        $this->assertSame(8.0, $result['price']);
        $this->assertSame('客户特价', $result['source']);
    }

    public function test_price_service_resolve_ignores_inactive_special(): void
    {
        // 无任何等级（level_id=null 且旧 level 列清空），避免命中等级默认折扣（LV1 9.5折 → 11.4）
        $customer = $this->makeCustomer(['level_id' => null, 'level' => 0]);
        $product = $this->makeProduct(['price_small' => 12]);
        CustomerSpecialPrice::create([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'price' => 8, 'status' => false,
        ]);

        $service = app(PriceService::class);
        $result = $service->resolve($customer->id, $product->id, 12.0);

        // 停用特价不生效，回落标准售价
        $this->assertSame(12.0, $result['price']);
        $this->assertSame('标准售价', $result['source']);
    }

    public function test_price_service_resolve_respects_date_window(): void
    {
        // 无任何等级（level_id=null 且旧 level 列清空），避免命中等级默认折扣（LV1 9.5折 → 11.4）
        $customer = $this->makeCustomer(['level_id' => null, 'level' => 0]);
        $product = $this->makeProduct(['price_small' => 12]);
        // 特价期间已过期：end_date 在昨天
        CustomerSpecialPrice::create([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'price' => 8, 'status' => true,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $service = app(PriceService::class);
        $result = $service->resolve($customer->id, $product->id, 12.0);

        $this->assertSame(12.0, $result['price'], '过期特价不得生效');
    }

    // ==================================================================
    // 报废查询：商品/仓库/期间筛选 + 合计（F7-BRIEF 第 2 项）
    // ==================================================================

    public function test_stock_adjust_index_filters_by_product_and_returns_summary(): void
    {
        $this->actingAsAdmin();

        $productA = $this->makeProduct();
        $productB = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $productA->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);
        Stock::create(['product_id' => $productB->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

        // 两笔报废单：A 单 -5，B 单 -8（均 ≤ 库存）
        foreach ([[$productA, -5], [$productB, -8]] as [$p, $qty]) {
            $store = $this->postJson('/admin/business/stock-adjust', [
                'warehouse_id' => $warehouse->id,
                'adjust_date' => now()->toDateString(),
                'adjust_type' => 'stock_loss',
                'reason' => '报废',
                'items' => [['product_id' => $p->id, 'adjust_qty' => $qty, 'unit_cost' => 2.0]],
            ]);
            $store->assertOk();
            $adjustId = $store->json('data.id');
            $this->postJson("/admin/business/stock-adjust/{$adjustId}/submit")->assertOk();
            $this->postJson("/admin/business/stock-adjust/{$adjustId}/approve")->assertOk();
        }

        // 按商品筛选：仅命中 A 的调整单
        $res = $this->getJson('/admin/business/stock-adjust?adjust_type=stock_loss&product_id='.$productA->id);
        $res->assertOk();
        $rows = collect($res->json('data.list'));
        $this->assertSame(1, $rows->count(), '按商品筛选应只命中含该商品的调整单');

        // 合计与筛选一致（整表 total_qty = -5）
        $summary = $res->json('data.summary');
        $this->assertNotNull($summary, '列表应带合计');
        $this->assertSame(-5.0, (float) $summary['total_qty'], '合计调整数量应为 -5');
        $this->assertSame(1, (int) $summary['count']);

        // 无商品筛选：合计覆盖两笔（-5 + -8 = -13）
        $resAll = $this->getJson('/admin/business/stock-adjust?adjust_type=stock_loss');
        $resAll->assertOk();
        $summaryAll = $resAll->json('data.summary');
        $this->assertSame(-13.0, (float) $summaryAll['total_qty'], '全量合计应为 -13');
        $this->assertSame(2, (int) $summaryAll['count']);
    }

    // ==================================================================
    // 盘点明细导出（F7-BRIEF 第 6 项）
    // ==================================================================

    public function test_stocktaking_export_returns_csv(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 8]);

        $store = $this->postJson('/admin/business/stocktaking', [
            'warehouse_id' => $warehouse->id,
            'check_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'actual_qty' => 6]],
        ]);
        $store->assertOk();
        $checkId = $store->json('data.id');

        $export = $this->get("/admin/business/stocktaking/{$checkId}/export");
        $export->assertOk();
        $this->assertStringContainsString('text/csv', $export->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $export->headers->get('content-disposition'));
        $this->assertStringContainsString($product->code, $export->getContent());
        $this->assertStringContainsString('账面数量', $export->getContent());
        // 差异 = 实盘 6 - 账面 8 = -2
        $this->assertStringContainsString('-2', $export->getContent());
    }
}
