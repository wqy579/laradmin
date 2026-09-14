<?php

namespace Modules\Business\Providers;

use Illuminate\Support\ServiceProvider;

class BusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // 模块自持迁移：注册后普通 migrate 也会执行
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
