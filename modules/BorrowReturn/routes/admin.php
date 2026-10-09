<?php

use Illuminate\Support\Facades\Route;
use Modules\BorrowReturn\Http\Controllers\BorrowOrderController;
use Modules\BorrowReturn\Http\Controllers\BorrowReturnOrderController;
use Modules\BorrowReturn\Http\Controllers\BorrowSummaryController;

/**
 * 借还货管理模块路由（BorrowReturn）
 *
 * 挂在库存管理下：借货单 / 还货单 / 借货汇总。URL 前缀 business/borrow-*。
 * 静态路由须声明在 /{id} 之前，避免被路由参数吞掉。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 借货单
    Route::prefix('business/borrow-order')->group(function () {
        Route::get('/warehouse-products', [BorrowOrderController::class, 'warehouseProducts']);
        Route::get('/export', [BorrowOrderController::class, 'export']);
        Route::get('/', [BorrowOrderController::class, 'index']);
        Route::post('/', [BorrowOrderController::class, 'store']);
        Route::get('/{id}', [BorrowOrderController::class, 'show']);
        Route::put('/{id}', [BorrowOrderController::class, 'update']);
        Route::delete('/{id}', [BorrowOrderController::class, 'destroy']);
        Route::post('/{id}/confirm', [BorrowOrderController::class, 'confirm']);
        Route::post('/{id}/cancel', [BorrowOrderController::class, 'cancel']);
        Route::post('/{id}/convert', [BorrowOrderController::class, 'convert']);
    });

    // 还货单
    Route::prefix('business/return-order')->group(function () {
        Route::get('/pending-borrow-items', [BorrowReturnOrderController::class, 'pendingBorrowItems']);
        Route::get('/export', [BorrowReturnOrderController::class, 'export']);
        Route::get('/', [BorrowReturnOrderController::class, 'index']);
        Route::post('/', [BorrowReturnOrderController::class, 'store']);
        Route::get('/{id}', [BorrowReturnOrderController::class, 'show']);
        Route::put('/{id}', [BorrowReturnOrderController::class, 'update']);
        Route::delete('/{id}', [BorrowReturnOrderController::class, 'destroy']);
        Route::post('/{id}/approve', [BorrowReturnOrderController::class, 'approve']);
        Route::post('/{id}/cancel', [BorrowReturnOrderController::class, 'cancel']);
    });

    // 借货汇总
    Route::prefix('business/borrow-summary')->group(function () {
        Route::get('/', [BorrowSummaryController::class, 'index']);
        Route::get('/customer-detail', [BorrowSummaryController::class, 'customerDetail']);
        Route::get('/trend', [BorrowSummaryController::class, 'trend']);
    });
});
