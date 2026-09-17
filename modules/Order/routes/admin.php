<?php

use Illuminate\Support\Facades\Route;

// =============================================================================
// 订单模块（Order）路由
//
// 领域边界：客户 / 供应商 / 线路 / 销售订单 / 采购订单 / 退货 / 发货 /
//           拜访 / 收款 / 付款
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Order 模块自己的路由。
//
// ⚠️ URL 前缀仍为 business/*（历史遗留，前端按此前缀调用）。模块化拆分只改后端归属，
// 不动 URL，避免破坏 frontend 已有的接口调用。要改 URL 需前后端同步发版。
// =============================================================================

// 收款/付款/费用此前漏在鉴权组之外，现一并纳入。
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 客户
    Route::prefix('business/customers')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\CustomerController::class, 'index']);
        Route::get('/{customer}', [\Modules\Order\Http\Controllers\CustomerController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\CustomerController::class, 'store']);
        Route::put('/{customer}', [\Modules\Order\Http\Controllers\CustomerController::class, 'update']);
        Route::delete('/{customer}', [\Modules\Order\Http\Controllers\CustomerController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Order\Http\Controllers\CustomerController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Order\Http\Controllers\CustomerController::class, 'batchUpdateStatus']);
    });
    // 供应商
    Route::prefix('business/supplier')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\SupplierController::class, 'index']);
        Route::get('/{supplier}', [\Modules\Order\Http\Controllers\SupplierController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\SupplierController::class, 'store']);
        Route::put('/{supplier}', [\Modules\Order\Http\Controllers\SupplierController::class, 'update']);
        Route::delete('/{supplier}', [\Modules\Order\Http\Controllers\SupplierController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Order\Http\Controllers\SupplierController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Order\Http\Controllers\SupplierController::class, 'batchUpdateStatus']);
    });
    // 线路
    Route::prefix('business/route')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\RouteController::class, 'index']);
        Route::get('/{route}', [\Modules\Order\Http\Controllers\RouteController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\RouteController::class, 'store']);
        Route::put('/{route}', [\Modules\Order\Http\Controllers\RouteController::class, 'update']);
        Route::delete('/{route}', [\Modules\Order\Http\Controllers\RouteController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Order\Http\Controllers\RouteController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Order\Http\Controllers\RouteController::class, 'batchUpdateStatus']);
        Route::get('/{route}/customers', [\Modules\Order\Http\Controllers\RouteController::class, 'customers']);
        Route::post('/{route}/customers', [\Modules\Order\Http\Controllers\RouteController::class, 'addCustomer']);
        Route::delete('/{route}/customers', [\Modules\Order\Http\Controllers\RouteController::class, 'removeCustomer']);
    });
    // 销售订单
    Route::prefix('business/sales-order')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\SalesOrderController::class, 'index']);
        Route::get('/statistics', [\Modules\Order\Http\Controllers\SalesOrderController::class, 'statistics']);
        Route::get('/{salesOrder}', [\Modules\Order\Http\Controllers\SalesOrderController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\SalesOrderController::class, 'store']);
        Route::put('/{salesOrder}', [\Modules\Order\Http\Controllers\SalesOrderController::class, 'update']);
        Route::delete('/{salesOrder}', [\Modules\Order\Http\Controllers\SalesOrderController::class, 'destroy']);
        Route::post('/{salesOrder}/approve', [\Modules\Order\Http\Controllers\SalesOrderController::class, 'approve']);
    });
    // 采购订单
    Route::prefix('business/purchase-order')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'index']);
        Route::get('/statistics', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'statistics']);
        Route::get('/{purchaseOrder}', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'store']);
        Route::put('/{purchaseOrder}', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'update']);
        Route::delete('/{purchaseOrder}', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'destroy']);
        Route::post('/{purchaseOrder}/approve', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'approve']);
        Route::post('/{purchaseOrder}/receive', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'receive']);
        Route::post('/{purchaseOrder}/cancel', [\Modules\Order\Http\Controllers\PurchaseOrderController::class, 'cancel']);
    });
    // 退货管理
    Route::prefix('business/return')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\ReturnController::class, 'index']);
        Route::get('/statistics', [\Modules\Order\Http\Controllers\ReturnController::class, 'statistics']);
        Route::get('/{return}', [\Modules\Order\Http\Controllers\ReturnController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\ReturnController::class, 'store']);
        Route::put('/{return}', [\Modules\Order\Http\Controllers\ReturnController::class, 'update']);
        Route::delete('/{return}', [\Modules\Order\Http\Controllers\ReturnController::class, 'destroy']);
        Route::post('/{return}/approve', [\Modules\Order\Http\Controllers\ReturnController::class, 'approve']);
        Route::post('/{return}/process', [\Modules\Order\Http\Controllers\ReturnController::class, 'process']);
    });
    // 发货管理
    Route::prefix('business/delivery')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\DeliveryController::class, 'index']);
        Route::get('/statistics', [\Modules\Order\Http\Controllers\DeliveryController::class, 'statistics']);
        Route::get('/{delivery}', [\Modules\Order\Http\Controllers\DeliveryController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\DeliveryController::class, 'store']);
        Route::put('/{delivery}', [\Modules\Order\Http\Controllers\DeliveryController::class, 'update']);
        Route::delete('/{delivery}', [\Modules\Order\Http\Controllers\DeliveryController::class, 'destroy']);
        Route::post('/{delivery}/dispatch', [\Modules\Order\Http\Controllers\DeliveryController::class, 'dispatch']);
        Route::post('/{delivery}/complete', [\Modules\Order\Http\Controllers\DeliveryController::class, 'complete']);
    });
    // 拜访管理
    Route::prefix('business/visit')->group(function () {
        Route::get('/logs', [\Modules\Order\Http\Controllers\VisitLogController::class, 'index']);
        Route::get('/logs/{visitLog}', [\Modules\Order\Http\Controllers\VisitLogController::class, 'show']);
        Route::post('/logs', [\Modules\Order\Http\Controllers\VisitLogController::class, 'store']);
        Route::put('/logs/{visitLog}', [\Modules\Order\Http\Controllers\VisitLogController::class, 'update']);
        Route::delete('/logs/{visitLog}', [\Modules\Order\Http\Controllers\VisitLogController::class, 'destroy']);
        Route::get('/achievement', [\Modules\Order\Http\Controllers\VisitLogController::class, 'achievement']);
        Route::get('/schedule', [\Modules\Order\Http\Controllers\VisitLogController::class, 'schedule']);
    });
    // 收款管理
    Route::prefix('business/receive')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\ReceiveController::class, 'index']);
        Route::get('/statistics', [\Modules\Order\Http\Controllers\ReceiveController::class, 'statistics']);
        Route::get('/{id}', [\Modules\Order\Http\Controllers\ReceiveController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\ReceiveController::class, 'store']);
        Route::put('/{id}', [\Modules\Order\Http\Controllers\ReceiveController::class, 'update']);
        Route::post('/{id}/approve', [\Modules\Order\Http\Controllers\ReceiveController::class, 'approve']);
        Route::delete('/{id}', [\Modules\Order\Http\Controllers\ReceiveController::class, 'destroy']);
    });
    // 付款管理
    Route::prefix('business/pay')->group(function () {
        Route::get('/', [\Modules\Order\Http\Controllers\PayController::class, 'index']);
        Route::get('/statistics', [\Modules\Order\Http\Controllers\PayController::class, 'statistics']);
        Route::get('/{id}', [\Modules\Order\Http\Controllers\PayController::class, 'show']);
        Route::post('/', [\Modules\Order\Http\Controllers\PayController::class, 'store']);
        Route::put('/{id}', [\Modules\Order\Http\Controllers\PayController::class, 'update']);
        Route::post('/{id}/approve', [\Modules\Order\Http\Controllers\PayController::class, 'approve']);
        Route::delete('/{id}', [\Modules\Order\Http\Controllers\PayController::class, 'destroy']);
    });
});
