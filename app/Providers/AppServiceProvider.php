<?php

namespace App\Providers;

use App\Listeners\FlushRequestStateOnRequestHandled;
use Illuminate\Foundation\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // 请求开头由 app/Http/Middleware/FlushRequestState.php 清一次，
        // 响应返回后再清一次，两次的理由写在各自文件头。
        //
        // 这里显式注册而不是依赖事件自动发现：bootstrap/app.php 的
        // withEvents(discover:) 只按 App\Listeners\X ↔ App\Events\X 配对，
        // 监听的是框架自带的 Illuminate\Foundation\Events\RequestHandled，
        // 本地没有对应的 App\Events 类，自动发现配不上。
        Event::listen(RequestHandled::class, FlushRequestStateOnRequestHandled::class);
    }
}
