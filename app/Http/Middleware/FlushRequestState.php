<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 请求级状态清理（常驻进程必需）
 *
 * 生产用 hhxsv5/laravel-s 常驻运行：同一个 worker 进程连续处理几千个请求，
 * 而 Laravel 容器里的单例在整个 worker 生命周期内始终是同一个实例。
 * 框架本身不做任何按请求清理——laravel-s 自带的 CleanerManager 也只清 url/request
 * 两个绑定（见 vendor/hhxsv5/laravel-s/src/Illuminate/Cleaners/RequestCleaner.php），
 * 于是上一个请求解析出来的状态会原封不动漏给下一个请求。
 *
 * 本项目踩到的实际后果：JWTGuard::user() 里
 *     if ($this->user !== null) return $this->user;   // 完全不校验 token
 * guard 一旦用过就不再回读 token，下一个请求即使不带 token、或带的是
 * 别人已登出/已拉黑的 token，也会拿到上一个用户的身份与权限。
 *
 * 挂法：bootstrap/app.php 里 $middleware->prepend(...)，保证它比任何
 * 全局/路由中间件都先执行。清理动作是幂等的，所以同一份 flush() 还会被
 * app/Listeners/FlushRequestStateOnRequestHandled.php 在响应返回后再调一次——
 * Swoole 是协程并发，同一 worker 上的请求可能交叠，只在开头清一次无法保护
 * 已经挂起、稍后恢复的上一个请求。
 *
 * ⚠️ 刻意不做的事（都试过，别改回来）：
 *  - 不清 'url'：UrlGenerator 与 session store 互相持有引用，请求处理中途销毁它
 *    会让 PHP 直接段错误（25 个连续请求稳定复现）。而且没必要——框架注册 url 时
 *    已挂 $app->refresh('request', $generator, 'setRequest')，请求换掉时它自己会跟换。
 *  - 不清 session：SessionManager::driver() 每次调用都新建 Store，并经
 *    $request->setLaravelSession() 挂在当前请求上，本就不是跨请求状态。
 *  - 不保留 actingAs()/setUser() 注入的身份：生产里身份只来自 token，
 *    没有「进程内注入用户」这回事。保留那个状态就等于重新打开本漏洞。
 *    测试里需要用真实 token，见 AuthFeatureTest 顶部的说明。
 */
class FlushRequestState
{
    public function __construct(private Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->flush();

        return $next($request);
    }

    /**
     * 清掉所有跨请求残留的「当前身份」状态。
     */
    public function flush(): void
    {
        // 1) 所有已解析的 guard 实例。
        //    JWTGuard::user() 缓存命中时不校验 token，不清就是把上一个用户的
        //    身份交给下一个请求。只清这一个不够，见下面的 JWT token 缓存。
        Auth::forgetGuards();

        // 2) tymon/jwt-auth 的 JWT 单例自己的 token 缓存。
        //    JWT::getToken() 在 $this->token 非 null 时不再解析本次请求头；
        //    任何代码调用过 JWTAuth::setToken()（续期、代签），那个 token 就一直挂着，
        //    下一个请求的 guard 即使重建，也照样从这个缓存里取到上一个人的 token。
        $this->app->make('tymon.jwt')->unsetToken();
    }
}
