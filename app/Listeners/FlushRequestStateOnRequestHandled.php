<?php

namespace App\Listeners;

use App\Http\Middleware\FlushRequestState;
use Illuminate\Foundation\Events\RequestHandled;

/**
 * 请求收尾后清一次状态。
 *
 * RequestHandled 由 Illuminate\Foundation\Http\Kernel::dispatchToRouter() 在
 * sendRequestThroughRouter() 返回后触发——此时响应体已渲染完、log.request 也
 * 已经用正确的用户写完日志，清状态不会影响本次请求的结果。
 *
 * 为什么要和开头的中间件各清一次：laravel-s 底层是 Swoole 协程，同一 worker
 * 上两个请求可能交错执行。如果只在请求开头清，会出现 A 请求解析出 admin 身份后
 * 挂起在 DB 等待、B 请求进来清了一次状态、B 完成后 A 恢复又调 auth()->user()
 * ——A 拿到的是 B 刚写进去的身份。响应返回后再清一次，A 恢复时读到的是空状态，
 * 会老老实实重新解析自己请求上的 token。
 *
 * 这个事件在 FPM / php artisan serve 下同样触发，所以在非常驻环境里注册无害。
 */
class FlushRequestStateOnRequestHandled
{
    public function __construct(private FlushRequestState $flushRequestState)
    {
    }

    public function handle(RequestHandled $event): void
    {
        $this->flushRequestState->flush();
    }
}
