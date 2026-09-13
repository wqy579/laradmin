<?php

namespace App\Events;

use App\Models\System\Notification;

class NotificationCreated
{
    public function __construct(
        public readonly Notification $notification
    ) {}
}
