<?php

namespace Modules\BorrowReturn\Providers;

use Illuminate\Support\ServiceProvider;

class BorrowReturnServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
