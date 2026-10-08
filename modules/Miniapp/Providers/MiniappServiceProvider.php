<?php

namespace Modules\Miniapp\Providers;

use Illuminate\Support\ServiceProvider;

class MiniappServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
