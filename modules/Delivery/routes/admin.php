<?php

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\CheckController;
use Modules\Delivery\Http\Controllers\CollectionController;
use Modules\Delivery\Http\Controllers\LoadController;
use Modules\Delivery\Http\Controllers\PickController;
use Modules\Delivery\Http\Controllers\PickingController;
use Modules\Delivery\Http\Controllers\RemitController;
use Modules\Delivery\Http\Controllers\TaskController;

/**
 * 配送管理模块路由。
 *
 * 信封由 bootstrap/app.php 统一套：api + stock.snapshot 中间件 + /admin 前缀 + admin. 命名。
 * URL 前缀沿用 business/* 惯例，使用 delivery-* 子前缀避免与历史 deliveries 路由冲突。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 配货单
    Route::prefix('business/delivery-picking')->name('admin.delivery.picking.')->group(function () {
        Route::get('/pending-orders', [PickingController::class, 'pendingOrders']);
        Route::get('/', [PickingController::class, 'index']);
        Route::get('/{id}', [PickingController::class, 'show'])->whereNumber('id');
        Route::post('/', [PickingController::class, 'store']);
        Route::put('/{id}', [PickingController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PickingController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/confirm', [PickingController::class, 'confirm'])->whereNumber('id');
        Route::post('/{id}/cancel', [PickingController::class, 'cancel'])->whereNumber('id');
    });

    // 拣货单
    Route::prefix('business/delivery-pick')->name('admin.delivery.pick.')->group(function () {
        Route::get('/', [PickController::class, 'index']);
        Route::get('/{id}', [PickController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/start', [PickController::class, 'start'])->whereNumber('id');
        Route::post('/{id}/confirm', [PickController::class, 'confirm'])->whereNumber('id');
        Route::post('/{id}/cancel', [PickController::class, 'cancel'])->whereNumber('id');
    });

    // 验货单
    Route::prefix('business/delivery-check')->name('admin.delivery.check.')->group(function () {
        Route::get('/unchecked', [CheckController::class, 'unchecked']);
        Route::get('/', [CheckController::class, 'index']);
        Route::get('/{id}', [CheckController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/start', [CheckController::class, 'start'])->whereNumber('id');
        Route::post('/{id}/confirm', [CheckController::class, 'confirm'])->whereNumber('id');
    });

    // 装车单
    Route::prefix('business/delivery-load')->name('admin.delivery.load.')->group(function () {
        Route::get('/', [LoadController::class, 'index']);
        Route::get('/{id}', [LoadController::class, 'show'])->whereNumber('id');
        Route::post('/', [LoadController::class, 'store']);
        Route::post('/{id}/confirm', [LoadController::class, 'confirm'])->whereNumber('id');
        Route::delete('/{id}', [LoadController::class, 'destroy'])->whereNumber('id');
    });

    // 配送任务
    Route::prefix('business/delivery-task')->name('admin.delivery.task.')->group(function () {
        Route::get('/', [TaskController::class, 'index']);
        Route::get('/{id}', [TaskController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/start', [TaskController::class, 'start'])->whereNumber('id');
        Route::post('/{id}/deliver', [TaskController::class, 'deliver'])->whereNumber('id');
        Route::post('/{id}/exception', [TaskController::class, 'exception'])->whereNumber('id');
        Route::post('/{id}/cancel', [TaskController::class, 'cancel'])->whereNumber('id');
    });

    // 配送收款
    Route::prefix('business/delivery-collection')->name('admin.delivery.collection.')->group(function () {
        Route::get('/pending-tasks', [CollectionController::class, 'pendingTasks']);
        Route::get('/', [CollectionController::class, 'index']);
        Route::get('/{id}', [CollectionController::class, 'show'])->whereNumber('id');
        Route::post('/', [CollectionController::class, 'store']);
    });

    // 上交货款
    Route::prefix('business/delivery-remit')->name('admin.delivery.remit.')->group(function () {
        Route::get('/unremit-summary', [RemitController::class, 'unremitSummary']);
        Route::get('/', [RemitController::class, 'index']);
        Route::get('/{id}', [RemitController::class, 'show'])->whereNumber('id');
        Route::post('/', [RemitController::class, 'store']);
        Route::post('/{id}/confirm', [RemitController::class, 'confirm'])->whereNumber('id');
    });
});
