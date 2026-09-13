<?php

namespace Modules\System\Events;

use Modules\System\Models\Notification;

class NotificationCreated
{
    public function __construct(
        public readonly Notification $notification
    ) {}
}
