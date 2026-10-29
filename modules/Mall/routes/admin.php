<?php

use Illuminate\Support\Facades\Route;
use Modules\Mall\Http\Controllers\MapController;

/**
 * F5 分销增值模块路由 —— 行程地图（第 1 块）。
 *
 * 统一前缀 /admin/f5，鉴权信封同其他模块（auth.check:admin + log.request）。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // —— 行程地图 ——
    Route::prefix('f5/map')->group(function () {
        Route::get('/salesmen', [MapController::class, 'salesmen']);
        Route::get('/trajectory', [MapController::class, 'trajectory']);
        Route::get('/customers', [MapController::class, 'customers']);
    });
});
