<?php

namespace Tests\System\Feature;

use Modules\Auth\Models\User;
use Modules\Auth\Seeders\AuthSeeder;
use Modules\System\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 站内通知 Feature 测试 —— System 模块的第一个模块专属测试
 *
 * 这组接口此前完全没有路由声明：控制器 modules/System/Http/Controllers/Admin/
 * Notification.php 和 NotificationService 都在，前端 frontend/src/api/system.js
 * 的 notification 块也一直按这些路径调用，结果是全线 404 且无任何报错——
 * 路由表里 260 条一条 notification 都没有，同模块的 attachment/dictionary 却都在。
 * 该状态从代码导入当天（6b30f8b）就存在，main 上同样如此，不是模块化改造引入的。
 *
 * 路由存在性由 RouteBaselineTest 的快照 + endpoints_declared_by_frontend_api_layer
 * 覆盖，这里补的是行为：未登录被拦、登录后各接口真的能跑通、已读/删除流转正确、
 * 以及 user_ids 过滤带来的用户隔离。
 *
 * 只测前端 API 层声明过的 11 个接口。send / retryUnsent 目前没有调用方，
 * 也还没有路由，待有实际消费方再补。
 */
class NotificationFeatureTest extends TestCase
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

    /**
     * 用种子账号 admin 的真实 JWT 发请求。
     *
     * 不用 actingAs()：生产是常驻进程，身份只来自请求上的 token，
     * FlushRequestState 中间件会清掉 actingAs() 注入的 guard 身份。
     */
    private function loginAs(): static
    {
        return $this->withToken($this->loginToken($this->admin()));
    }

    /**
     * 造一条只发给当前管理员的通知
     */
    private function makeNotification(array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_ids' => [$this->admin()->id],
            'title' => '测试通知',
            'content' => '来自 NotificationFeatureTest',
            'type' => 'info',
            'category' => 'system',
            'is_read' => false,
            'sent_via_websocket' => false,
            'retry_count' => 0,
        ], $overrides));
    }

    // ---------------------------------------------------------------- 鉴权

    public function test_notification_endpoints_require_admin_auth(): void
    {
        foreach (
            [
                '/admin/system/notification',
                '/admin/system/notification/unread-count',
                '/admin/system/notification/1',
            ] as $uri
        ) {
            $this->getJson($uri)
                ->assertUnauthorized("未登录访问 {$uri} 应当被拦下")
                ->assertJsonPath('code', 401);
        }
    }

    // ---------------------------------------------------------------- 只读接口

    public function test_list_unread_count_and_statistics_are_reachable(): void
    {
        $this->makeNotification();

        $this->loginAs()
            ->getJson('/admin/system/notification')
            ->assertOk()
            ->assertJsonPath('code', 200);

        $this->loginAs()
            ->getJson('/admin/system/notification/unread')
            ->assertOk()
            ->assertJsonPath('code', 200);

        $this->loginAs()
            ->getJson('/admin/system/notification/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->loginAs()
            ->getJson('/admin/system/notification/statistics')
            ->assertOk()
            ->assertJsonPath('code', 200);
    }

    // ---------------------------------------------------------------- 单条生命周期

    public function test_show_then_mark_read_then_delete_lifecycle(): void
    {
        $notification = $this->makeNotification();

        $this->loginAs()
            ->getJson("/admin/system/notification/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.title', '测试通知');

        $this->loginAs()
            ->postJson("/admin/system/notification/{$notification->id}/read")
            ->assertOk();

        $this->assertTrue(
            $notification->fresh()->is_read,
            'POST /{id}/read 之后 is_read 应为 true'
        );

        $this->loginAs()
            ->deleteJson("/admin/system/notification/{$notification->id}")
            ->assertOk();

        // 软删除的验证要分两头：
        // fresh() 走 newQueryWithoutScopes()，会绕过 SoftDeletes 把已删行捞回来，
        // 所以它只能用来确认「deleted_at 确实写上了」；
        // 要确认「常规查询查不到」必须走 find()，由作用域拦。
        $this->assertTrue(
            $notification->fresh()->trashed(),
            '删除后应当写入 deleted_at'
        );
        $this->assertNull(
            Notification::find($notification->id),
            '常规查询应当查不到已删除的通知（SoftDeletes 作用域生效）'
        );
    }

    // ---------------------------------------------------------------- 批量操作

    public function test_batch_read_and_batch_delete_report_actual_affect_counts(): void
    {
        $a = $this->makeNotification(['title' => '批量1']);
        $b = $this->makeNotification(['title' => '批量2']);
        $other = $this->makeNotification(['user_ids' => [9999], 'title' => '别人的通知']);

        $this->loginAs()
            ->postJson('/admin/system/notification/batch-read', ['ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->loginAs()
            ->postJson('/admin/system/notification/batch-delete', ['ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertNull(Notification::find($a->id));
        $this->assertNull(Notification::find($b->id));
        $this->assertNotNull(
            Notification::find($other->id),
            'batch-delete 必须按 user_ids 过滤，不能删掉不属于当前用户的通知'
        );
    }

    /**
     * clear-read 按 is_read 删，不是按 id 删——未读行和他人通知都不能被动。
     */
    public function test_clear_read_only_removes_read_notifications_of_own_user(): void
    {
        $read = $this->makeNotification(['title' => '已读']);
        $unread = $this->makeNotification(['title' => '未读']);
        $someoneElses = $this->makeNotification(['user_ids' => [9999], 'title' => '别人的']);

        $this->loginAs()
            ->postJson("/admin/system/notification/{$read->id}/read")
            ->assertOk();

        $this->loginAs()
            ->postJson('/admin/system/notification/clear-read')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->assertNull(Notification::find($read->id), '已读行应当被清掉');
        $this->assertNotNull(Notification::find($unread->id), '未读行不应被 clear-read 误删');
        $this->assertNotNull(
            Notification::find($someoneElses->id),
            '他人通知不应被当前用户的 clear-read 清掉'
        );
    }

    public function test_read_all_only_marks_unread_notifications_of_own_user(): void
    {
        $a = $this->makeNotification(['title' => '1']);
        $b = $this->makeNotification(['title' => '2']);
        $this->makeNotification(['title' => '早就读过', 'is_read' => true]);
        $someoneElses = $this->makeNotification(['user_ids' => [9999], 'title' => '别人的']);

        $this->loginAs()
            ->postJson('/admin/system/notification/read-all')
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertTrue($a->fresh()->is_read);
        $this->assertTrue($b->fresh()->is_read);
        // count 是 2 而不是 4：已读的不重复计数，他人的不该被当前用户标记
        $this->assertFalse(
            Notification::where('id', $someoneElses->id)->first()->is_read,
            'read-all 不应把他人通知标记为已读'
        );
    }
}
