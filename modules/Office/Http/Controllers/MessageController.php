<?php

namespace Modules\Office\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Office\Models\Message;

/**
 * 消息通知中心。
 *
 * 铃铛角标 + 消息中心列表都走这里。每条消息按收件人独立存储（见 Message 模型注释），
 * 所有查询/写入都先锁死 user_id = 当前登录用户，天然做到「我的消息我可见、
 * 我的已读我不影响别人」，不需要像 system_notification 那样额外做 isForUser 校验。
 *
 * 写入侧（审批/库存预警）不在此控制器——见 NotifyApprover trait 与
 * StockWarningJob 命令。本控制器只读 + 改已读态 + 删除。
 */
class MessageController extends Controller
{
    use ResponseTrait;

    /** 未读角标数量 */
    public function unreadCount(): JsonResponse
    {
        $count = Message::where('user_id', $this->userId())
            ->where('is_read', false)
            ->count();

        return $this->success(['count' => $count]);
    }

    /**
     * 消息列表。
     *
     * status: unread | read | all（默认 all）
     * type: 可选，按消息类型筛选（approval / stock_warning / system / business）
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId();
        $status = $request->input('status', Message::STATUS_ALL);
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = Message::where('user_id', $userId);

        switch ($status) {
            case Message::STATUS_UNREAD:
                $query->where('is_read', false);
                break;
            case Message::STATUS_READ:
                $query->where('is_read', true);
                break;
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                    ->orWhere('content', 'like', "%{$keyword}%");
            });
        }

        $paginator = $query->orderByDesc('is_read')->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /** 标记单条已读：同时回传最新未读数，前端可一次更新角标，省一次请求 */
    public function markAsRead(int $id): JsonResponse
    {
        $updated = Message::where('id', $id)
            ->where('user_id', $this->userId())
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            return $this->notFound('消息不存在或无权操作');
        }

        return $this->success(['unread_count' => $this->unreadCountRaw()], '已标记为已读');
    }

    /** 全部已读 */
    public function markAllAsRead(): JsonResponse
    {
        $count = Message::where('user_id', $this->userId())
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now(), 'updated_at' => now()]);

        return $this->success(['count' => $count], "已标记 {$count} 条为已读");
    }

    /** 删除单条（软删，仅删自己那条） */
    public function destroy(int $id): JsonResponse
    {
        $deleted = Message::where('id', $id)
            ->where('user_id', $this->userId())
            ->delete();

        if (! $deleted) {
            return $this->notFound('消息不存在或无权删除');
        }

        return $this->success(null, '已删除');
    }

    private function userId(): int
    {
        return (int) auth('admin')->id();
    }

    private function unreadCountRaw(): int
    {
        return Message::where('user_id', $this->userId())
            ->where('is_read', false)
            ->count();
    }
}
