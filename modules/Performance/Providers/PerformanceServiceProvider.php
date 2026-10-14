<?php

namespace Modules\Performance\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 机器 C（绩效分析）服务提供者。
 *
 * 只做两件事：注册模块迁移、注册菜单 Seeder。
 * 路由由 bootstrap/app.php 的 glob 自动发现各模块 routes/admin.php。
 */
class PerformanceServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // 绩效模块自持迁移：注册后普通 migrate 也会执行本目录。
        // 菜单 Seeder 由 DatabaseSeeder 编排调用，不注册为 console command
        // （Seeder 不是 Command，注册会触发 TypeError）。
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
