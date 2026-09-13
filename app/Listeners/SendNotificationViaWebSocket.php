<?php

namespace App\Listeners;

use App\Events\NotificationCreated;
use App\Services\WebSocket\WebSocketService;

class SendNotificationViaWebSocket
{
    public function __construct(
        protected WebSocketService $webSocketService
    ) {}

    public function handle(NotificationCreated $event): void
    {
        $notification = $event->notification;

        if (!$this->webSocketService->isUserOnline($notification->user_id)) {
            return;
        }

        $data = [
            'type' => 'notification',
            'data' => [
                'id' => $notification->id,
                'title' => $notification->title,
                'content' => $notification->content,
                'type' => $notification->type,
                'category' => $notification->category,
                'data' => $notification->data,
                'action_type' => $notification->action_type,
                'action_data' => $notification->action_data,
                'timestamp' => $notification->created_at->timestamp,
            ],
        ];

        $result = $this->webSocketService->sendToUser($notification->user_id, $data);

        if ($result) {
            $notification->markAsSent();
        } else {
            $notification->incrementRetry();
        }
    }
}
