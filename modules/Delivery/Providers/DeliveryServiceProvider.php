<?php

namespace Modules\Delivery\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 配送管理模块服务提供者。
 *
 * 仅注册迁移；路由由 bootstrap/app.php 的 glob 自动发现各模块 routes/admin.php。
 */
class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
