<?php

namespace Modules\Exchange\Providers;

use Illuminate\Support\ServiceProvider;

class ExchangeServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
