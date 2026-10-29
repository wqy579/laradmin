<?php

namespace Modules\Mall\Providers;

use Illuminate\Support\ServiceProvider;

class MallServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // F5 分销增值模块自持迁移：map / mall / sms 三块新表落在此处。
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
