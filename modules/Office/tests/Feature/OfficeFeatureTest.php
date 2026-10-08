<?php

namespace Tests\Office\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * 办公模块（内部邮件 / 公司公告）接口冒烟。
 *
 * 邮件这边最容易被写错的是「已读/删除归谁」：收件人的状态记在 mail_recipients，
 * 发件人的删除记在 mails.deleted_at，回收站要把两条路 union 起来才完整。
 * 只用单元测试碰不到这些，页面上点一下才会发现「我删了但对方还能看到」。
 */
class OfficeFeatureTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOfficeData();
    }

    public function test_sent_mail_appears_in_recipient_inbox_and_can_be_read(): void
    {
        $sender = $this->ids['sender'];
        $recipient = $this->ids['recipient'];

        $res = $this->postJson('admin/business/mail', [
            'subject' => '本周销售复盘',
            'content' => '请各区域经理于周五前提交。',
            'to_ids' => [$recipient],
            'save_type' => 'send',
        ]);
        $res->assertOk();
        $mailId = $res->json('data.id');

        // 收件人视角：收件箱里应该看到这封，且是未读
        $this->loginAs($recipient);
        $inbox = $this->getJson('admin/business/mail?box=inbox');
        $inbox->assertOk();
        $this->assertSame('本周销售复盘', $inbox->json('data.list.0.subject'));
        $this->assertFalse((bool) $inbox->json('data.list.0.is_read'));

        $count = $this->getJson('admin/business/mail/unread-count');
        $this->assertSame(1, $count->json('data.count'));

        // 标记已读后未读数归零
        $this->postJson("admin/business/mail/{$mailId}/read")->assertOk();
        $this->assertSame(0, $this->getJson('admin/business/mail/unread-count')->json('data.count'));

        // 发件人视角：出现在发件箱
        $this->loginAs($sender);
        $sent = $this->getJson('admin/business/mail?box=sent');
        $sent->assertOk();
        $this->assertSame('本周销售复盘', $sent->json('data.list.0.subject'));
    }

    public function test_deleted_mail_moves_to_trash_and_disappears_from_inbox(): void
    {
        $recipient = $this->ids['recipient'];

        $created = $this->postJson('admin/business/mail', [
            'subject' => '待删除邮件',
            'content' => '内容',
            'to_ids' => [$recipient],
            'save_type' => 'send',
        ]);
        $mailId = $created->json('data.id');

        $this->loginAs($recipient);
        $this->deleteJson("admin/business/mail/{$mailId}")->assertOk();

        $this->assertSame(0, $this->getJson('admin/business/mail?box=inbox')->json('data.total'));
        $this->assertSame(1, $this->getJson('admin/business/mail?box=trash')->json('data.total'));
    }

    public function test_draft_requires_no_recipient_but_send_does(): void
    {
        // 草稿可以没有收件人
        $this->postJson('admin/business/mail', [
            'subject' => '没写完的草稿',
            'content' => '待补充',
            'save_type' => 'draft',
        ])->assertOk();

        // 发送必须有收件人，否则 422
        $this->postJson('admin/business/mail', [
            'subject' => '空收件人',
            'save_type' => 'send',
        ])->assertStatus(422);
    }

    public function test_notice_list_orders_top_first_and_returns_summary(): void
    {
        $normal = DB::table('notices')->insertGetId([
            'title' => '普通通知', 'type' => 'notice', 'content' => '正文内容',
            'is_top' => 0, 'scope' => 'all', 'scope_ids' => '[]', 'attachments' => '[]',
            'author_id' => $this->ids['sender'], 'author_name' => '张三', 'view_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notices')->insertGetId([
            'title' => '置顶制度', 'type' => 'rule', 'content' => '制度正文',
            'is_top' => 1, 'scope' => 'all', 'scope_ids' => '[]', 'attachments' => '[]',
            'author_id' => $this->ids['sender'], 'author_name' => '张三', 'view_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('admin/business/notice');
        $res->assertOk();
        // 置顶永远在最前
        $this->assertSame('置顶制度', $res->json('data.list.0.title'));
        // 列表不下发整篇正文，只给摘要
        $this->assertArrayNotHasKey('content', $res->json('data.list.0'));
        $this->assertNotEmpty($res->json('data.list.0.summary'));

        // 按类型筛选
        $this->assertSame(1, $this->getJson('admin/business/notice?type=rule')->json('data.total'));
        $this->assertGreaterThan(0, $normal);
    }

    public function test_notice_can_only_be_edited_by_its_author(): void
    {
        $author = $this->ids['sender'];
        DB::table('notices')->insert([
            'title' => '作者自己的公告', 'type' => 'notice', 'content' => '正文',
            'is_top' => 0, 'scope' => 'all', 'scope_ids' => '[]', 'attachments' => '[]',
            'author_id' => $author, 'author_name' => '张三', 'view_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('notices')->where('title', '作者自己的公告')->value('id');

        $this->loginAs($author);
        $this->putJson("admin/business/notice/{$id}", ['title' => '改过的标题'])->assertOk();
        $this->assertSame('改过的标题', DB::table('notices')->where('id', $id)->value('title'));

        // 非作者（非超管）改别人的公告应被拒
        $other = $this->ids['recipient'];
        $this->loginAs($other);
        $this->putJson("admin/business/notice/{$id}", ['title' => '篡改'])->assertStatus(403);
    }

    public function test_office_endpoints_require_auth(): void
    {
        $this->withToken('');
        $this->getJson('admin/business/mail')->assertStatus(401);
        $this->getJson('admin/business/notice')->assertStatus(401);
    }

    /**
     * 切换当前请求的身份（真实 JWT，与 actingAsAdmin 同一条链路）。
     *
     * 必须叫 loginAs 而不是 actingAs：后者是 TestCase 的 public 方法，
     * 用 private 重写会直接触发 PHP 的访问权限致命错误。
     */
    private function loginAs(int $userId): void
    {
        $this->withToken(auth('admin')->login(User::find($userId)));
    }

    private function seedOfficeData(): void
    {
        $now = now();
        foreach (['sender' => '张三', 'recipient' => '李四'] as $key => $name) {
            $this->ids[$key] = DB::table('auth_user')->insertGetId([
                'username' => $key.'_'.bin2hex(random_bytes(4)),
                'password' => bcrypt('secret123'),
                'real_name' => $name,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 以 sender 身份登录（seed 之后默认身份）
        $this->loginAs($this->ids['sender']);
    }
}
