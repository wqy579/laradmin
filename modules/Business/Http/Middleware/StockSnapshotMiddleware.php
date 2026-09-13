<?php

namespace Modules\Business\Http\Middleware;

use Modules\Business\Services\StockSnapshotService;
use Closure;
use Illuminate\Http\Request;

class StockSnapshotMiddleware
{
    public function __construct(private StockSnapshotService $service) {}

    public function handle(Request $request, Closure $next)
    {
        $this->service->checkAndSnapshot();

        return $next($request);
    }
}
