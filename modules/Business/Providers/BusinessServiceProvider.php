<?php

namespace Modules\Business\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Business\Console\Commands\StockSnapshot;

class BusinessServiceProvider extends ServiceProvider
{
    protected array $commands = [
        StockSnapshot::class,
    ];

    public function register(): void
    {
        $this->commands($this->commands);
    }

    public function boot(): void
    {
        // 模块自持迁移：注册后普通 migrate 也会执行
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
