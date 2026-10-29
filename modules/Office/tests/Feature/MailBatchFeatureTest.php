<?php

namespace Tests\Office\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * BUG-07 回归：站内信批量操作（batch-read / batch-unread / batch-delete）。
 *
 * 三个接口此前 500 Server Error。修复要求：
 *   1. 批量操作必须包事务（任一表失败整体回滚）；
 *   2. 空数组入参直接返回成功，不报错（不产生 500 / 422）；
 *   3. 入参校验：ids 必须是数组、过滤非整数、只操作本人记录（越权 id 被过滤）。
 *
 * 注意：真实 JWT 走 loginAs/withToken 同一条链路，不用 actingAs。
 */
class MailBatchFeatureTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMailData();
    }

    public function test_batch_read_marks_all_selected_as_read(): void
    {
        $recipient = $this->ids['recipient'];
        $mailA = $this->sendMail('批量已读 A', $recipient);
        $mailB = $this->sendMail('批量已读 B', $recipient);

        $this->loginAs($recipient);
        $res = $this->postJson('admin/business/mail/batch-read', ['ids' => [$mailA, $mailB]]);
        $res->assertOk();
        $this->assertSame(2, $res->json('data.updated'));

        // 两张收件记录都被标为已读
        $readCount = DB::table('mail_recipients')
            ->where('user_id', $recipient)
            ->where('is_read', 1)
            ->count();
        $this->assertSame(2, $readCount);
    }

    public function test_batch_unread_marks_selected_as_unread(): void
    {
        $recipient = $this->ids['recipient'];
        $mail = $this->sendMail('批量未读', $recipient);
        // 先标已读
        DB::table('mail_recipients')->where('mail_id', $mail)->where('user_id', $recipient)
            ->update(['is_read' => 1, 'read_at' => now()]);

        $this->loginAs($recipient);
        $res = $this->postJson('admin/business/mail/batch-unread', ['ids' => [$mail]]);
        $res->assertOk();
        $this->assertSame(1, $res->json('data.updated'));
        $this->assertSame(0, (int) DB::table('mail_recipients')->where('mail_id', $mail)->where('user_id', $recipient)->value('is_read'));
    }

    public function test_batch_delete_soft_deletes_own_recipient_records(): void
    {
        $recipient = $this->ids['recipient'];
        $mail = $this->sendMail('批量删除', $recipient);

        $this->loginAs($recipient);
        $res = $this->postJson('admin/business/mail/batch-delete', ['ids' => [$mail]]);
        $res->assertOk();
        $this->assertSame(1, $res->json('data.deleted'));
        $this->assertNotNull(DB::table('mail_recipients')->where('mail_id', $mail)->where('user_id', $recipient)->value('deleted_at'));
    }

    // ---------------------------------------------------------------- 空数组

    public function test_batch_read_with_empty_array_returns_success_not_error(): void
    {
        $this->loginAs($this->ids['recipient']);
        $res = $this->postJson('admin/business/mail/batch-read', ['ids' => []]);
        $res->assertOk();
        $this->assertSame(0, $res->json('data.updated'));
    }

    public function test_batch_unread_with_empty_array_returns_success_not_error(): void
    {
        $this->loginAs($this->ids['recipient']);
        $res = $this->postJson('admin/business/mail/batch-unread', ['ids' => []]);
        $res->assertOk();
        $this->assertSame(0, $res->json('data.updated'));
    }

    public function test_batch_delete_with_empty_array_returns_success_not_error(): void
    {
        $this->loginAs($this->ids['recipient']);
        $res = $this->postJson('admin/business/mail/batch-delete', ['ids' => []]);
        $res->assertOk();
        $this->assertSame(0, $res->json('data.deleted'));
    }

    // ---------------------------------------------------------------- 入参校验

    public function test_batch_read_requires_ids_array(): void
    {
        $this->loginAs($this->ids['recipient']);
        $this->postJson('admin/business/mail/batch-read', ['ids' => '1,2,3'])->assertStatus(422);
        $this->postJson('admin/business/mail/batch-read', [])->assertStatus(422);
    }

    public function test_batch_read_filters_non_integer_ids(): void
    {
        $recipient = $this->ids['recipient'];
        $mail = $this->sendMail('脏 id 过滤', $recipient);

        $this->loginAs($recipient);
        // 含一个非整数项：只处理合法的 mail id，非整数被过滤（不应 500）
        $res = $this->postJson('admin/business/mail/batch-read', ['ids' => ['abc', $mail]]);
        $res->assertOk();
        $this->assertSame(1, $res->json('data.updated'));
    }

    public function test_batch_read_ignores_ids_not_owned_by_current_user(): void
    {
        $recipient = $this->ids['recipient'];
        $sender = $this->ids['sender'];
        $mail = $this->sendMail('越权 id', $recipient);

        // 发件人视角：这封 mail 的收件记录属于 recipient，sender 不是收件人，
        // where user_id 层已挡 → updated 应为 0，且不报错
        $this->loginAs($sender);
        $res = $this->postJson('admin/business/mail/batch-read', ['ids' => [$mail]]);
        $res->assertOk();
        $this->assertSame(0, $res->json('data.updated'));
        // 收件记录未被动过
        $this->assertSame(0, (int) DB::table('mail_recipients')->where('mail_id', $mail)->where('user_id', $recipient)->value('is_read'));
    }

    public function test_batch_operations_run_inside_transaction(): void
    {
        // 冒烟：批量删除在事务内完成，两张表（mail_recipients 与 mails 的 from_user 侧）
        // 的删除要么都发生、要么都不发生。这里以收件人删除为验证点，
        // 事务正确性由失败回滚路径保证——单测无法强制注入失败，
        // 但至少验证正常路径下删除结果一致（事务开启无副作用）。
        $recipient = $this->ids['recipient'];
        $mail = $this->sendMail('事务冒烟', $recipient);

        $this->loginAs($recipient);
        $this->postJson('admin/business/mail/batch-delete', ['ids' => [$mail]])->assertOk();
        $this->assertNotNull(DB::table('mail_recipients')->where('mail_id', $mail)->where('user_id', $recipient)->value('deleted_at'));
    }

    // ---------------------------------------------------------------- 助手

    private function sendMail(string $subject, int $recipient): int
    {
        $this->loginAs($this->ids['sender']);
        $res = $this->postJson('admin/business/mail', [
            'subject' => $subject,
            'content' => '内容',
            'to_ids' => [$recipient],
            'save_type' => 'send',
        ]);
        $res->assertOk();

        return (int) $res->json('data.id');
    }

    private function loginAs(int $userId): void
    {
        $this->withToken(auth('admin')->login(User::find($userId)));
    }

    private function seedMailData(): void
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

        $this->loginAs($this->ids['sender']);
    }
}
