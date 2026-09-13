<?php

namespace App\Providers;

use App\Events\NotificationCreated;
use App\Listeners\SendNotificationViaWebSocket;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // 业务模块迁移位于 database/migrations/business，注册后普通 migrate 也会执行
        $this->loadMigrationsFrom(database_path('migrations/business'));

        Event::listen(
            NotificationCreated::class,
            SendNotificationViaWebSocket::class,
        );
    }
}
