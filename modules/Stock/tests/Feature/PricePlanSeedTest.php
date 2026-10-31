<?php

namespace Tests\Stock\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\CustomerLevel;
use Modules\Stock\Models\CustomerSpecialPrice;
use Modules\Stock\Models\ProductLevelPrice;
use Modules\Stock\Seeders\PricePlanDemoSeeder;
use Modules\Stock\Services\PriceService;
use Tests\TestCase;

/**
 * QA-08 回归：价格方案 / 客户特价两页不再空表，且取价真的按客户出不同单价。
 *
 * 两页原先接口 200 但 0 行——不是查询写错，是库里根本没配置。这里守四件事：
 *  1) 迁移在库上落出默认方案，三种 type 都有样本；
 *  2) 重复播种不翻倍（部署只跑 `migrate --force`，同一迁移在存量库上会反复执行）；
 *  3) 没有演示主体时绝不写客户级价格——这三张表会真实改变成交价；
 *  4) 有主体时按「特价 > 等级价 > 默认价」解析，且同一商品换客户单价就变
 *     （简报指定的验收口径：「在订单弹窗验证单价是否随客户变化」）。
 *
 * 不引用 Business 的 BusinessDataSeeder：那会让 Stock 反向依赖 Business，
 * 与既有 Business->Stock 成环（ArchitectureTest 会拦）。演示主体改用
 * TestCase 的 makeCustomer/makeProduct 按同编码自建，效果等价。
 */
class PricePlanSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_default_plans_covering_all_types(): void
    {
        $codes = DB::table('price_plans')->pluck('code')->all();

        foreach (array_column(PricePlanDemoSeeder::PLANS, 'code') as $code) {
            $this->assertContains($code, $codes, "默认方案 {$code} 未由迁移落库");
        }

        $types = DB::table('price_plans')->pluck('type')->unique()->sort()->values()->all();
        $this->assertSame(['default_price', 'level_price', 'special_price'], $types,
            '三种方案类型都应有样本，否则方案页看起来仍像没配全');
    }

    public function test_seeding_twice_does_not_duplicate_plans(): void
    {
        $before = DB::table('price_plans')->orderBy('id')->pluck('code')->all();

        $this->seed(PricePlanDemoSeeder::class);
        $after = DB::table('price_plans')->orderBy('id')->pluck('code')->all();

        $this->assertSame($before, $after, '重复播种不应新增或改动方案行');
    }

    public function test_plans_endpoint_returns_rows_after_migration(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/admin/business/customer-special-price/plans')->assertOk();

        // QA-08 报的现象正是 total=0，这里正面锁死
        $this->assertSame(count(PricePlanDemoSeeder::PLANS), (int) $res->json('data.total'));
        $this->assertNotEmpty($res->json('data.list'));
    }

    /** 没有 CUST001/P001 时，客户级三处数据一律不写——不能给真实库凭空造成交价。 */
    public function test_customer_pricing_is_skipped_without_demo_anchors(): void
    {
        $this->assertSame(0, DB::table('customer_special_price')->count());
        $this->assertSame(0, DB::table('product_level_prices')->count());

        $this->seed(PricePlanDemoSeeder::class);

        $this->assertSame(0, DB::table('customer_special_price')->count(), '缺主体时不应写客户特价');
        $this->assertSame(0, DB::table('product_level_prices')->count(), '缺主体时不应写等级单独价');
    }

    public function test_seeded_prices_differ_per_customer(): void
    {
        $anchors = $this->makeDemoAnchors();
        $this->seed(PricePlanDemoSeeder::class);

        $lv1Name = $this->levelName('LV1');
        $service = app(PriceService::class);

        // 客户A × P001 → LV1 商品单独等级价 1.85（压过 9.5 折算出来的 1.90）
        $aP001 = $service->resolve($anchors['custA'], $anchors['p001'], 2.00);
        $this->assertSame($lv1Name.'价', $aP001['source'], '客户A 的 P001 应命中等级单独价');
        $this->assertSame(1.85, round((float) $aP001['price'], 2));

        // 客户B × P004 → 客户特价 19.90
        $bP004 = $service->resolve($anchors['custB'], $anchors['p004'], 25.00);
        $this->assertSame('客户特价', $bP004['source'], '客户B 的 P004 应命中客户特价');
        $this->assertSame(19.90, round((float) $bP004['price'], 2));

        // 同一商品换客户，单价必须变
        $aP004 = $service->resolve($anchors['custA'], $anchors['p004'], 25.00);
        $this->assertNotSame(
            round((float) $aP004['price'], 2),
            round((float) $bP004['price'], 2),
            'P004 在客户A/客户B 下应取到不同单价，否则订单弹窗看不出「单价随客户变化」'
        );

        // 「价格解析」页（E4 口径预览）同样应出规则价样本
        $this->actingAsAdmin();
        $res = $this->getJson('/admin/business/customer-special-price/resolve'
            .'?customer_ids[]='.$anchors['custA'].'&customer_ids[]='.$anchors['custB']
            .'&product_ids[]='.$anchors['p001'].'&product_ids[]='.$anchors['p004'])
            ->assertOk();
        $sources = collect($res->json('data.list'))->pluck('price_source')->unique()->all();
        $this->assertTrue(
            in_array('特价', $sources, true) || in_array('等级价', $sources, true),
            '预览应至少命中一档规则价，实际来源：'.implode(',', $sources)
        );
    }

    /**
     * 过期客户特价：下单取价与「价格解析」预览必须同口径。
     *
     * 修复前预览的 join 没带生效窗口，过期特价在页面上仍显示「特价」高亮，
     * 而 PriceService 会跳过它去取等级价——预览与成交打架。
     */
    public function test_expired_special_price_falls_back_consistently(): void
    {
        $this->actingAsAdmin();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price_small' => 12]);
        $level = CustomerLevel::where('code', 'LV2')->first();
        $this->assertNotNull($level, 'LV2 等级应由价格系统迁移自种');
        DB::table('customers')->where('id', $customer->id)->update(['level_id' => $level->id]);
        ProductLevelPrice::create(['product_id' => $product->id, 'level_id' => $level->id, 'price' => 9]);
        CustomerSpecialPrice::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => 8,
            'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->subMonth()->toDateString(),
            'status' => true,
        ]);

        $resolved = app(PriceService::class)->resolve($customer->id, $product->id, 12.0);
        $this->assertSame($level->name.'价', $resolved['source'], '过期特价不应参与成交取价');
        $this->assertSame(9.0, round((float) $resolved['price'], 2));

        $row = collect($this->getJson('/admin/business/customer-special-price/resolve'
            .'?customer_ids='.$customer->id.'&product_ids='.$product->id)
            ->assertOk()->json('data.list'))->first();
        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) $row['special_price'], '预览不应带出过期特价金额');
        $this->assertSame('等级价', $row['price_source'], '预览应与成交同口径落到等级价');
    }

    /**
     * 按 BusinessDataSeeder 的同编码自建演示主体（编码必须一致，播种器认的就是编码）。
     * 等级由播种器自己挂，这里留空，顺带验证「只补空值」那段逻辑。
     *
     * @return array{custA:int, custB:int, p001:int, p004:int}
     */
    private function makeDemoAnchors(): array
    {
        $custA = $this->makeCustomer(['code' => 'CUST001', 'name' => '客户A']);
        $custB = $this->makeCustomer(['code' => 'CUST002', 'name' => '客户B']);
        $p001 = $this->makeProduct(['code' => 'P001', 'name' => '矿泉水 550ml', 'price_small' => 2.00]);
        $p002 = $this->makeProduct(['code' => 'P002', 'name' => '可乐 330ml', 'price_small' => 3.50]);
        $p003 = $this->makeProduct(['code' => 'P003', 'name' => '巧克力饼干', 'price_small' => 8.00]);
        $p004 = $this->makeProduct(['code' => 'P004', 'name' => '洗衣液 2kg', 'price_small' => 25.00]);

        return ['custA' => $custA->id, 'custB' => $custB->id, 'p001' => $p001->id, 'p004' => $p004->id];
    }

    private function levelName(string $code): string
    {
        $name = DB::table('customer_levels')->where('code', $code)->value('name');
        $this->assertNotNull($name, "等级 {$code} 不存在");

        return (string) $name;
    }
}
