<?php

namespace Modules\System\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\System\Services\NotificationService;
use App\Http\Controllers\Controller;
use Modules\System\Http\Requests\NotificationRequest;

class Notification extends Controller
{
    /**
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Notification constructor
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * 获取通知列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $params = $request->all();

        // 如果没有指定user_id，使用当前登录用户
        if (empty($params['user_id'])) {
            $params['user_id'] = auth('admin')->id();
        }

        $result = $this->notificationService->getList($params);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result
        ]);
    }

    /**
     * 获取未读通知
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unread(Request $request): JsonResponse
    {
        $userId = auth('admin')->id();
        $limit = $request->input('limit', $request->input('page_size', 10));
        $page = $request->input('page', 1);
        $type = $request->input('type');

        $result = $this->notificationService->getUnreadNotifications($userId, $limit, $page, $type);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result
        ]);
    }

    /**
     * 获取未读通知数量
     *
     * @return JsonResponse
     */
    public function unreadCount(): JsonResponse
    {
        $userId = auth('admin')->id();
        $count = $this->notificationService->getUnreadCount($userId);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'count' => $count
            ]
        ]);
    }

    /**
     * 获取通知详情
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $notification = $this->notificationService->getById($id);

        if (!$notification) {
            return response()->json([
                'code' => 404,
                'message' => 'Notification not found',
                'data' => null
            ], 404);
        }

        // 检查权限：当前用户必须在通知目标用户范围内
        if (!$notification->isForUser(auth('admin')->id())) {
            return response()->json([
                'code' => 403,
                'message' => 'Access denied',
                'data' => null
            ], 403);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $notification
        ]);
    }

    /**
     * 标记通知为已读
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $userId = auth('admin')->id();
        $result = $this->notificationService->markAsRead($id, $userId);

        if (!$result) {
            return response()->json([
                'code' => 404,
                'message' => 'Notification not found or access denied',
                'data' => null
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Notification marked as read',
            'data' => null
        ]);
    }

    /**
     * 批量标记通知为已读
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchMarkAsRead(NotificationRequest $request): JsonResponse
    {
        $userId = auth('admin')->id();
        $validated = $request->validated();
        $ids = $validated['ids'];

        $count = $this->notificationService->batchMarkAsRead($ids, $userId);

        return response()->json([
            'code' => 200,
            'message' => 'Notifications marked as read',
            'data' => [
                'count' => $count
            ]
        ]);
    }

    /**
     * 标记所有通知为已读
     *
     * @return JsonResponse
     */
    public function markAllAsRead(): JsonResponse
    {
        $userId = auth('admin')->id();
        $count = $this->notificationService->markAllAsRead($userId);

        return response()->json([
            'code' => 200,
            'message' => 'All notifications marked as read',
            'data' => [
                'count' => $count
            ]
        ]);
    }

    /**
     * 删除通知
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $userId = auth('admin')->id();
        $result = $this->notificationService->delete($id, $userId);

        if (!$result) {
            return response()->json([
                'code' => 404,
                'message' => 'Notification not found or access denied',
                'data' => null
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Notification deleted',
            'data' => null
        ]);
    }

    /**
     * 批量删除通知
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchDelete(NotificationRequest $request): JsonResponse
    {
        $userId = auth('admin')->id();
        $validated = $request->validated();
        $ids = $validated['ids'];

        $count = $this->notificationService->batchDelete($ids, $userId);

        return response()->json([
            'code' => 200,
            'message' => 'Notifications deleted',
            'data' => [
                'count' => $count
            ]
        ]);
    }

    /**
     * 清空已读通知
     *
     * @return JsonResponse
     */
    public function clearRead(): JsonResponse
    {
        $userId = auth('admin')->id();
        $count = $this->notificationService->clearReadNotifications($userId);

        return response()->json([
            'code' => 200,
            'message' => 'Read notifications cleared',
            'data' => [
                'count' => $count
            ]
        ]);
    }

    /**
     * 获取通知统计
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        $userId = auth('admin')->id();
        $stats = $this->notificationService->getStatistics($userId);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * 发送通知（管理员功能）
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function send(NotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $userIds = $validated['user_ids'] ?? null;
        $title = $validated['title'];
        $content = $validated['content'];
        $type = $validated['type'] ?? 'info';
        $category = $validated['category'] ?? 'system';
        $extraData = $validated['data'] ?? [];

        // 如果没有指定user_ids，则发送给所有用户
        if (empty($userIds)) {
            $result = $this->notificationService->broadcast(
                $title,
                $content,
                $type,
                $category,
                $extraData
            );
        } else {
            $result = $this->notificationService->sendToUsers(
                $userIds,
                $title,
                $content,
                $type,
                $category,
                $extraData
            );
        }

        return response()->json([
            'code' => 200,
            'message' => 'Notification sent successfully',
            'data' => [
                'count' => count($result)
            ]
        ]);
    }

    /**
     * 重试发送未发送的通知（管理员功能）
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function retryUnsent(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 100);
        $count = $this->notificationService->retryUnsentNotifications($limit);

        return response()->json([
            'code' => 200,
            'message' => 'Unsent notifications retried',
            'data' => [
                'count' => $count
            ]
        ]);
    }
}
