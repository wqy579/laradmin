<?php

use Illuminate\Support\Facades\Route;
use Modules\VanSales\Http\Controllers\VanPickingController;
use Modules\VanSales\Http\Controllers\VanRequisitionController;
use Modules\VanSales\Http\Controllers\VanSaleOrderController;
use Modules\VanSales\Http\Controllers\VanStockController;

/**
 * 车销业务模块路由（VanSales）
 *
 * URL 前缀 business/van-xxx，与现有 business/* 历史前缀对齐。
 * 静态路由（export/batch-approve/warehouse-products/vehicle-products）必须声明在 /{id} 之前，
 * 否则被路由参数吞掉（与 stock-adjust/purchase-application 模块一致）。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 要货申请
    Route::prefix('business/van-requisition')->group(function () {
        Route::get('/warehouse-products', [VanRequisitionController::class, 'warehouseProducts']);
        Route::get('/export', [VanRequisitionController::class, 'export']);
        Route::get('/', [VanRequisitionController::class, 'index']);
        Route::post('/', [VanRequisitionController::class, 'store']);
        Route::get('/{id}', [VanRequisitionController::class, 'show']);
        Route::put('/{id}', [VanRequisitionController::class, 'update']);
        Route::delete('/{id}', [VanRequisitionController::class, 'destroy']);
        Route::post('/{id}/submit', [VanRequisitionController::class, 'submit']);
        Route::post('/{id}/approve', [VanRequisitionController::class, 'approve']);
        Route::post('/{id}/reject', [VanRequisitionController::class, 'reject']);
        Route::post('/{id}/cancel', [VanRequisitionController::class, 'cancel']);
    });

    // 拣货（含验货）
    Route::prefix('business/van-picking')->group(function () {
        Route::get('/export', [VanPickingController::class, 'export']);
        Route::get('/', [VanPickingController::class, 'index']);
        Route::post('/', [VanPickingController::class, 'store']);
        Route::get('/{id}', [VanPickingController::class, 'show']);
        Route::put('/{id}', [VanPickingController::class, 'update']);
        Route::delete('/{id}', [VanPickingController::class, 'destroy']);
        Route::post('/{id}/submit', [VanPickingController::class, 'submit']);
        Route::post('/{id}/approve', [VanPickingController::class, 'approve']);
        Route::post('/{id}/check', [VanPickingController::class, 'check']);
        Route::post('/{id}/cancel', [VanPickingController::class, 'cancel']);
    });

    // 车销销售单
    Route::prefix('business/van-sale-order')->group(function () {
        Route::get('/vehicle-products', [VanSaleOrderController::class, 'vehicleProducts']);
        Route::get('/export', [VanSaleOrderController::class, 'export']);
        Route::get('/', [VanSaleOrderController::class, 'index']);
        Route::post('/', [VanSaleOrderController::class, 'store']);
        Route::get('/{id}', [VanSaleOrderController::class, 'show']);
        Route::put('/{id}', [VanSaleOrderController::class, 'update']);
        Route::delete('/{id}', [VanSaleOrderController::class, 'destroy']);
        Route::post('/{id}/approve', [VanSaleOrderController::class, 'approve']);
        Route::post('/{id}/cancel', [VanSaleOrderController::class, 'cancel']);
    });

    // 车上库存管理
    Route::prefix('business/van-stock')->group(function () {
        Route::get('/', [VanStockController::class, 'index']);
        Route::get('/history', [VanStockController::class, 'history']);
        Route::get('/warning', [VanStockController::class, 'warning']);
    });
});
