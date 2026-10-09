<?php

use Illuminate\Support\Facades\Route;
use Modules\Exchange\Http\Controllers\ExchangeOrderController;
use Modules\Exchange\Http\Controllers\ExchangeSummaryController;

/**
 * 换货管理模块路由（Exchange）
 *
 * 挂在库存管理下：换货单 / 换货汇总。URL 前缀 business/exchange-*。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 换货单
    Route::prefix('business/exchange-order')->group(function () {
        // 必须在 /{id} 之前注册，否则会被 /{id} 抢先匹配
        Route::get('/warehouse-products', [ExchangeOrderController::class, 'warehouseProducts']);
        Route::get('/sales-order-items', [ExchangeOrderController::class, 'salesOrderItems']);
        Route::get('/export', [ExchangeOrderController::class, 'export']);
        Route::get('/', [ExchangeOrderController::class, 'index']);
        Route::post('/', [ExchangeOrderController::class, 'store']);
        Route::get('/{id}', [ExchangeOrderController::class, 'show']);
        Route::put('/{id}', [ExchangeOrderController::class, 'update']);
        Route::delete('/{id}', [ExchangeOrderController::class, 'destroy']);
        Route::post('/{id}/submit', [ExchangeOrderController::class, 'submit']);
        Route::post('/{id}/approve', [ExchangeOrderController::class, 'approve']);
        Route::post('/{id}/reject', [ExchangeOrderController::class, 'reject']);
        Route::post('/{id}/cancel', [ExchangeOrderController::class, 'cancel']);
    });

    // 换货汇总
    Route::prefix('business/exchange-summary')->group(function () {
        Route::get('/', [ExchangeSummaryController::class, 'index']);
        Route::get('/reason-distribution', [ExchangeSummaryController::class, 'reasonDistribution']);
        Route::get('/product-rank', [ExchangeSummaryController::class, 'productRank']);
        Route::get('/customer-detail', [ExchangeSummaryController::class, 'customerDetail']);
    });
});
