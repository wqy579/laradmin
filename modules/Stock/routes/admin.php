<?php

use Illuminate\Support\Facades\Route;

// =============================================================================
// 库存模块（Stock）路由
//
// 领域边界：商品档案 / 单位 / 仓库 / 车辆 / 库存查询 / 出入库 / 调拨 /
//           成本价格 / 连凯库存监控与核对
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Stock 模块自己的路由。
//
// ⚠️ URL 前缀仍为 business/*（历史遗留，前端按此前缀调用）。模块化拆分只改后端归属，
// 不动 URL，避免破坏 frontend 已有的接口调用。要改 URL 需前后端同步发版。
// 路由归属变更请用 tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// =============================================================================

// 收款/付款/费用此前漏在鉴权组之外，现一并纳入。
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 商品档案
    Route::prefix('business/product')->group(function () {
        Route::get('/units', [\Modules\Stock\Http\Controllers\ProductController::class, 'units']);
        Route::get('/categories', [\Modules\Stock\Http\Controllers\ProductController::class, 'categories']);
        Route::get('/', [\Modules\Stock\Http\Controllers\ProductController::class, 'index']);
        Route::post('/', [\Modules\Stock\Http\Controllers\ProductController::class, 'store']);
        Route::get('/{product}', [\Modules\Stock\Http\Controllers\ProductController::class, 'show']);
        Route::put('/{product}', [\Modules\Stock\Http\Controllers\ProductController::class, 'update']);
        Route::delete('/{product}', [\Modules\Stock\Http\Controllers\ProductController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Stock\Http\Controllers\ProductController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Stock\Http\Controllers\ProductController::class, 'batchUpdateStatus']);
        Route::post('/categories', [\Modules\Stock\Http\Controllers\ProductController::class, 'storeCategory']);
        Route::put('/categories/{category}', [\Modules\Stock\Http\Controllers\ProductController::class, 'updateCategory']);
        Route::delete('/categories/{category}', [\Modules\Stock\Http\Controllers\ProductController::class, 'destroyCategory']);
    });
    // 仓库
    Route::prefix('business/warehouse')->group(function () {
        Route::get('/', [\Modules\Stock\Http\Controllers\WarehouseController::class, 'index']);
        Route::get('/{warehouse}', [\Modules\Stock\Http\Controllers\WarehouseController::class, 'show']);
        Route::post('/', [\Modules\Stock\Http\Controllers\WarehouseController::class, 'store']);
        Route::put('/{warehouse}', [\Modules\Stock\Http\Controllers\WarehouseController::class, 'update']);
        Route::delete('/{warehouse}', [\Modules\Stock\Http\Controllers\WarehouseController::class, 'destroy']);
    });
    // 车辆（仅连凯库存核对引用，随库存模块归属）
    Route::prefix('business/vehicle')->group(function () {
        Route::get('/', [\Modules\Stock\Http\Controllers\VehicleController::class, 'index']);
        Route::get('/{vehicle}', [\Modules\Stock\Http\Controllers\VehicleController::class, 'show']);
        Route::post('/', [\Modules\Stock\Http\Controllers\VehicleController::class, 'store']);
        Route::put('/{vehicle}', [\Modules\Stock\Http\Controllers\VehicleController::class, 'update']);
        Route::delete('/{vehicle}', [\Modules\Stock\Http\Controllers\VehicleController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Stock\Http\Controllers\VehicleController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Stock\Http\Controllers\VehicleController::class, 'batchUpdateStatus']);
    });
    // 调拨管理
    Route::prefix('business/transfer')->group(function () {
        Route::get('/', [\Modules\Stock\Http\Controllers\TransferController::class, 'index']);
        Route::get('/statistics', [\Modules\Stock\Http\Controllers\TransferController::class, 'statistics']);
        Route::get('/{transfer}', [\Modules\Stock\Http\Controllers\TransferController::class, 'show']);
        Route::post('/', [\Modules\Stock\Http\Controllers\TransferController::class, 'store']);
        Route::put('/{transfer}', [\Modules\Stock\Http\Controllers\TransferController::class, 'update']);
        Route::delete('/{transfer}', [\Modules\Stock\Http\Controllers\TransferController::class, 'destroy']);
        Route::post('/{transfer}/approve', [\Modules\Stock\Http\Controllers\TransferController::class, 'approve']);
        Route::post('/{transfer}/execute', [\Modules\Stock\Http\Controllers\TransferController::class, 'execute']);
    });
    // 成本价格（只依赖商品与库存表，随库存模块归属）
    Route::prefix('business/cost-price')->group(function () {
        Route::get('/', [\Modules\Stock\Http\Controllers\CostPriceController::class, 'index']);
        Route::post('/batch', [\Modules\Stock\Http\Controllers\CostPriceController::class, 'batchUpdate']);
        Route::put('/{product}', [\Modules\Stock\Http\Controllers\CostPriceController::class, 'update']);
    });
    // 连凯库存监控（监控库存变动）
    Route::get('business/liankai-stock-monitor', [\Modules\Stock\Http\Controllers\LiankaiStockCheckController::class, 'monitor']);
    Route::get('business/liankai-stock-check', [\Modules\Stock\Http\Controllers\LiankaiStockCheckController::class, 'index']);
    Route::post('business/liankai-stock-check/sync', [\Modules\Stock\Http\Controllers\LiankaiStockCheckController::class, 'sync']);
    Route::post('business/liankai-stock-monitor/sync', [\Modules\Stock\Http\Controllers\LiankaiStockCheckController::class, 'sync']);
    // 库存查询
    Route::prefix('business/stock')->group(function () {
        Route::get('/', [\Modules\Stock\Http\Controllers\StockController::class, 'index']);
        Route::get('/statistics', [\Modules\Stock\Http\Controllers\StockController::class, 'statistics']);
        Route::get('/{stock}', [\Modules\Stock\Http\Controllers\StockController::class, 'show']);
    });
    // 入库单
    Route::prefix('business/stock-in')->group(function () {
        Route::get('/', [\Modules\Stock\Http\Controllers\StockInController::class, 'index']);
        Route::post('/', [\Modules\Stock\Http\Controllers\StockInController::class, 'store']);
    });
    // 出库单
    Route::prefix('business/stock-out')->group(function () {
        Route::get('/', [\Modules\Stock\Http\Controllers\StockOutController::class, 'index']);
        Route::post('/', [\Modules\Stock\Http\Controllers\StockOutController::class, 'store']);
    });
});
