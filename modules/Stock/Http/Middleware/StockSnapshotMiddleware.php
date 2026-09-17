<?php

namespace Modules\Stock\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Stock\Services\StockSnapshotService;

class StockSnapshotMiddleware
{
    public function __construct(private StockSnapshotService $service) {}

    public function handle(Request $request, Closure $next)
    {
        $this->service->checkAndSnapshot();

        return $next($request);
    }
}
