<?php

namespace Modules\System\Providers;

use App\Contracts\TaskNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\System\Console\Commands\RetryUnsentNotifications;
use Modules\System\Console\Commands\RunScheduled;
use Modules\System\Console\Commands\UpdateMenuIcons;
use Modules\System\Events\NotificationCreated;
use Modules\System\Listeners\SendNotificationViaWebSocket;
use Modules\System\Services\NotificationService;

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

        // 通知能力通过内核契约对外暴露，消费方（Auth）不依赖 Modules\System。
        // 绑定放在模块自己的 Provider 里，内核只声明契约、不知道实现。
        $this->app->bind(TaskNotification::class, NotificationService::class);
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
