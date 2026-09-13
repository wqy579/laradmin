<?php

namespace Modules\System\Services;

use Modules\System\Events\NotificationCreated;
use Modules\System\Models\Notification;
use Modules\System\Services\WebSocket\WebSocketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * @var WebSocketService
     */
    protected $webSocketService;

    /**
     * NotificationService constructor
     */
    public function __construct()
    {
        $this->webSocketService = app(WebSocketService::class);
    }

    /**
     * 获取通知列表
     *
     * @param array $params
     * @return array
     */
    public function getList(array $params): array
    {
        $query = Notification::query();

        // 过滤用户ID（支持 JSON 查询）
        if (!empty($params['user_id'])) {
            $query->whereJsonContains('user_ids', $params['user_id']);
        }

        // 关键字搜索
        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('title', 'like', '%' . $params['keyword'] . '%')
                    ->orWhere('content', 'like', '%' . $params['keyword'] . '%');
            });
        }

        // 过滤阅读状态
        if (isset($params['is_read']) && $params['is_read'] !== '') {
            $query->where('is_read', $params['is_read']);
        }

        // 过滤通知类型
        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        // 过滤通知分类
        if (!empty($params['category'])) {
            $query->where('category', $params['category']);
        }

        // 日期范围
        if (!empty($params['start_date'])) {
            $query->where('created_at', '>=', $params['start_date']);
        }
        if (!empty($params['end_date'])) {
            $query->where('created_at', '<=', $params['end_date']);
        }

        $query->orderBy('created_at', 'desc');

        $pageSize = $params['page_size'] ?? 20;
        $list = $query->paginate($pageSize);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ];
    }

    /**
     * 获取未读通知列表
     *
     * @param int $userId
     * @param int $limit
     * @param int $page
     * @param string|null $type
     * @return array
     */
    public function getUnreadNotifications(int $userId, int $limit = 10, int $page = 1, ?string $type = null): array
    {
        $query = Notification::whereJsonContains('user_ids', $userId)
            ->where('is_read', false);

        // 按类型过滤
        if (!empty($type)) {
            $query->where('type', $type);
        }

        $query->orderBy('created_at', 'desc');

        // 分页处理
        $list = $query->paginate($limit, ['*'], 'page', $page);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ];
    }

    /**
     * 获取未读通知数量
     *
     * @param int $userId
     * @return int
     */
    public function getUnreadCount(int $userId): int
    {
        return Notification::whereJsonContains('user_ids', $userId)
            ->where('is_read', false)
            ->count();
    }

    /**
     * 根据ID获取通知
     *
     * @param int $id
     * @return Notification|null
     */
    public function getById(int $id): ?Notification
    {
        return Notification::find($id);
    }

    /**
     * 创建通知
     *
     * @param array $data
     * @return Notification
     */
    public function create(array $data): Notification
    {
        // 验证至少有用户ID或部门ID
        $this->validateRecipients($data);

        // 确保 action_data 是数组格式
        if (isset($data['action_data']) && is_string($data['action_data'])) {
            $data['action_data'] = json_decode($data['action_data'], true) ?? [];
        }

        // 如果 action_data 不是数组，转换为标准格式
        if (isset($data['action_data']) && !isset($data['action_data'][0])) {
            $data['action_data'] = [$data['action_data']];
        }

        $notification = Notification::create($data);

        // 推送通知给所有目标用户
        $this->pushNotificationToTargets($notification);

        return $notification;
    }

    /**
     * 验证接收者（user_ids 或 department_ids 至少有一个）
     */
    protected function validateRecipients(array $data): void
    {
        $userIds = $data['user_ids'] ?? [];
        $departmentIds = $data['department_ids'] ?? [];

        if (empty($userIds) && empty($departmentIds)) {
            throw new \InvalidArgumentException('user_ids 和 department_ids 至少需要一个非空');
        }
    }

    /**
     * 推送通知给所有目标用户
     */
    protected function pushNotificationToTargets(Notification $notification): void
    {
        $targetUserIds = $notification->getTargetUserIds();

        foreach ($targetUserIds as $userId) {
            if ($this->webSocketService->isUserOnline($userId)) {
                $this->sendViaWebSocket($notification, $userId);
            }
        }
    }

    /**
     * 批量创建通知
     */
    public function batchCreate(array $notificationsData): array
    {
        $now = now();
        $notifications = [];

        DB::beginTransaction();
        try {
            foreach ($notificationsData as $data) {
                $this->validateRecipients($data);

                // 处理 action_data
                if (isset($data['action_data']) && is_string($data['action_data'])) {
                    $data['action_data'] = json_decode($data['action_data'], true) ?? [];
                }
                if (isset($data['action_data']) && !isset($data['action_data'][0])) {
                    $data['action_data'] = [$data['action_data']];
                }

                $data['created_at'] = $now;
                $data['updated_at'] = $now;

                $notification = Notification::create($data);
                $notifications[] = $notification;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('批量创建通知失败', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // 推送通知
        foreach ($notifications as $notification) {
            $this->pushNotificationToTargets($notification);
        }

        return $notifications;
    }

    /**
     * 发送系统通知给单个用户
     *
     * @param int $userId
     * @param string $title
     * @param string $content
     * @param string $type
     * @param string $category
     * @param array $actionData
     * @return Notification
     */
    public function sendToUser(
        int $userId,
        string $title,
        string $content,
        string $type = Notification::TYPE_INFO,
        string $category = Notification::CATEGORY_SYSTEM,
        array $actionData = []
    ): Notification {
        $data = [
            'user_ids' => [$userId],
            'title' => $title,
            'content' => $content,
            'type' => $type,
            'category' => $category,
            'action_data' => $actionData,
            'is_read' => false,
        ];

        return $this->create($data);
    }

    /**
     * 发送系统通知给多个用户
     *
     * @param array $userIds
     * @param string $title
     * @param string $content
     * @param string $type
     * @param string $category
     * @param array $actionData
     * @return array
     */
    public function sendToUsers(
        array $userIds,
        string $title,
        string $content,
        string $type = Notification::TYPE_INFO,
        string $category = Notification::CATEGORY_SYSTEM,
        array $actionData = []
    ): array {
        $data = [
            'user_ids' => $userIds,
            'title' => $title,
            'content' => $content,
            'type' => $type,
            'category' => $category,
            'action_data' => $actionData,
            'is_read' => false,
        ];

        return $this->batchCreate([$data]);
    }

    /**
     * 发送系统通知给部门成员
     *
     * @param array $departmentIds
     * @param string $title
     * @param string $content
     * @param string $type
     * @param string $category
     * @param array $actionData
     * @return array
     */
    public function sendToDepartments(
        array $departmentIds,
        string $title,
        string $content,
        string $type = Notification::TYPE_INFO,
        string $category = Notification::CATEGORY_SYSTEM,
        array $actionData = []
    ): array {
        $data = [
            'department_ids' => $departmentIds,
            'title' => $title,
            'content' => $content,
            'type' => $type,
            'category' => $category,
            'action_data' => $actionData,
            'is_read' => false,
        ];

        return $this->batchCreate([$data]);
    }

    /**
     * 发送系统通知给指定用户和部门成员
     *
     * @param array $userIds
     * @param array $departmentIds
     * @param string $title
     * @param string $content
     * @param string $type
     * @param string $category
     * @param array $actionData
     * @return array
     */
    public function sendToUsersAndDepartments(
        array $userIds,
        array $departmentIds,
        string $title,
        string $content,
        string $type = Notification::TYPE_INFO,
        string $category = Notification::CATEGORY_SYSTEM,
        array $actionData = []
    ): array {
        $data = [
            'user_ids' => $userIds,
            'department_ids' => $departmentIds,
            'title' => $title,
            'content' => $content,
            'type' => $type,
            'category' => $category,
            'action_data' => $actionData,
            'is_read' => false,
        ];

        return $this->batchCreate([$data]);
    }

    /**
     * 发送系统通知给多个用户
     */
    public function sendSystemNotificationToMultipleUsers(
        array $userIds,
        string $title,
        string $content,
        string $type = Notification::TYPE_INFO,
        string $category = Notification::CATEGORY_SYSTEM,
        array $extraData = []
    ): array {
        $notificationsData = [];

        foreach ($userIds as $userId) {
            $notificationsData[] = [
                'user_ids' => [$userId],
                'title' => $title,
                'content' => $content,
                'type' => $type,
                'category' => $category,
                'data' => $extraData,
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $this->batchCreate($notificationsData);
    }

    /**
     * 发送系统广播通知（所有用户）
     *
     * @param string $title
     * @param string $content
     * @param string $type
     * @param string $category
     * @param array $extraData
     * @return array
     */
    public function broadcast(
        string $title,
        string $content,
        string $type = Notification::TYPE_INFO,
        string $category = Notification::CATEGORY_ANNOUNCEMENT,
        array $extraData = []
    ): array {
        // 获取所有用户ID
        $userIds = \Modules\Auth\Models\User::where('status', 1)->pluck('id')->toArray();

        return $this->sendToUsers($userIds, $title, $content, $type, $category, $extraData);
    }

    /**
     * 通过WebSocket发送通知
     *
     * @param Notification $notification
     * @param int|null $targetUserId 目标用户ID
     * @return bool
     */
    protected function sendViaWebSocket(Notification $notification, ?int $targetUserId = null): bool
    {
        $data = [
            'type' => 'notification',
            'data' => [
                'id' => $notification->id,
                'title' => $notification->title,
                'content' => $notification->content,
                'type' => $notification->type,
                'category' => $notification->category,
                'data' => $notification->data,
                'action_data' => $notification->action_data ?? [],
                'timestamp' => $notification->created_at->timestamp,
            ]
        ];

        $userId = $targetUserId ?? ($notification->user_ids[0] ?? null);
        if (!$userId) {
            return false;
        }

        $result = $this->webSocketService->sendToUser($userId, $data);

        if ($result) {
            $notification->markAsSent();
        } else {
            $notification->incrementRetry();
        }

        return $result;
    }

    /**
     * 重试发送未发送的通知
     *
     * @param int $limit
     * @return int
     */
    public function retryUnsentNotifications(int $limit = 100): int
    {
        $notifications = Notification::where('sent_via_websocket', false)
            ->where('retry_count', '<', 3)
            ->where('created_at', '>', now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $sentCount = 0;

        foreach ($notifications as $notification) {
            foreach ($notification->getTargetUserIds() as $userId) {
                if ($this->webSocketService->isUserOnline($userId)) {
                    if ($this->sendViaWebSocket($notification, $userId)) {
                        $sentCount++;
                    }
                }
            }
        }

        return $sentCount;
    }

    /**
     * 标记通知为已读
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function markAsRead(int $id, int $userId): bool
    {
        $notification = Notification::where('id', $id)
            ->whereJsonContains('user_ids', $userId)
            ->first();

        if (!$notification) {
            return false;
        }

        return $notification->markAsRead();
    }

    /**
     * 批量标记通知为已读
     *
     * @param array $ids
     * @param int $userId
     * @return int
     */
    public function batchMarkAsRead(array $ids, int $userId): int
    {
        return Notification::whereJsonContains('user_ids', $userId)
            ->whereIn('id', $ids)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /**
     * 标记所有通知为已读
     *
     * @param int $userId
     * @return int
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::whereJsonContains('user_ids', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /**
     * 删除通知
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function delete(int $id, int $userId): bool
    {
        $notification = Notification::where('id', $id)
            ->whereJsonContains('user_ids', $userId)
            ->first();

        if (!$notification) {
            return false;
        }

        return $notification->delete();
    }

    /**
     * 批量删除通知
     *
     * @param array $ids
     * @param int $userId
     * @return int
     */
    public function batchDelete(array $ids, int $userId): int
    {
        return Notification::whereJsonContains('user_ids', $userId)
            ->whereIn('id', $ids)
            ->delete();
    }

    /**
     * 清空已读通知
     *
     * @param int $userId
     * @return int
     */
    public function clearReadNotifications(int $userId): int
    {
        return Notification::whereJsonContains('user_ids', $userId)
            ->where('is_read', true)
            ->delete();
    }

    /**
     * 获取通知统计
     *
     * @param int $userId
     * @return array
     */
    public function getStatistics(int $userId): array
    {
        $total = Notification::whereJsonContains('user_ids', $userId)->count();
        $unread = Notification::whereJsonContains('user_ids', $userId)->where('is_read', false)->count();
        $read = Notification::whereJsonContains('user_ids', $userId)->where('is_read', true)->count();

        // 按类型统计
        $byType = Notification::whereJsonContains('user_ids', $userId)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        // 按分类统计
        $byCategory = Notification::whereJsonContains('user_ids', $userId)
            ->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        return [
            'total' => $total,
            'unread' => $unread,
            'read' => $read,
            'by_type' => $byType,
            'by_category' => $byCategory,
        ];
    }

    /**
     * 发送任务通知
     *
     * @param int $userId
     * @param string $title
     * @param string $content
     * @param array $taskData
     * @return Notification
     */
    public function sendTaskNotification(int $userId, string $title, string $content, array $extraData = []): Notification
    {
        return $this->sendToUser(
            $userId,
            $title,
            $content,
            Notification::TYPE_TASK,
            Notification::CATEGORY_TASK,
            $extraData
        );
    }

    /**
     * 发送系统维护通知
     *
     * @param string $title
     * @param string $content
     * @param array $maintenanceData
     * @return array
     */
    public function sendMaintenanceNotification(string $title, string $content, array $extraData = []): array
    {
        return $this->broadcast(
            $title,
            $content,
            Notification::TYPE_WARNING,
            Notification::CATEGORY_ANNOUNCEMENT,
            $extraData
        );
    }

    /**
     * 发送新消息通知
     *
     * @param int $userId
     * @param string $title
     * @param string $content
     * @param array $messageData
     * @return Notification
     */
    public function sendNewMessageNotification(int $userId, string $title, string $content, array $extraData = []): Notification
    {
        return $this->sendToUser(
            $userId,
            $title,
            $content,
            Notification::TYPE_INFO,
            Notification::CATEGORY_MESSAGE,
            $extraData
        );
    }

    /**
     * 发送提醒通知
     *
     * @param int $userId
     * @param string $title
     * @param string $content
     * @param array $reminderData
     * @return Notification
     */
    public function sendReminderNotification(int $userId, string $title, string $content, array $extraData = []): Notification
    {
        return $this->sendToUser(
            $userId,
            $title,
            $content,
            Notification::TYPE_WARNING,
            Notification::CATEGORY_REMINDER,
            $extraData
        );
    }
}
