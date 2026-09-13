<?php

namespace Modules\System\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\System\Console\Commands\RetryUnsentNotifications;
use Modules\System\Console\Commands\RunScheduled;
use Modules\System\Console\Commands\UpdateMenuIcons;
use Modules\System\Events\NotificationCreated;
use Modules\System\Listeners\SendNotificationViaWebSocket;

class SystemServiceProvider extends ServiceProvider
{
    protected array $commands = [
        RetryUnsentNotifications::class,
        RunScheduled::class,
        UpdateMenuIcons::class,
    ];

    public function register(): void
    {
        $this->commands($this->commands);
    }

    public function boot(): void
    {
        Event::listen(
            NotificationCreated::class,
            SendNotificationViaWebSocket::class,
        );
    }
}
