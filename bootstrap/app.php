<?php

use App\Http\Middleware\FlushRequestState;
use App\Http\Middleware\RateLimitMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\AuthCheckMiddleware;
use Modules\Stock\Http\Middleware\StockSnapshotMiddleware;
use Modules\System\Http\Middleware\LogRequestMiddleware;

/*
|--------------------------------------------------------------------------
| 模块化路由加载
|--------------------------------------------------------------------------
|
| 路由归属：
|   modules/<Module>/routes/api.php    —— 模块自己的对外 API
|   modules/<Module>/routes/admin.php  —— 模块自己的管理端接口
|   routes/admin.php                   —— 内核级路由（不归属任何业务模块）
|
| 内核只干两件事：发现模块路由文件 + 施加统一信封。模块只写自己的路由声明，
| 不碰信封、不碰鉴权中间件——信封在哪层施加是架构决策，改动会同时影响所有模块。
|
| 当前没有内核级对外 API（原先 routes/api.php 里的 4 条已全归 System 模块）。
| 真有需要时，在 withRouting 里加回 `api: base_path('routes/api.php')` 即可。
|
| ⚠️ 信封顺序不可调整：tests/Feature/RouteBaselineTest.php 用路由表快照逐条比对
| method / uri / name / action / middleware，改错一个中间件名字顺序就会红灯。
| stock.snapshot 虽然是 Stock 模块的中间件，但作用域是「所有管理端请求」，
| 属于横切关注点，所以留在内核信封里而不进 Stock。
|
*/
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // 内核级管理路由（跨模块的运维闭包路由）
            Route::middleware(['api', 'stock.snapshot'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            // 模块路由：glob 自动发现，新增模块放好文件即被加载
            // 按路径排序，保证注册顺序跨环境稳定可复现
            foreach (glob(base_path('modules/*/routes/api.php')) as $moduleApi) {
                Route::middleware('api')->prefix('api')->group($moduleApi);
            }
            foreach (glob(base_path('modules/*/routes/admin.php')) as $moduleAdmin) {
                Route::middleware(['api', 'stock.snapshot'])
                    ->prefix('admin')
                    ->name('admin.')
                    ->group($moduleAdmin);
            }
        },
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 中间件别名集中声明在这一个地方。
        //
        // 这是「app/ 不依赖模块」规则的唯一登记例外：别名表是全局中间件词汇表，
        // 属配置而非代码耦合。中间件类的**代码**都在各自模块里
        // （modules/Auth、modules/System、modules/Stock、modules/Order、modules/Business），内核这里只做映射。
        //
        // 曾试过把别名注册挪到各模块 Provider 的 boot() 里（Route::aliasMiddleware），
        // 功能正常但 route:list 会把这些别名解析成完整类名、而 withMiddleware 注册的
        // 仍显示别名名——同一份快照里两种表示法，且依赖 afterResolving(HttpKernel)
        // 的注册时机。集中声明更直观，快照也稳定。

        // 常驻进程（laravel-s）下必须：容器单例跨请求复用，不清就会把上一个用户的
        // JWT 身份漏给下一个请求。prepend 让它排在全局栈最前（TrustProxies 之前），
        // 任何中间件读到状态之前先被清空。
        // 这是全局中间件、不进路由栈，所以 route:list 的路由表快照不受影响。
        $middleware->prepend(FlushRequestState::class);

        $middleware->alias([
            'auth.check' => AuthCheckMiddleware::class,
            'log.request' => LogRequestMiddleware::class,
            'rate.limit' => RateLimitMiddleware::class,
            'stock.snapshot' => StockSnapshotMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->expectsJson() || $request->is('api/*') || $request->is('admin/*');
        });
    })
    ->withEvents(discover: [
        __DIR__.'/../app/Listeners',
    ])->create();
