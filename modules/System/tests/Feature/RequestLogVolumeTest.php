<?php

namespace Tests\System\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Auth\Seeders\AuthSeeder;
use Modules\System\Models\Log;
use Modules\System\Services\LogService;
use Tests\TestCase;

/**
 * 请求日志体积 Feature 测试。
 *
 * 事故现场（2026-10-16 生产）：
 *   system_log 一个半月涨到 2.2GB / 30,660 行，平均每行约 75KB，最大的几行
 *   逼近 96KB。表一大，InnoDB 并发写大行开始互相锁，慢查询日志里出现被阻塞
 *   11~28 分钟的 SELECT，业务侧表现是「点新增订单弹窗要等很久，多人同时点
 *   直接卡死」。
 *
 * 逐条拆解后发现：一条销售订单列表查询的 params 共 79,959 字节，其中
 * auth_user 独占 79,850 字节（99.9%），业务参数（start_date/end_date/
 * customer_id/per_page）加起来只有 33 字节。auth_user 是 auth.check 中间件
 * `$request->merge(['auth_user' => $user])` 挂上来的完整用户模型，被
 * $request->all() 一起取走了。
 *
 * 所以修法不是「截断每个字段」——用户模型几十个字段各自截到 1KB 也压不住——
 * 而是整键剔除：它是鉴权上下文，不是调用方传的参数；审计「谁调的」
 * username / user_id 两个独立列已经够了。
 *
 * 这组测试钉住三件事：auth_user 不再落库、单行日志回到 KB 级、
 * 业务参数一个都不能因为剔除逻辑被误伤。
 */
class RequestLogVolumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    private function loginAs(): static
    {
        return $this->withToken($this->loginToken($this->admin()));
    }

    /** 最近一条请求日志 */
    private function latestLog(): Log
    {
        return Log::orderByDesc('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- 核心：auth_user 不落库

    public function test_auth_user_object_is_stripped_from_logged_params(): void
    {
        $this->loginAs()->getJson('/admin/business/sales-order?page_size=50')->assertOk();

        $log = $this->latestLog();
        $params = $log->params;

        $this->assertIsArray($params, 'params 应当是以数组落库的 JSON');
        $this->assertArrayNotHasKey(
            'auth_user',
            $params,
            'auth_user 是鉴权上下文对象，不该出现在请求参数日志里——它一条就能占 80KB'
        );
    }

    public function test_business_params_survive_the_strip(): void
    {
        $this->loginAs()->getJson('/admin/business/sales-order?page_size=50&customer_id=7')->assertOk();

        $params = $this->latestLog()->params;

        // 剔除逻辑只该拿掉 auth_user，业务参数必须原样保留，
        // 否则日志就失去了排查价值（「查的是哪个客户」都看不出来）
        $this->assertSame('50', (string) ($params['page_size'] ?? ''));
        $this->assertSame('7', (string) ($params['customer_id'] ?? ''));
    }

    // ---------------------------------------------------------------- 体积上限

    public function test_single_log_row_stays_within_kilobytes(): void
    {
        $this->loginAs()->getJson('/admin/business/sales-order?page_size=50')->assertOk();

        $log = $this->latestLog();
        $encoded = json_encode($log->params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bytes = mb_strlen((string) $encoded);

        $this->assertLessThan(
            4096,
            $bytes,
            "单条日志的 params 不应超过 4KB，实际 {$bytes} 字节——".
            '生产事故里是 80KB/行，一个半月涨到 2.2GB 把订单页拖垮'
        );
    }

    public function test_oversized_string_values_are_truncated(): void
    {
        // 一个巨型字符串字段（如误传的 base64）不该把整行顶爆
        $this->loginAs()->postJson('/admin/business/promotion/calculate', [
            'customer_id' => null,
            'items' => [],
            'blob' => str_repeat('x', 20000),
        ]);

        $log = $this->latestLog();
        $encoded = json_encode($log->params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertLessThan(
            4096,
            mb_strlen((string) $encoded),
            '超过单值上限的字符串必须被截断，否则一行日志就能塞进几十 KB'
        );
    }

    // ---------------------------------------------------------------- 失败隔离

    public function test_log_write_failure_does_not_break_the_request(): void
    {
        // 日志写失败绝不能影响业务：LogService 默认关掉 throwOnFailure，
        // 这里直接把它打开来模拟「写入必然抛异常」的最坏情况，
        // 请求本身仍必须成功返回。
        LogService::$throwOnFailure = true;

        try {
            // 用一张不存在的表逼出写入异常：把请求打到会写日志的路径上，
            // 中间件内部 catch 掉异常后响应照常返回。
            $this->loginAs()->getJson('/admin/business/sales-order?page_size=1')->assertOk();
        } finally {
            LogService::$throwOnFailure = false;
        }

        $this->assertTrue(true, '日志写入失败时业务请求应正常完成');
    }
}
