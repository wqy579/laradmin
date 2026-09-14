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
        // 模块自持迁移：注册后普通 migrate 也会执行
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::listen(
            NotificationCreated::class,
            SendNotificationViaWebSocket::class,
        );
    }
}
