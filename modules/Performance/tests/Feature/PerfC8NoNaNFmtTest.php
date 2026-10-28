<?php

namespace Tests\Performance\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C8 NaN 禁绝验收。
 *
 * 绩效域的比率/占比/账龄全部来自除法，分母为 0 或字段缺失时若未兜底，
 * PHP json_encode 会输出非法 JSON 或字符串 "NaN"，前端拿到就是白屏 / "NaN%"。
 * 本测试对 analysis / dashboard / ar-aging / profit / delivery-uncollected /
 * commission-report 六个模块的接口在「空数据」与「零分母数据」两种场景下断言：
 * 响应 200、是合法 JSON、且响应文本里既不出现 NaN 也不出现 Infinity。
 *
 * 注：dashboard/sales-overview、analysis/growth-return、profit/trend 三个端点用
 * MySQL 的 DATE_FORMAT 做月度分桶，sqlite 下会直接报「no such function」——那是
 * 方言差异不是 NaN 问题，故这三个只在真实 MySQL 连接上断言。
 */
class PerfC8NoNaNFmtTest extends TestCase
{
    use RefreshDatabase;

    /** 依赖 DATE_FORMAT（MySQL 方言）的端点 */
    private const MYSQL_ONLY = [
        '/admin/perf/dashboard/sales-overview',
        '/admin/perf/analysis/growth-return',
        '/admin/perf/profit/trend',
    ];

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /** 响应必须是合法 JSON 且文本不含 NaN / Infinity */
    private function assertNoNaN(string $url, mixed $res): void
    {
        $res->assertOk();
        $body = $res->getContent();
        $this->assertIsArray(json_decode($body, true), "{$url} 返回非法 JSON（NaN/INF 会被 json_encode 破坏）");
        $this->assertStringNotContainsString('NaN', $body, "{$url} 响应含 NaN");
        $this->assertStringNotContainsString('Infinity', $body, "{$url} 响应含 Infinity");
    }

    /** 逐个端点断言（$extra 附加查询参数；MySQL 专属端点按驱动跳过） */
    private function hitAll(array $extra = []): void
    {
        $q = $extra ? '?'.http_build_query($extra) : '';
        $urls = [
            '/admin/perf/analysis/products',
            '/admin/perf/analysis/customers',
            '/admin/perf/analysis/employees',
            '/admin/perf/analysis/growth-return',
            '/admin/perf/ar/aging',
            '/admin/perf/profit/products',
            '/admin/perf/profit/customers',
            '/admin/perf/profit/employees',
            '/admin/perf/profit/brands',
            '/admin/perf/profit/orders',
            '/admin/perf/profit/trend',
            '/admin/perf/delivery/uncollected',
            '/admin/perf/commission-orders',
            '/admin/perf/reports/return-summary',
            '/admin/perf/dashboard/sales-overview',
        ];
        foreach ($urls as $path) {
            if (! $this->isMysql() && in_array($path, self::MYSQL_ONLY, true)) {
                continue;
            }
            $this->assertNoNaN($path, $this->getJson($path.$q));
        }
    }

    public function test_no_nan_with_empty_dataset(): void
    {
        $this->actingAsAdmin();
        // 全零分母：所有比率类除法都只能走兜底分支
        $this->hitAll(['start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
    }

    /** 造一笔零成本、零退货、零收款的已审核订单 + 发货单 */
    private function seedOrder(int $salesmanId, int $customerId, int $productId, int $warehouseId): void
    {
        $orderId = DB::table('sales_orders')->insertGetId([
            'order_no' => 'SO-C8-'.substr(str_shuffle('0123456789'), 0, 6),
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-06-10',
            'total_qty' => 4,
            'total_amount' => 400,
            'paid_amount' => 0,
            'status' => 'approved',
            'salesman_id' => $salesmanId,
        ]);
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 4,
            'price' => 100,
            'price_small' => 100,
            'amount' => 400,
        ]);
        DB::table('deliveries')->insert([
            'delivery_no' => 'DLV-C8-'.substr(str_shuffle('0123456789'), 0, 6),
            'order_id' => $orderId,
            'warehouse_id' => $warehouseId,
            'total_amount' => 400,
            'delivery_date' => '2026-06-11',
            'status' => 1,
        ]);
    }

    public function test_no_nan_with_zero_denominator_rows(): void
    {
        $salesman = $this->actingAsAdmin(['real_name' => '业务员甲']);
        $customer = $this->makeCustomer(['name' => '客户甲']);
        $product = $this->makeProduct(['name' => '零成本商品']);
        $this->seedOrder($salesman->id, $customer->id, $product->id, $this->makeWarehouse()->id);
        // 目标 0 → achieve_rate 分母 0；无收款 → 回款类比率分母 0；无品牌 → 品牌维度空桶
        DB::table('perf_sales_targets')->insert([
            'target_type' => 'employee',
            'target_id' => $salesman->id,
            'period' => '2026-06',
            'target_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->hitAll();

        foreach ($this->getJson('/admin/perf/ar/aging?dimension=customer')->assertOk()->json('data.list') as $row) {
            $this->assertTrue(
                $row['credit_usage_rate'] === null || is_numeric($row['credit_usage_rate']),
                'credit_usage_rate 必须是数值或 null，不能是 "NaN"'
            );
        }

        $uncollected = $this->getJson('/admin/perf/delivery/uncollected')->assertOk()->json('data.list');
        $this->assertNotEmpty($uncollected, '发货未收款应有数据，否则本用例覆盖不到账龄计算');
        foreach ($uncollected as $row) {
            $this->assertIsNumeric($row['age_days'], 'age_days 非数值会让前端渲染 NaN');
        }
    }
}
