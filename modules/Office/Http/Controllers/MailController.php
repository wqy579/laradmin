<?php

namespace Modules\Office\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 模块六：内部邮件
 *
 * 四个信箱（收件箱 / 发件箱 / 草稿箱 / 回收站）共用一条列表接口，用 box 切换。
 * 「已读」「删除」都是收件人各自的状态，记在 mail_recipients 上；
 * 发件人删自己发出的邮件记在 mails.deleted_at 上——两套删除互不干扰，
 * 所以回收站里既有收到的也有发出的，来源不同但操作一致。
 */
class MailController extends Controller
{
    private const BOXES = ['inbox', 'sent', 'draft', 'trash'];

    public function index(Request $request): JsonResponse
    {
        $userId = (int) auth('admin')->id();
        $box = in_array($request->input('box'), self::BOXES, true) ? $request->input('box') : 'inbox';
        $keyword = trim((string) $request->input('keyword', ''));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));

        $query = $this->boxQuery($box, $userId);

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('m.subject', 'like', "%{$keyword}%")
                    ->orWhere('m.content', 'like', "%{$keyword}%");
            });
        }

        $paginator = $query->paginate($pageSize);

        $mailIds = collect($paginator->items())->pluck('id')->all();
        $recipients = $mailIds
            ? DB::table('mail_recipients as mr')
                ->join('auth_user as u', 'u.id', '=', 'mr.user_id')
                ->whereIn('mr.mail_id', $mailIds)
                ->get(['mr.mail_id', 'mr.type', 'u.real_name'])
                ->groupBy('mail_id')
            : collect();

        $list = collect($paginator->items())->map(function ($mail) use ($recipients, $box) {
            $people = $recipients->get($mail->id, collect());
            $mail->to_names = $people->where('type', 'to')->pluck('real_name')->implode('、');
            $mail->cc_names = $people->where('type', 'cc')->pluck('real_name')->implode('、');
            $mail->has_attachment = ! empty($mail->attachments) && $mail->attachments !== '[]';
            // 收件箱/回收站看收件人自己的已读标记；发件箱/草稿箱没有「已读」概念
            $mail->is_read = in_array($box, ['inbox', 'trash'], true) ? (bool) $mail->is_read : true;

            return $mail;
        })->all();

        return $this->success([
            'list' => $list,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'box' => $box,
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        $userId = (int) auth('admin')->id();

        $count = DB::table('mail_recipients')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->whereNull('deleted_at')
            ->count();

        return $this->success(['count' => $count]);
    }

    /** 通讯录：部门树 + 部门下的人，供写邮件时选收件人 */
    public function contacts(): JsonResponse
    {
        $departments = DB::table('auth_department')
            ->where('status', 1)
            ->orderBy('sort')
            ->get(['id', 'name', 'parent_id']);

        $users = DB::table('auth_user')
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'real_name', 'username', 'department_id']);

        $tree = $departments->map(function ($dept) use ($users) {
            return [
                'id' => $dept->id,
                'name' => $dept->name,
                'parent_id' => $dept->parent_id,
                'users' => $users->where('department_id', $dept->id)->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->real_name ?: $u->username,
                ])->values()->all(),
            ];
        });

        // 未分配部门的人也要能选到，否则新入职员工在通讯录里是「不存在」的
        $orphans = $users->filter(fn ($u) => ! $departments->contains('id', $u->department_id));

        return $this->success([
            'departments' => $tree,
            // value = 用户ID，label = 姓名：el-select 多选直接吃这个结构
            'users' => $users->map(fn ($u) => ['value' => $u->id, 'label' => $u->real_name ?: $u->username])->all(),
            'orphan_count' => $orphans->count(),
        ]);
    }

    public function show(int $mail): JsonResponse
    {
        $userId = (int) auth('admin')->id();

        $row = DB::table('mails as m')->where('m.id', $mail)->first();
        if (! $row) {
            return $this->notFound('邮件不存在');
        }

        if (! $this->canAccess($row, $userId)) {
            return $this->forbidden('无权查看该邮件');
        }

        $row->recipients = DB::table('mail_recipients as mr')
            ->join('auth_user as u', 'u.id', '=', 'mr.user_id')
            ->where('mr.mail_id', $mail)
            ->get(['mr.user_id', 'mr.type', 'mr.is_read', 'u.real_name']);

        // 打开即已读：只对收件人生效，发件人回看自己发的邮件不该被标成已读
        DB::table('mail_recipients')
            ->where('mail_id', $mail)
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'read_at' => now(), 'updated_at' => now()]);

        return $this->success($row);
    }

    /**
     * 写邮件：save_type=send 直接发送，draft 存草稿
     *
     * 草稿不写 mail_recipients——草稿还没发出去，收件人此刻不应该看到任何东西，
     * 收件人列表放在 mails 之外单独存会让「草稿转发送」多一步清理，
     * 这里直接把收件人写进 JSON 之外的选择是：草稿也写 recipients 但 mail.status=draft，
     * 收件箱查询带 status=sent 过滤，草稿自然不可见。
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'nullable|string|max:200',
            'content' => 'nullable|string',
            'to_ids' => 'nullable|array',
            'to_ids.*' => 'integer',
            'cc_ids' => 'nullable|array',
            'cc_ids.*' => 'integer',
            'attachments' => 'nullable|array',
            'save_type' => 'nullable|in:send,draft',
        ]);

        $userId = (int) auth('admin')->id();
        $userName = DB::table('auth_user')->where('id', $userId)->value('real_name');
        $isSend = ($validated['save_type'] ?? 'send') === 'send';

        if ($isSend && empty($validated['to_ids'])) {
            return $this->validateError(['to_ids' => ['请选择收件人']], '发送失败');
        }

        $mailId = DB::table('mails')->insertGetId([
            'subject' => $validated['subject'] ?? '',
            'content' => $validated['content'] ?? '',
            'from_user_id' => $userId,
            'from_name' => $userName,
            'status' => $isSend ? 'sent' : 'draft',
            'attachments' => json_encode($validated['attachments'] ?? [], JSON_UNESCAPED_UNICODE),
            'sent_at' => $isSend ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! empty($validated['to_ids'])) {
            $this->attachRecipients($mailId, $validated['to_ids'], 'to');
        }
        if (! empty($validated['cc_ids'])) {
            $this->attachRecipients($mailId, $validated['cc_ids'], 'cc');
        }

        return $this->created(['id' => $mailId], $isSend ? '邮件已发送' : '草稿已保存');
    }

    /** 编辑草稿后直接发送 */
    public function update(Request $request, int $mail): JsonResponse
    {
        $userId = (int) auth('admin')->id();
        $row = DB::table('mails')->where('id', $mail)->first();

        if (! $row || $row->from_user_id !== $userId) {
            return $this->notFound('草稿不存在');
        }

        $validated = $request->validate([
            'subject' => 'nullable|string|max:200',
            'content' => 'nullable|string',
            'to_ids' => 'nullable|array',
            'to_ids.*' => 'integer',
            'cc_ids' => 'nullable|array',
            'cc_ids.*' => 'integer',
            'attachments' => 'nullable|array',
            'save_type' => 'nullable|in:send,draft',
        ]);

        $isSend = ($validated['save_type'] ?? 'send') === 'send';
        if ($isSend && empty($validated['to_ids'])) {
            return $this->validateError(['to_ids' => ['请选择收件人']], '发送失败');
        }

        DB::table('mails')->where('id', $mail)->update([
            'subject' => $validated['subject'] ?? '',
            'content' => $validated['content'] ?? '',
            'status' => $isSend ? 'sent' : 'draft',
            'attachments' => json_encode($validated['attachments'] ?? [], JSON_UNESCAPED_UNICODE),
            'sent_at' => $isSend ? now() : null,
            'updated_at' => now(),
        ]);

        // 草稿转发送可能改了收件人：整份重灌，避免残留已移除的人
        DB::table('mail_recipients')->where('mail_id', $mail)->delete();
        if (! empty($validated['to_ids'])) {
            $this->attachRecipients($mail, $validated['to_ids'], 'to');
        }
        if (! empty($validated['cc_ids'])) {
            $this->attachRecipients($mail, $validated['cc_ids'], 'cc');
        }

        return $this->success(['id' => $mail], $isSend ? '邮件已发送' : '草稿已保存');
    }

    /**
     * 删除：进回收站（软删）
     *
     * 收件人删 = 删自己的收件记录；发件人删 = 删自己发的那封。
     * 两边都只影响操作者自己，这也是为什么回收站要按「我」来过滤而不是按邮件。
     */
    public function destroy(int $mail): JsonResponse
    {
        $userId = (int) auth('admin')->id();
        $now = now();

        $deleted = DB::table('mail_recipients')
            ->where('mail_id', $mail)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        $deleted += DB::table('mails')
            ->where('id', $mail)
            ->where('from_user_id', $userId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        return $deleted > 0 ? $this->success(null, '已移入回收站') : $this->error('邮件不存在或无权删除', 404);
    }

    public function markRead(int $mail): JsonResponse
    {
        return $this->setRead($mail, true);
    }

    public function markUnread(int $mail): JsonResponse
    {
        return $this->setRead($mail, false);
    }

    public function batchRead(Request $request): JsonResponse
    {
        return $this->batchSetRead($request, true);
    }

    public function batchUnread(Request $request): JsonResponse
    {
        return $this->batchSetRead($request, false);
    }

    public function batchDelete(Request $request): JsonResponse
    {
        $validated = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $userId = (int) auth('admin')->id();
        $now = now();

        $count = DB::table('mail_recipients')
            ->whereIn('mail_id', $validated['ids'])
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        $count += DB::table('mails')
            ->whereIn('id', $validated['ids'])
            ->where('from_user_id', $userId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        return $this->success(['deleted' => $count], "已移入回收站 {$count} 封");
    }

    private function setRead(int $mail, bool $read): JsonResponse
    {
        $userId = (int) auth('admin')->id();

        $updated = DB::table('mail_recipients')
            ->where('mail_id', $mail)
            ->where('user_id', $userId)
            ->update([
                'is_read' => $read,
                'read_at' => $read ? now() : null,
                'updated_at' => now(),
            ]);

        return $updated > 0
            ? $this->success(null, $read ? '已标记为已读' : '已标记为未读')
            : $this->error('邮件不存在或无权操作', 404);
    }

    private function batchSetRead(Request $request, bool $read): JsonResponse
    {
        $validated = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $userId = (int) auth('admin')->id();

        $count = DB::table('mail_recipients')
            ->whereIn('mail_id', $validated['ids'])
            ->where('user_id', $userId)
            ->update([
                'is_read' => $read,
                'read_at' => $read ? now() : null,
                'updated_at' => now(),
            ]);

        return $this->success(['updated' => $count], $read ? "已标记 {$count} 封为已读" : "已标记 {$count} 封为未读");
    }

    /** 四个信箱各自的查询 */
    private function boxQuery(string $box, int $userId)
    {
        switch ($box) {
            case 'sent':
                return DB::table('mails as m')
                    ->where('m.from_user_id', $userId)
                    ->where('m.status', 'sent')
                    ->whereNull('m.deleted_at')
                    ->select('m.*')
                    ->orderByDesc('m.sent_at')
                    ->orderByDesc('m.id');

            case 'draft':
                return DB::table('mails as m')
                    ->where('m.from_user_id', $userId)
                    ->where('m.status', 'draft')
                    ->whereNull('m.deleted_at')
                    ->select('m.*')
                    ->orderByDesc('m.id');

            case 'trash':
                // 回收站 = 我删掉的收件 + 我删掉的发件，两路 union 后按时间倒序
                $received = DB::table('mail_recipients as mr')
                    ->join('mails as m', 'm.id', '=', 'mr.mail_id')
                    ->where('mr.user_id', $userId)
                    ->whereNotNull('mr.deleted_at')
                    ->select('m.id', 'm.subject', 'm.content', 'm.from_user_id', 'm.from_name', 'm.status', 'm.attachments', 'm.sent_at', 'm.created_at', DB::raw('mr.is_read as is_read'), DB::raw('mr.deleted_at as trashed_at'));

                return DB::table('mails as m')
                    ->where('m.from_user_id', $userId)
                    ->whereNotNull('m.deleted_at')
                    ->select('m.id', 'm.subject', 'm.content', 'm.from_user_id', 'm.from_name', 'm.status', 'm.attachments', 'm.sent_at', 'm.created_at', DB::raw('1 as is_read'), DB::raw('m.deleted_at as trashed_at'))
                    ->union($received)
                    ->orderByDesc('trashed_at');

            default:
                return DB::table('mail_recipients as mr')
                    ->join('mails as m', 'm.id', '=', 'mr.mail_id')
                    ->where('mr.user_id', $userId)
                    ->where('m.status', 'sent')
                    ->whereNull('mr.deleted_at')
                    ->select('m.*', 'mr.is_read')
                    ->orderByDesc('m.sent_at')
                    ->orderByDesc('m.id');
        }
    }

    private function attachRecipients(int $mailId, array $userIds, string $type): void
    {
        $now = now();
        $rows = [];
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            $rows[] = [
                'mail_id' => $mailId,
                'user_id' => $uid,
                'type' => $type,
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            DB::table('mail_recipients')->insert($rows);
        }
    }

    /** 只有发件人、收件人、抄送人能看这封邮件 */
    private function canAccess(object $mail, int $userId): bool
    {
        if ((int) $mail->from_user_id === $userId) {
            return true;
        }

        return DB::table('mail_recipients')
            ->where('mail_id', $mail->id)
            ->where('user_id', $userId)
            ->exists();
    }
}
