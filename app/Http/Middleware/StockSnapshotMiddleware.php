<?php

namespace App\Http\Middleware;

use App\Services\Business\StockSnapshotService;
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
