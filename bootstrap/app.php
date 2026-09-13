<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\AuthCheckMiddleware;
use App\Http\Middleware\RateLimitMiddleware;

return Application::configure(basePath: dirname(__DIR__))
	->withRouting(
		web: __DIR__.'/../routes/web.php',
		api: __DIR__.'/../routes/api.php',
		commands: __DIR__.'/../routes/console.php',
		then: function() {
			Route::middleware(['api', 'stock.snapshot'])
				->prefix('admin')
				->name('admin.')
				->group(base_path('routes/admin.php'));
		},
		health: '/up',
	)
	->withMiddleware(function (Middleware $middleware): void {
		$middleware->alias([
			'auth.check' => \App\Http\Middleware\AuthCheckMiddleware::class,
			'log.request' => \App\Http\Middleware\LogRequestMiddleware::class,
			'rate.limit' => \App\Http\Middleware\RateLimitMiddleware::class,
			'stock.snapshot' => \Modules\Business\Http\Middleware\StockSnapshotMiddleware::class,
		]);
	})
	->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function(Request $request, Throwable $e){
			return $request->expectsJson() || $request->is('api/*') || $request->is('admin/*');
		});
	})
	->withEvents(discover: [
		__DIR__ . '/../app/Listeners'
	])->create();
