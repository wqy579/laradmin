<?php

use Illuminate\Support\Facades\Route;
use Modules\Mall\Http\Controllers\MallOrderController;
use Modules\Mall\Http\Controllers\MallProductController;
use Modules\Mall\Http\Controllers\MapController;
use Modules\Mall\Http\Controllers\SmsController;

/**
 * F5 分销增值模块路由 —— 行程地图 + 分销商城 + 短信。
 *
 * 统一前缀 /admin/f5，鉴权信封同其他模块（auth.check:admin + log.request）。
 * 静态段（/salesmen、/customers、/options、/preview）一律声明在 /{id} 之前。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // —— 行程地图 ——
    Route::prefix('f5/map')->group(function () {
        Route::get('/salesmen', [MapController::class, 'salesmen']);
        Route::get('/trajectory', [MapController::class, 'trajectory']);
        Route::get('/customers', [MapController::class, 'customers']);
    });

    // —— 分销商城 · 商品 ——
    Route::prefix('f5/mall/products')->group(function () {
        Route::get('/', [MallProductController::class, 'index']);
        Route::post('/', [MallProductController::class, 'store']);
        Route::get('/options', [MallProductController::class, 'options']);
        Route::put('/{id}', [MallProductController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [MallProductController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/toggle', [MallProductController::class, 'toggle'])->whereNumber('id');
        Route::post('/sync-price', [MallProductController::class, 'syncPrice']);
    });

    // —— 分销商城 · 前台目录 ——
    Route::prefix('f5/mall/shop')->group(function () {
        Route::get('/catalog', [MallProductController::class, 'catalog']);
        Route::get('/customers', [MallProductController::class, 'shopCustomers']);
    });

    // —— 分销商城 · 订单 ——
    Route::prefix('f5/mall/orders')->group(function () {
        Route::get('/', [MallOrderController::class, 'index']);
        Route::post('/', [MallOrderController::class, 'store']);
        Route::get('/warehouses', [MallOrderController::class, 'warehouses']);
        Route::get('/{id}', [MallOrderController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/accept', [MallOrderController::class, 'accept'])->whereNumber('id');
        Route::post('/{id}/reject', [MallOrderController::class, 'reject'])->whereNumber('id');
        Route::post('/{id}/cancel', [MallOrderController::class, 'cancel'])->whereNumber('id');
    });

    // —— 短信 ——
    Route::prefix('f5/sms')->group(function () {
        Route::get('/templates', [SmsController::class, 'index']);
        Route::post('/templates', [SmsController::class, 'store']);
        Route::put('/templates/{id}', [SmsController::class, 'update'])->whereNumber('id');
        Route::delete('/templates/{id}', [SmsController::class, 'destroy'])->whereNumber('id');
        Route::post('/templates/{id}/toggle', [SmsController::class, 'toggle'])->whereNumber('id');
        Route::post('/preview', [SmsController::class, 'preview']);

        Route::get('/records', [SmsController::class, 'records']);
        Route::post('/records/send', [SmsController::class, 'send']);
        Route::post('/records/{id}/resend', [SmsController::class, 'resend'])->whereNumber('id');
    });
});
