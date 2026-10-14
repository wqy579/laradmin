<?php

namespace Tests\Office\Feature;

use App\Support\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * 消息通知中心（站内消息 + 公告草稿/发布）接口测试。
 *
 * 最关键的是「已读态归谁」：messages 表按收件人一行存，A 标已读不能动 B 的
 * 未读角标。这是铃铛/消息中心的基本语义，和同模块 mail_recipients 同一设计。
 * 只测列表页会漏掉这类跨用户串扰，必须按收件人分身份来断言。
 */
class MessageFeatureTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMessageData();
    }

    public function test_unread_count_is_per_user_not_shared(): void
    {
        $alice = $this->ids['alice'];
        $bob = $this->ids['bob'];

        // 给 alice 两条、bob 一条
        Inbox::sendToUsers([$alice], '待办一', '内容一', Inbox::TYPE_APPROVAL, 'order', ['path' => '/business/sales-order']);
        Inbox::sendToUsers([$alice], '待办二', '内容二', Inbox::TYPE_BUSINESS, null, []);
        Inbox::sendToUsers([$bob], '只给 bob', '内容三', Inbox::TYPE_STOCK_WARNING, 'inventory', []);

        $this->loginAs($alice);
        $this->assertSame(2, $this->getJson('admin/business/messages/unread-count')->json('data.count'));

        $this->loginAs($bob);
        $this->assertSame(1, $this->getJson('admin/business/messages/unread-count')->json('data.count'));
    }

    public function test_list_filters_by_status_and_marks_read(): void
    {
        $alice = $this->ids['alice'];

        Inbox::sendToUsers([$alice], '未读A', '内容', Inbox::TYPE_APPROVAL, 'order', ['path' => '/business/sales-order']);
        Inbox::sendToUsers([$alice], '未读B', '内容', Inbox::TYPE_BUSINESS, null, []);

        $this->loginAs($alice);

        // 默认 all，两条都在
        $all = $this->getJson('admin/business/messages');
        $all->assertOk();
        $this->assertSame(2, $all->json('data.total'));

        // unread 只有未读
        $this->assertSame(2, $this->getJson('admin/business/messages?status=unread')->json('data.total'));
        $this->assertSame(0, $this->getJson('admin/business/messages?status=read')->json('data.total'));

        $firstId = $all->json('data.list.0.id');

        // 标记单条已读：回传最新未读数，且 read 计数 +1
        $read = $this->postJson("admin/business/messages/{$firstId}/read");
        $read->assertOk();
        $this->assertSame(1, $read->json('data.unread_count'));
        $this->assertSame(1, $this->getJson('admin/business/messages?status=read')->json('data.total'));
        $this->assertSame(1, $this->getJson('admin/business/messages?status=unread')->json('data.total'));

        // 全部已读
        $this->postJson('admin/business/messages/read-all')->assertOk();
        $this->assertSame(0, $this->getJson('admin/business/messages/unread-count')->json('data.count'));
        $this->assertSame(2, $this->getJson('admin/business/messages?status=read')->json('data.total'));
    }

    public function test_delete_is_scoped_to_owner(): void
    {
        $alice = $this->ids['alice'];
        $bob = $this->ids['bob'];

        Inbox::sendToUsers([$alice], 'alice 的消息', 'x', Inbox::TYPE_BUSINESS, null, []);
        Inbox::sendToUsers([$bob], 'bob 的消息', 'x', Inbox::TYPE_BUSINESS, null, []);
        $aliceId = DB::table('messages')->where('user_id', $alice)->value('id');
        $bobId = DB::table('messages')->where('user_id', $bob)->value('id');

        // bob 不能删 alice 的消息（按 id 越权）
        $this->loginAs($bob);
        $this->deleteJson("admin/business/messages/{$aliceId}")->assertStatus(404);
        $this->deleteJson("admin/business/messages/{$bobId}")->assertOk();

        // alice 的消息仍在
        $this->loginAs($alice);
        $this->assertSame(1, $this->getJson('admin/business/messages')->json('data.total'));
        $this->assertSame(1, $this->getJson('admin/business/messages/unread-count')->json('data.count'));

        // alice 自己能删，且能标已读自己的
        $this->postJson("admin/business/messages/{$aliceId}/read")->assertOk();
        $this->assertSame(0, $this->getJson('admin/business/messages/unread-count')->json('data.count'));
        $this->deleteJson("admin/business/messages/{$aliceId}")->assertOk();
        $this->assertSame(0, $this->getJson('admin/business/messages')->json('data.total'));
    }

    public function test_send_to_users_drops_invalid_recipients(): void
    {
        $alice = $this->ids['alice'];

        // 含一个不存在的用户 ID + alice：只给 alice 写入一行
        $written = Inbox::sendToUsers([$alice, 999999], '有效收件人', '内容', Inbox::TYPE_APPROVAL, 'order', ['path' => '/business/sales-order']);
        $this->assertSame(1, $written);
        $this->assertSame(1, $this->getJson('admin/business/messages/unread-count')->json('data.count'));

        // 全是不存在的人：不写入任何行
        $this->assertSame(0, Inbox::sendToUsers([999998, 999999], '无效', '内容'));
        $this->assertSame(1, $this->getJson('admin/business/messages/unread-count')->json('data.count'));
    }

    public function test_notice_publish_creates_system_message(): void
    {
        $author = $this->ids['alice'];
        $bob = $this->ids['bob'];

        $this->loginAs($author);
        $create = $this->postJson('admin/business/notice', [
            'title' => '全员大会通知',
            'type' => 'notice',
            'content' => '本周五下午三点全员大会',
            'scope' => 'all',
        ]);
        $create->assertOk();
        $noticeId = $create->json('data.id');

        // 默认存草稿：普通用户看不到（scope=all 但仍需 status=published）
        $this->loginAs($bob);
        $this->assertSame(0, $this->getJson('admin/business/notice')->json('data.total'));

        // 作者发布 → 全员收到一条系统公告消息
        $this->loginAs($author);
        $this->postJson("admin/business/notice/{$noticeId}/publish")->assertOk();

        $this->loginAs($bob);
        $this->assertSame(1, $this->getJson('admin/business/notice')->json('data.total'));
        $this->assertSame(1, $this->getJson('admin/business/messages/unread-count')->json('data.count'));
        $message = $this->getJson('admin/business/messages')->json('data.list.0');
        $this->assertSame('system', $message['type']);
        $this->assertStringContainsString('全员大会通知', $message['title']);
        $this->assertSame('/business/notice', $message['payload']['path']);
    }

    public function test_popup_pending_returns_null_without_popup_notice(): void
    {
        $this->loginAs($this->ids['bob']);
        $res = $this->getJson('admin/business/notice/popup-pending');
        $res->assertOk();
        $this->assertNull($res->json('data.notice'));
    }

    public function test_popup_pending_returns_notice_after_publishing_popup_announcement(): void
    {
        $author = $this->ids['alice'];
        $bob = $this->ids['bob'];

        $this->loginAs($author);
        $create = $this->postJson('admin/business/notice', [
            'title' => '弹窗公告：系统升级通知',
            'type' => 'notice',
            'content' => '系统将于本周六凌晨升级，届时暂停服务两小时。',
            'scope' => 'all',
            'is_popup' => true,
            'status' => 'published',
        ]);
        $create->assertOk();

        // 弹窗公告对 bob 可见且消息未读 → popup-pending 回传公告本体 + 消息 id
        $this->loginAs($bob);
        $res = $this->getJson('admin/business/notice/popup-pending');
        $res->assertOk();
        $this->assertNotNull($res->json('data.notice'));
        $this->assertSame('弹窗公告：系统升级通知', $res->json('data.notice.title'));
        $this->assertTrue((bool) $res->json('data.notice.is_popup'));
        $this->assertSame($create->json('data.id'), $res->json('data.notice.id'));
        $this->assertIsInt($res->json('data.message_id'));
    }

    public function test_popup_pending_excludes_non_popup_notices(): void
    {
        $author = $this->ids['alice'];
        $bob = $this->ids['bob'];

        // 普通公告（未勾登录弹窗）即使已发布、消息未读，也不该弹窗
        $this->loginAs($author);
        $create = $this->postJson('admin/business/notice', [
            'title' => '普通公告不弹窗',
            'type' => 'notice',
            'content' => '正文',
            'scope' => 'all',
            'is_popup' => false,
            'status' => 'published',
        ]);
        $create->assertOk();

        $this->loginAs($bob);
        // 消息照常下发（铃铛看得到），但弹窗不弹
        $this->assertSame(1, $this->getJson('admin/business/messages/unread-count')->json('data.count'));
        $res = $this->getJson('admin/business/notice/popup-pending');
        $res->assertOk();
        $this->assertNull($res->json('data.notice'));
    }

    public function test_popup_pending_honors_visibility_scope(): void
    {
        $author = $this->ids['alice'];
        $bob = $this->ids['bob'];

        // 只发给 alice 本人的弹窗公告：bob 收到了消息，但公告对他不可见 → 不弹
        $this->loginAs($author);
        $create = $this->postJson('admin/business/notice', [
            'title' => '只给本人看的弹窗公告',
            'type' => 'notice',
            'content' => '正文',
            'scope' => 'user',
            'scope_ids' => [$author],
            'is_popup' => true,
            'status' => 'published',
        ]);
        $create->assertOk();

        $this->loginAs($bob);
        $this->assertNull($this->getJson('admin/business/notice/popup-pending')->json('data.notice'));

        // alice 本人在范围内 → 正常弹窗
        $this->loginAs($author);
        $res = $this->getJson('admin/business/notice/popup-pending');
        $res->assertOk();
        $this->assertSame($create->json('data.id'), $res->json('data.notice.id'));
    }

    public function test_popup_pending_returns_null_after_message_read(): void
    {
        $author = $this->ids['alice'];
        $bob = $this->ids['bob'];

        $this->loginAs($author);
        $create = $this->postJson('admin/business/notice', [
            'title' => '弹窗公告',
            'type' => 'notice',
            'content' => '正文',
            'scope' => 'all',
            'is_popup' => true,
            'status' => 'published',
        ]);
        $create->assertOk();

        $this->loginAs($bob);
        $messageId = $this->getJson('admin/business/notice/popup-pending')->json('data.message_id');

        // 标记已读后弹窗不再弹——未读态是弹窗的唯一开关
        $this->postJson("admin/business/messages/{$messageId}/read")->assertOk();
        $this->assertNull($this->getJson('admin/business/notice/popup-pending')->json('data.notice'));
    }

    public function test_notice_list_and_detail_return_is_popup(): void
    {
        $author = $this->ids['alice'];

        $this->loginAs($author);
        $create = $this->postJson('admin/business/notice', [
            'title' => '弹窗公告',
            'type' => 'notice',
            'content' => '正文',
            'scope' => 'all',
            'is_popup' => true,
            'status' => 'published',
        ]);
        $create->assertOk();
        $id = $create->json('data.id');

        $this->assertTrue((bool) $this->getJson('admin/business/notice')->json('data.list.0.is_popup'));
        $this->assertTrue((bool) $this->getJson("admin/business/notice/{$id}")->json('data.is_popup'));

        // 关掉弹窗标志后不再回传 true
        $this->putJson("admin/business/notice/{$id}", [
            'title' => '弹窗公告',
            'type' => 'notice',
            'content' => '正文',
            'is_top' => false,
            'is_popup' => false,
            'scope' => 'all',
            'scope_ids' => [],
            'attachments' => [],
            'status' => 'published',
        ])->assertOk();
        $this->assertFalse((bool) $this->getJson("admin/business/notice/{$id}")->json('data.is_popup'));
    }

    public function test_message_endpoints_require_auth(): void
    {
        $this->withToken('');
        $this->getJson('admin/business/messages')->assertStatus(401);
        $this->getJson('admin/business/messages/unread-count')->assertStatus(401);
        $this->getJson('admin/business/notice/popup-pending')->assertStatus(401);
    }

    /** 切身份：走真实 JWT 链路（actingAs 会绕过中间件，验不到鉴权） */
    private function loginAs(int $userId): void
    {
        $this->withToken(auth('admin')->login(User::find($userId)));
    }

    private function seedMessageData(): void
    {
        $now = now();
        foreach (['alice' => '张三', 'bob' => '李四'] as $key => $name) {
            $this->ids[$key] = DB::table('auth_user')->insertGetId([
                'username' => $key.'_'.bin2hex(random_bytes(4)),
                'password' => bcrypt('secret123'),
                'real_name' => $name,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->loginAs($this->ids['alice']);
    }
}
