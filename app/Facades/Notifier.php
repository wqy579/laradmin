<?php

namespace App\Facades;

use App\Models\System\Notification;
use App\Services\System\NotificationService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Notification sendToUser(int $userId, string $title, string $content, string $type = 'info', string $category = 'system', array $extraData = [])
 * @method static array sendToUsers(array $userIds, string $title, string $content, string $type = 'info', string $category = 'system', array $extraData = [])
 * @method static array broadcast(string $title, string $content, string $type = 'info', string $category = 'announcement', array $extraData = [])
 * @method static Notification sendTaskNotification(int $userId, string $title, string $content, array $extraData = [])
 * @method static Notification sendReminderNotification(int $userId, string $title, string $content, array $extraData = [])
 * @method static array sendMaintenanceNotification(string $title, string $content, array $extraData = [])
 * @method static Notification sendNewMessageNotification(int $userId, string $title, string $content, array $extraData = [])
 */
class Notifier extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NotificationService::class;
    }
}
