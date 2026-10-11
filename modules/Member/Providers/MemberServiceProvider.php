<?php

namespace Modules\Member\Providers;

use Illuminate\Support\ServiceProvider;

class MemberServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // 会员模块自持迁移：注册后普通 migrate 也会执行本目录。
        // 对齐 StockServiceProvider / OrderServiceProvider 的写法。
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
