<?php

use Illuminate\Support\Facades\Route;
use Modules\Stock\Http\Controllers\CostPriceController;
use Modules\Stock\Http\Controllers\CustomerLevelController;
use Modules\Stock\Http\Controllers\ProductController;
use Modules\Stock\Http\Controllers\ProductPriceController;
use Modules\Stock\Http\Controllers\StockCheckController;
use Modules\Stock\Http\Controllers\StockController;
use Modules\Stock\Http\Controllers\StockHistoryController;
use Modules\Stock\Http\Controllers\StockInController;
use Modules\Stock\Http\Controllers\StockOutController;
use Modules\Stock\Http\Controllers\StockAdjustController;
use Modules\Stock\Http\Controllers\StocktakingController;
use Modules\Stock\Http\Controllers\TransferController;
use Modules\Stock\Http\Controllers\VehicleController;
use Modules\Stock\Http\Controllers\WarehouseController;

// =============================================================================
// 库存模块（Stock）路由
//
// 领域边界：商品档案 / 单位 / 仓库 / 车辆 / 库存查询 / 出入库 / 调拨 /
//           成本价格 / 库存核对与监控
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
        Route::get('/units', [ProductController::class, 'units']);
        Route::get('/categories', [ProductController::class, 'categories']);
        Route::get('/', [ProductController::class, 'index']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('/{product}', [ProductController::class, 'show']);
        Route::put('/{product}', [ProductController::class, 'update']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
        Route::post('/batch-delete', [ProductController::class, 'batchDelete']);
        Route::post('/batch-status', [ProductController::class, 'batchUpdateStatus']);
        Route::post('/categories', [ProductController::class, 'storeCategory']);
        Route::put('/categories/{category}', [ProductController::class, 'updateCategory']);
        Route::delete('/categories/{category}', [ProductController::class, 'destroyCategory']);
    });
    // 仓库
    Route::prefix('business/warehouse')->group(function () {
        Route::get('/', [WarehouseController::class, 'index']);
        Route::get('/{warehouse}', [WarehouseController::class, 'show']);
        Route::post('/', [WarehouseController::class, 'store']);
        Route::put('/{warehouse}', [WarehouseController::class, 'update']);
        Route::delete('/{warehouse}', [WarehouseController::class, 'destroy']);
    });
    // 车辆（订单发货/销售单引用，随库存模块归属）
    Route::prefix('business/vehicle')->group(function () {
        Route::get('/', [VehicleController::class, 'index']);
        Route::get('/{vehicle}', [VehicleController::class, 'show']);
        Route::post('/', [VehicleController::class, 'store']);
        Route::put('/{vehicle}', [VehicleController::class, 'update']);
        Route::delete('/{vehicle}', [VehicleController::class, 'destroy']);
        Route::post('/batch-delete', [VehicleController::class, 'batchDelete']);
        Route::post('/batch-status', [VehicleController::class, 'batchUpdateStatus']);
    });
    // 调拨管理
    Route::prefix('business/transfer')->group(function () {
        Route::get('/', [TransferController::class, 'index']);
        Route::get('/statistics', [TransferController::class, 'statistics']);
        Route::get('/{transfer}', [TransferController::class, 'show']);
        Route::post('/', [TransferController::class, 'store']);
        Route::put('/{transfer}', [TransferController::class, 'update']);
        Route::delete('/{transfer}', [TransferController::class, 'destroy']);
        Route::post('/{transfer}/approve', [TransferController::class, 'approve']);
        Route::post('/{transfer}/execute', [TransferController::class, 'execute']);
    });
    // 成本价格（只依赖商品与库存表，随库存模块归属）
    Route::prefix('business/cost-price')->group(function () {
        Route::get('/', [CostPriceController::class, 'index']);
        Route::post('/batch', [CostPriceController::class, 'batchUpdate']);
        Route::put('/{product}', [CostPriceController::class, 'update']);
    });

    // 客户等级
    Route::prefix('business/customer-level')->group(function () {
        Route::get('/', [CustomerLevelController::class, 'index']);
        Route::post('/', [CustomerLevelController::class, 'store']);
        Route::put('/{id}', [CustomerLevelController::class, 'update']);
        Route::delete('/{id}', [CustomerLevelController::class, 'destroy']);
    });

    // 商品价格（静态路由先于 {productId}）
    Route::get('business/product-price/history', [ProductPriceController::class, 'history']);
    Route::post('business/product-price/calculate', [ProductPriceController::class, 'calculate']);
    Route::post('business/product-price/batch', [ProductPriceController::class, 'batch']);
    Route::prefix('business/product-price')->group(function () {
        Route::get('/', [ProductPriceController::class, 'index']);
        Route::put('/{productId}', [ProductPriceController::class, 'save']);
    });
    // 库存核对与监控
    Route::get('business/stock-monitor', [StockCheckController::class, 'monitor']);
    Route::get('business/stock-check', [StockCheckController::class, 'index']);
    Route::get('business/stock-history', [StockHistoryController::class, 'index']);
    // 库存查询
    Route::prefix('business/stock')->group(function () {
        Route::get('/', [StockController::class, 'index']);
        Route::get('/statistics', [StockController::class, 'statistics']);
        Route::get('/{stock}', [StockController::class, 'show']);
    });
    // 入库单
    Route::prefix('business/stock-in')->group(function () {
        Route::get('/', [StockInController::class, 'index']);
        Route::post('/', [StockInController::class, 'store']);
    });
    // 出库单
    Route::prefix('business/stock-out')->group(function () {
        Route::get('/', [StockOutController::class, 'index']);
        Route::post('/', [StockOutController::class, 'store']);
    });

    // 库存调整单（库存调整单全流程：建单→提交→审核调库存+财务凭证）
    // 与库存盘点（stocktaking）的区别：调整单直接录入调整数量和原因，流程更轻量
    Route::get('business/stock-adjust/warehouse-products', [StockAdjustController::class, 'warehouseProducts']);
    Route::prefix('business/stock-adjust')->group(function () {
        Route::get('/', [StockAdjustController::class, 'index']);
        Route::post('/', [StockAdjustController::class, 'store']);
        Route::get('/{id}', [StockAdjustController::class, 'show']);
        Route::put('/{id}', [StockAdjustController::class, 'update']);
        Route::delete('/{id}', [StockAdjustController::class, 'destroy']);
        Route::post('/{id}/submit', [StockAdjustController::class, 'submit']);
        Route::post('/{id}/approve', [StockAdjustController::class, 'approve']);
        Route::post('/{id}/reject', [StockAdjustController::class, 'reject']);
        Route::post('/{id}/cancel', [StockAdjustController::class, 'cancel']);
    });

    // 库存盘点（盘点单全流程：建单→录实盘→提交→审核调库存+财务凭证）
    // 注意与上方只读「库存核对」business/stock-check 不是一回事。
    // 静态路由必须声明在 {id} 之前，否则会被路由参数吞掉。
    Route::get('business/stocktaking/warehouse-products', [StocktakingController::class, 'warehouseProducts']);
    Route::get('business/stocktaking/ledger', [StocktakingController::class, 'ledger']);
    Route::prefix('business/stocktaking')->group(function () {
        Route::get('/', [StocktakingController::class, 'index']);
        Route::post('/', [StocktakingController::class, 'store']);
        Route::get('/{id}', [StocktakingController::class, 'show']);
        Route::put('/{id}', [StocktakingController::class, 'update']);
        Route::delete('/{id}', [StocktakingController::class, 'destroy']);
        Route::post('/{id}/submit', [StocktakingController::class, 'submit']);
        Route::post('/{id}/approve', [StocktakingController::class, 'approve']);
        Route::post('/{id}/reject', [StocktakingController::class, 'reject']);
        Route::post('/{id}/cancel', [StocktakingController::class, 'cancel']);
    });
});
