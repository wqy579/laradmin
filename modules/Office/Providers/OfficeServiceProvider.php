<?php

namespace Modules\Office\Providers;

use Illuminate\Support\ServiceProvider;

class OfficeServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
