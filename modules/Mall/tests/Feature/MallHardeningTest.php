<?php

namespace Tests\Mall\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Mall\Models\MallOrder;
use Modules\Mall\Models\SmsRecord;
use Modules\Mall\Models\SmsTemplate;
use Modules\Mall\Services\SmsSendService;
use Modules\Order\Models\SalesOrder;
use Modules\Stock\Models\Stock;
use Tests\TestCase;

/**
 * F5 交付后加固 —— 边界测试补齐（F5-HARDENING 第 4 项）。
 *
 *  - 下单幂等：同一幂等键重复提交只生成一条商城订单与一条申报单；
 *  - 金额边界：负数数量拒绝、超大金额不溢出不崩溃；
 *  - 短信频控：同手机号分钟级超限被拒（明确报错，非静默丢弃）；
 *  - 短信重试：超过最大重试次数被拒、退避期内重发被拒（禁止无限重发）；
 *  - 短信可观测：落库含耗时/请求摘要/响应摘要。
 */
class MallHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '加固测试员']);
    }

    // ---------- 下单幂等 ----------

    public function test_duplicate_submit_with_same_idempotency_key_creates_one_order(): void
    {
        [$product, $warehouse, $customer] = $this->orderFixture();
        $key = 'client-abc-'.uniqid();

        $first = $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'qty_large' => 2]],
        ])->assertOk();

        // 重复提交（同客户端/同幂等键）
        $second = $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'qty_large' => 2]],
        ])->assertOk();

        // 同一订单返回，不产生第二条
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, MallOrder::count());
        $this->assertSame(1, SalesOrder::count(), '重复提交不得产生第二条申报单');

        // 库存只冻结一次：2 大件 × conv 2 × convMd 3 = 12 小单位
        $stock = Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
        $this->assertSame(12, (int) $stock->frozen_qty);
        $this->assertSame(88, (int) $stock->quantity - (int) $stock->frozen_qty, '可用库存只扣一次');
    }

    public function test_without_key_each_submit_creates_separate_order(): void
    {
        [$product, $warehouse, $customer] = $this->orderFixture();

        $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 1]],
        ])->assertOk();
        $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 1]],
        ])->assertOk();

        $this->assertSame(2, MallOrder::count(), '无幂等键按常规下单各自成单');
    }

    // ---------- 金额边界 ----------

    public function test_negative_quantity_is_rejected(): void
    {
        [$product, $warehouse, $customer] = $this->orderFixture();

        $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => -1]],
        ])->assertStatus(422);

        $this->assertSame(0, MallOrder::count(), '负数数量不得成单');
    }

    public function test_very_large_amount_does_not_overflow_or_crash(): void
    {
        $product = $this->makeProduct([
            'price_large' => 999999.99,
            'price_medium' => 99999.99,
            'price_small' => 9999.99,
            'unit_conversion' => 1,
            'unit_conversion_medium' => 1,
            'price_unit' => '件', 'barcode_medium_unit' => '盒', 'price_unit_small' => '个',
        ]);
        $warehouse = $this->makeWarehouse();
        $customer = $this->makeCustomer();
        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'quantity' => 1000000, 'frozen_qty' => 0,
            'cost_price' => 1, 'total_amount' => 0, 'updated_at' => now(),
        ]);

        // 999999.99 × 100000 ≈ 9.9999e10：超出常规单量级，需不溢出、不 500、不静默归零。
        $resp = $this->postJson('/admin/f5/mall/orders', [
            'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'qty_large' => 100000]],
        ])->assertOk();

        $this->assertEqualsWithDelta(100000 * 999999.99, (float) $resp->json('data.total_amount'), 0.01);
        $this->assertGreaterThan(1e9, (float) $resp->json('data.total_amount'), '超大金额不得被截断/归零');
        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(1, MallOrder::count());
    }

    // ---------- 短信频控 / 重试 / 可观测 ----------

    public function test_sms_rate_limit_rejects_over_minute_cap_with_clear_error(): void
    {
        $template = $this->smsTemplate();

        // 连发 MINUTE_CAP 次成功
        for ($i = 0; $i < SmsSendService::MINUTE_CAP; $i++) {
            $this->postJson('/admin/f5/sms/records/send', [
                'template_id' => $template->id, 'mobile' => '13811112222',
                'variables' => ['客户名' => '张三'],
            ])->assertOk();
        }

        // 第 6 次：超限 → 明确报错（非静默丢弃）
        $resp = $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id, 'mobile' => '13811112222',
            'variables' => ['客户名' => '张三'],
        ])->assertStatus(422);

        $this->assertStringContainsString('上限', $resp->json('message'));
        $this->assertSame(SmsSendService::MINUTE_CAP, SmsRecord::count(), '超限不发不落库');
    }

    public function test_sms_daily_cap_rejects_over_daily_limit(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00')); // 正午：30 分钟前必属当日
        // 时间回拨使 setUp 签发的 JWT 落在未来（nbf）→ 401；重新以当前时刻签发。
        $this->actingAsAdmin(['real_name' => '加固测试员']);
        $template = $this->smsTemplate();
        $mobile = '13822223333';

        // 落满 DAILY_CAP 条「今日但早于分钟窗口」的记录，使分钟上限不先触发，
        // 从而验证日级上限单独拦截（今日已达上限 → 明确报错）。
        $beforeWindow = now()->subMinutes(30); // 今日内、但 1 分钟窗口之外
        $rows = [];
        for ($i = 0; $i < SmsSendService::DAILY_CAP; $i++) {
            $rows[] = [
                'send_no' => 'SMS'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'template_id' => $template->id, 'template_code' => $template->code,
                'template_name' => $template->name, 'mobile' => $mobile,
                'content' => '您好张三', 'variables' => json_encode(['客户名' => '张三']),
                'status' => 'success', 'sent_at' => $beforeWindow, 'created_by' => 1,
                'created_at' => $beforeWindow, 'updated_at' => $beforeWindow,
            ];
        }
        DB::table('sms_records')->insert($rows);

        $resp = $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id, 'mobile' => $mobile,
            'variables' => ['客户名' => '张三'],
        ])->assertStatus(422);

        $this->assertStringContainsString('今日', $resp->json('message'));
        $this->assertSame(SmsSendService::DAILY_CAP, SmsRecord::count(), '超限不发不落库');
    }

    public function test_sms_resend_rejects_when_retry_cap_reached(): void
    {
        $template = $this->smsTemplate();
        // 已重试 MAX_RETRY 次的失败记录：再重发即达上限，拒绝（禁止无限重发）。
        $record = SmsRecord::create([
            'send_no' => 'SMSRETRYCAP001',
            'template_id' => $template->id, 'template_code' => $template->code,
            'template_name' => $template->name, 'mobile' => '13833334444',
            'content' => '您好张三', 'variables' => ['客户名' => '张三'],
            'status' => 'failed', 'error_message' => '模拟失败',
            'retry_count' => SmsSendService::MAX_RETRY, 'created_by' => 1,
        ]);

        $resp = $this->postJson("/admin/f5/sms/records/{$record->id}/resend")
            ->assertStatus(422);

        $this->assertStringContainsString('上限', $resp->json('message'));
        $this->assertSame(1, SmsRecord::count(), '超限不再落新记录（禁止无限重发）');
    }

    public function test_sms_failed_resend_rejects_within_backoff_window(): void
    {
        $template = $this->smsTemplate();
        // 直接落一条「失败」记录（retry 0，未达上限），created_at=now → 处于退避期。
        $failed = SmsRecord::create([
            'send_no' => 'SMSBACKOFF001',
            'template_id' => $template->id, 'template_code' => $template->code,
            'template_name' => $template->name, 'mobile' => '13844445555',
            'content' => '您好张三', 'variables' => ['客户名' => '张三'],
            'status' => 'failed', 'error_message' => '模拟失败',
            'retry_count' => 0, 'created_by' => 1,
        ]);

        // 立即重发失败记录：距上次发送 < 退避秒数 → 拒绝
        $resp = $this->postJson("/admin/f5/sms/records/{$failed->id}/resend")
            ->assertStatus(422);

        $this->assertStringContainsString('稍后', $resp->json('message'));
        $this->assertSame(1, SmsRecord::count(), '退避期内不落新记录');
    }

    public function test_sms_success_resend_not_gated_by_backoff(): void
    {
        $template = $this->smsTemplate();
        // 成功记录主动补发（如换号）不属于失败自动重试循环，不受退避约束。
        $first = $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id, 'mobile' => '13866667777',
            'variables' => ['客户名' => '张三'],
        ])->assertOk()->json('data');

        $resend = $this->postJson("/admin/f5/sms/records/{$first['id']}/resend")
            ->assertOk();

        $this->assertNotSame($first['send_no'], $resend->json('data.send_no'));
        $this->assertSame(2, SmsRecord::count());
    }

    public function test_sms_record_carries_observability_columns(): void
    {
        $template = $this->smsTemplate();
        $resp = $this->postJson('/admin/f5/sms/records/send', [
            'template_id' => $template->id, 'mobile' => '13855556666',
            'receiver_name' => '连凯超市', 'variables' => ['客户名' => '连凯超市'],
        ])->assertOk();

        $data = $resp->json('data');
        $this->assertSame('success', $data['status']);
        $this->assertSame(0, $data['retry_count']);
        $this->assertGreaterThanOrEqual(0, $data['elapsed_ms']);
        $this->assertNotNull($data['request_summary']);
        $this->assertNotNull($data['response_summary']);

        $summary = json_decode($data['request_summary'], true);
        $this->assertSame($template->code, $summary['template_code']);
        $this->assertSame('13855556666', $summary['mobile']);
    }

    // ---------- helpers ----------

    private function orderFixture(): array
    {
        $product = $this->makeProduct([
            'price_large' => 100, 'price_medium' => 20, 'price_small' => 5,
            'unit_conversion' => 2, 'unit_conversion_medium' => 3,
            'price_unit' => '件', 'barcode_medium_unit' => '盒', 'price_unit_small' => '个',
        ]);
        $warehouse = $this->makeWarehouse();
        $customer = $this->makeCustomer();
        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'quantity' => 100, 'frozen_qty' => 0,
            'cost_price' => 50, 'total_amount' => 5000, 'updated_at' => now(),
        ]);

        return [$product, $warehouse, $customer];
    }

    private function smsTemplate(): SmsTemplate
    {
        return SmsTemplate::create([
            'code' => 'HARDEN_'.strtoupper(bin2hex(random_bytes(3))),
            'name' => '加固模板',
            'content' => '您好{客户名}',
            'variables' => ['客户名' => '示例'],
            'status' => 1,
        ]);
    }
}
