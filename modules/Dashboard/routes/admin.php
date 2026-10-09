<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

/**
 * 智慧大屏模块路由（Dashboard）
 *
 * 纯聚合接口，无写操作。URL 前缀 dashboard。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    Route::prefix('dashboard')->group(function () {
        Route::get('/metrics', [DashboardController::class, 'metrics']);
        Route::get('/realtime-orders', [DashboardController::class, 'realtimeOrders']);
        Route::get('/category-proportion', [DashboardController::class, 'categoryProportion']);
        Route::get('/sales-trend', [DashboardController::class, 'salesTrend']);
        Route::get('/customer-rank', [DashboardController::class, 'customerRank']);
        Route::get('/inventory-overview', [DashboardController::class, 'inventoryOverview']);
        Route::get('/inventory-warning', [DashboardController::class, 'inventoryWarning']);
        Route::get('/salesman-rank', [DashboardController::class, 'salesmanRank']);
    });
});
