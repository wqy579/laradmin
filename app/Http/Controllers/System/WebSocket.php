<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\WebSocket\WebSocketService;
use App\Http\Requests\System\WebSocketRequest;

/**
 * WebSocket Controller
 *
 * Provides API endpoints for WebSocket operations
 */
class WebSocket extends Controller
{
    /**
     * @var WebSocketService
     */
    protected $webSocketService;

    /**
     * WebSocket constructor
     */
    public function __construct()
    {
        $this->webSocketService = app(WebSocketService::class);
    }

    /**
     * Get online user count
     *
     * @return JsonResponse
     */
    public function getOnlineCount(): JsonResponse
    {
        $count = $this->webSocketService->getOnlineUserCount();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'online_count' => $count
            ]
        ]);
    }

    /**
     * Get online user IDs
     *
     * @return JsonResponse
     */
    public function getOnlineUsers(): JsonResponse
    {
        $userIds = $this->webSocketService->getOnlineUserIds();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'user_ids' => $userIds,
                'count' => count($userIds)
            ]
        ]);
    }

    /**
     * Check if a user is online
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkOnline(WebSocketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $validated['user_id'];
        $isOnline = $this->webSocketService->isUserOnline($userId);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'user_id' => $userId,
                'is_online' => $isOnline
            ]
        ]);
    }

    /**
     * Send message to a specific user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToUser(WebSocketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $validated['user_id'];
        $type = $validated['type'];
        $data = $validated['data'];

        $message = [
            'type' => $type,
            'data' => $data
        ];

        $sent = $this->webSocketService->sendToUser($userId, $message);

        return response()->json([
            'code' => $sent ? 200 : 404,
            'message' => $sent ? 'Message sent successfully' : 'User is not online',
            'data' => [
                'user_id' => $userId,
                'sent' => $sent
            ]
        ], $sent ? 200 : 404);
    }

    /**
     * Send message to multiple users
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToUsers(WebSocketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userIds = $validated['user_ids'];
        $type = $validated['type'];
        $data = $validated['data'];

        $message = [
            'type' => $type,
            'data' => $data
        ];

        $sentTo = $this->webSocketService->sendToUsers($userIds, $message);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'total_users' => count($userIds),
                'sent_to' => $sentTo,
                'failed' => count($userIds) - count($sentTo)
            ]
        ]);
    }

    /**
     * Broadcast message to all users
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function broadcast(WebSocketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $type = $validated['type'];
        $data = $validated['data'];
        $excludeUserId = $validated['exclude_user_id'] ?? null;

        $message = [
            'type' => $type,
            'data' => $data
        ];

        $count = $this->webSocketService->broadcast($message, $excludeUserId);

        return response()->json([
            'code' => 200,
            'message' => 'Broadcast sent successfully',
            'data' => [
                'sent_to' => $count,
                'exclude_user_id' => $excludeUserId
            ]
        ]);
    }

    /**
     * Send message to a channel
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToChannel(WebSocketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $channel = $validated['channel'];
        $type = $validated['type'];
        $data = $validated['data'];

        $message = [
            'type' => $type,
            'data' => $data
        ];

        $count = $this->webSocketService->sendToChannel($channel, $message);

        return response()->json([
            'code' => 200,
            'message' => 'Message sent to channel successfully',
            'data' => [
                'channel' => $channel,
                'sent_to' => $count
            ]
        ]);
    }

    // 通知发送已统一到 POST /admin/system/notification/send（NotificationService），
    // 该方法会同时持久化到数据库并通过 WebSocket 推送。
    // WebSocket Controller 仅保留实时消息推送能力（不持久化）。

    /**
     * Push data update
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function pushDataUpdate(WebSocketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userIds = $validated['user_ids'];
        $resourceType = $validated['resource_type'];
        $action = $validated['action'];
        $data = $validated['data'];

        $sentTo = $this->webSocketService->pushDataUpdate($userIds, $resourceType, $action, $data);

        return response()->json([
            'code' => 200,
            'message' => 'Data update pushed successfully',
            'data' => [
                'resource_type' => $resourceType,
                'action' => $action,
                'total_users' => count($userIds),
                'sent_to' => $sentTo,
                'failed' => count($userIds) - count($sentTo)
            ]
        ]);
    }

    /**
     * Push data update to channel
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function pushDataUpdateToChannel(WebSocketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $channel = $validated['channel'];
        $resourceType = $validated['resource_type'];
        $action = $validated['action'];
        $data = $validated['data'];

        $count = $this->webSocketService->pushDataUpdateToChannel($channel, $resourceType, $action, $data);

        return response()->json([
            'code' => 200,
            'message' => 'Data update pushed to channel successfully',
            'data' => [
                'channel' => $channel,
                'resource_type' => $resourceType,
                'action' => $action,
                'sent_to' => $count
            ]
        ]);
    }

    /**
     * Disconnect a user from WebSocket
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function disconnectUser(WebSocketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $validated['user_id'];
        $disconnected = $this->webSocketService->disconnectUser($userId);

        return response()->json([
            'code' => $disconnected ? 200 : 404,
            'message' => $disconnected ? 'User disconnected successfully' : 'User is not online',
            'data' => [
                'user_id' => $userId,
                'disconnected' => $disconnected
            ]
        ], $disconnected ? 200 : 404);
    }
}
