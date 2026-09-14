<?php

use Illuminate\Support\Facades\Route;

// =============================================================================
// 业务模块（Business）路由
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Business 模块自己的路由。路由归属变更请用
// tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// =============================================================================

// 收款/付款/费用此前漏在鉴权组之外，现一并纳入。
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 业务模块：产品
    Route::prefix('business/product')->group(function () {
        Route::get('/units', [\Modules\Business\Http\Controllers\ProductController::class, 'units']);
        Route::get('/categories', [\Modules\Business\Http\Controllers\ProductController::class, 'categories']);
        Route::get('/', [\Modules\Business\Http\Controllers\ProductController::class, 'index']);
        Route::post('/', [\Modules\Business\Http\Controllers\ProductController::class, 'store']);
        Route::get('/{product}', [\Modules\Business\Http\Controllers\ProductController::class, 'show']);
        Route::put('/{product}', [\Modules\Business\Http\Controllers\ProductController::class, 'update']);
        Route::delete('/{product}', [\Modules\Business\Http\Controllers\ProductController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\ProductController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\ProductController::class, 'batchUpdateStatus']);
        Route::post('/categories', [\Modules\Business\Http\Controllers\ProductController::class, 'storeCategory']);
        Route::put('/categories/{category}', [\Modules\Business\Http\Controllers\ProductController::class, 'updateCategory']);
        Route::delete('/categories/{category}', [\Modules\Business\Http\Controllers\ProductController::class, 'destroyCategory']);
    });
    // 业务模块：客户
    Route::prefix('business/customers')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\CustomerController::class, 'index']);
        Route::get('/{customer}', [\Modules\Business\Http\Controllers\CustomerController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\CustomerController::class, 'store']);
        Route::put('/{customer}', [\Modules\Business\Http\Controllers\CustomerController::class, 'update']);
        Route::delete('/{customer}', [\Modules\Business\Http\Controllers\CustomerController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\CustomerController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\CustomerController::class, 'batchUpdateStatus']);
    });
    // 业务模块：员工
    Route::prefix('business/employee')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\EmployeeController::class, 'index']);
        Route::get('/{employee}', [\Modules\Business\Http\Controllers\EmployeeController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\EmployeeController::class, 'store']);
        Route::put('/{employee}', [\Modules\Business\Http\Controllers\EmployeeController::class, 'update']);
        Route::delete('/{employee}', [\Modules\Business\Http\Controllers\EmployeeController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\EmployeeController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\EmployeeController::class, 'batchUpdateStatus']);
    });
    // 业务模块：仓库
    Route::prefix('business/warehouse')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\WarehouseController::class, 'index']);
        Route::get('/{warehouse}', [\Modules\Business\Http\Controllers\WarehouseController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\WarehouseController::class, 'store']);
        Route::put('/{warehouse}', [\Modules\Business\Http\Controllers\WarehouseController::class, 'update']);
        Route::delete('/{warehouse}', [\Modules\Business\Http\Controllers\WarehouseController::class, 'destroy']);
    });
    // 业务模块：车辆
    Route::prefix('business/vehicle')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\VehicleController::class, 'index']);
        Route::get('/{vehicle}', [\Modules\Business\Http\Controllers\VehicleController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\VehicleController::class, 'store']);
        Route::put('/{vehicle}', [\Modules\Business\Http\Controllers\VehicleController::class, 'update']);
        Route::delete('/{vehicle}', [\Modules\Business\Http\Controllers\VehicleController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\VehicleController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\VehicleController::class, 'batchUpdateStatus']);
    });
    // 业务模块：供应商
    Route::prefix('business/supplier')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\SupplierController::class, 'index']);
        Route::get('/{supplier}', [\Modules\Business\Http\Controllers\SupplierController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\SupplierController::class, 'store']);
        Route::put('/{supplier}', [\Modules\Business\Http\Controllers\SupplierController::class, 'update']);
        Route::delete('/{supplier}', [\Modules\Business\Http\Controllers\SupplierController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\SupplierController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\SupplierController::class, 'batchUpdateStatus']);
    });
    // 业务模块：线路
    Route::prefix('business/route')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\RouteController::class, 'index']);
        Route::get('/{route}', [\Modules\Business\Http\Controllers\RouteController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\RouteController::class, 'store']);
        Route::put('/{route}', [\Modules\Business\Http\Controllers\RouteController::class, 'update']);
        Route::delete('/{route}', [\Modules\Business\Http\Controllers\RouteController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\RouteController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\RouteController::class, 'batchUpdateStatus']);
        Route::get('/{route}/customers', [\Modules\Business\Http\Controllers\RouteController::class, 'customers']);
        Route::post('/{route}/customers', [\Modules\Business\Http\Controllers\RouteController::class, 'addCustomer']);
        Route::delete('/{route}/customers', [\Modules\Business\Http\Controllers\RouteController::class, 'removeCustomer']);
    });
    // 业务模块：销售订单
    Route::prefix('business/sales-order')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\SalesOrderController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\SalesOrderController::class, 'statistics']);
        Route::get('/{salesOrder}', [\Modules\Business\Http\Controllers\SalesOrderController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\SalesOrderController::class, 'store']);
        Route::put('/{salesOrder}', [\Modules\Business\Http\Controllers\SalesOrderController::class, 'update']);
        Route::delete('/{salesOrder}', [\Modules\Business\Http\Controllers\SalesOrderController::class, 'destroy']);
        Route::post('/{salesOrder}/approve', [\Modules\Business\Http\Controllers\SalesOrderController::class, 'approve']);
    });
    // 业务模块：采购订单
    Route::prefix('business/purchase-order')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\PurchaseOrderController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\PurchaseOrderController::class, 'statistics']);
        Route::get('/{purchaseOrder}', [\Modules\Business\Http\Controllers\PurchaseOrderController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\PurchaseOrderController::class, 'store']);
        Route::put('/{purchaseOrder}', [\Modules\Business\Http\Controllers\PurchaseOrderController::class, 'update']);
        Route::delete('/{purchaseOrder}', [\Modules\Business\Http\Controllers\PurchaseOrderController::class, 'destroy']);
        Route::post('/{purchaseOrder}/approve', [\Modules\Business\Http\Controllers\PurchaseOrderController::class, 'approve']);
        Route::post('/{purchaseOrder}/receive', [\Modules\Business\Http\Controllers\PurchaseOrderController::class, 'receive']);
    });
    // 业务模块：调拨管理
    Route::prefix('business/transfer')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\TransferController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\TransferController::class, 'statistics']);
        Route::get('/{transfer}', [\Modules\Business\Http\Controllers\TransferController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\TransferController::class, 'store']);
        Route::put('/{transfer}', [\Modules\Business\Http\Controllers\TransferController::class, 'update']);
        Route::delete('/{transfer}', [\Modules\Business\Http\Controllers\TransferController::class, 'destroy']);
        Route::post('/{transfer}/approve', [\Modules\Business\Http\Controllers\TransferController::class, 'approve']);
        Route::post('/{transfer}/execute', [\Modules\Business\Http\Controllers\TransferController::class, 'execute']);
    });
    // 业务模块：退货管理
    Route::prefix('business/return')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\ReturnController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\ReturnController::class, 'statistics']);
        Route::get('/{return}', [\Modules\Business\Http\Controllers\ReturnController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\ReturnController::class, 'store']);
        Route::put('/{return}', [\Modules\Business\Http\Controllers\ReturnController::class, 'update']);
        Route::delete('/{return}', [\Modules\Business\Http\Controllers\ReturnController::class, 'destroy']);
        Route::post('/{return}/approve', [\Modules\Business\Http\Controllers\ReturnController::class, 'approve']);
        Route::post('/{return}/process', [\Modules\Business\Http\Controllers\ReturnController::class, 'process']);
    });
    // 业务模块：发货管理
    Route::prefix('business/delivery')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\DeliveryController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\DeliveryController::class, 'statistics']);
        Route::get('/{delivery}', [\Modules\Business\Http\Controllers\DeliveryController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\DeliveryController::class, 'store']);
        Route::put('/{delivery}', [\Modules\Business\Http\Controllers\DeliveryController::class, 'update']);
        Route::delete('/{delivery}', [\Modules\Business\Http\Controllers\DeliveryController::class, 'destroy']);
        Route::post('/{delivery}/dispatch', [\Modules\Business\Http\Controllers\DeliveryController::class, 'dispatch']);
        Route::post('/{delivery}/complete', [\Modules\Business\Http\Controllers\DeliveryController::class, 'complete']);
    });
    // 业务模块：成本价格
    Route::prefix('business/cost-price')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\CostPriceController::class, 'index']);
        Route::post('/batch', [\Modules\Business\Http\Controllers\CostPriceController::class, 'batchUpdate']);
        Route::put('/{product}', [\Modules\Business\Http\Controllers\CostPriceController::class, 'update']);
    });
    // 连凯库存监控（监控库存变动）
    Route::get('business/liankai-stock-monitor', [\Modules\Business\Http\Controllers\LiankaiStockCheckController::class, 'monitor']);
    Route::get('business/liankai-stock-check', [\Modules\Business\Http\Controllers\LiankaiStockCheckController::class, 'index']);
    Route::post('business/liankai-stock-check/sync', [\Modules\Business\Http\Controllers\LiankaiStockCheckController::class, 'sync']);
    Route::post('business/liankai-stock-monitor/sync', [\Modules\Business\Http\Controllers\LiankaiStockCheckController::class, 'sync']);
    // 业务模块：库存查询
    Route::prefix('business/stock')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\StockController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\StockController::class, 'statistics']);
        Route::get('/{stock}', [\Modules\Business\Http\Controllers\StockController::class, 'show']);
    });
    // 业务模块：入库单
    Route::prefix('business/stock-in')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\StockInController::class, 'index']);
        Route::post('/', [\Modules\Business\Http\Controllers\StockInController::class, 'store']);
    });
    // 业务模块：出库单
    Route::prefix('business/stock-out')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\StockOutController::class, 'index']);
        Route::post('/', [\Modules\Business\Http\Controllers\StockOutController::class, 'store']);
    });
    // 业务模块：拜访管理
    Route::prefix('business/visit')->group(function () {
        Route::get('/logs', [\Modules\Business\Http\Controllers\VisitLogController::class, 'index']);
        Route::get('/logs/{visitLog}', [\Modules\Business\Http\Controllers\VisitLogController::class, 'show']);
        Route::post('/logs', [\Modules\Business\Http\Controllers\VisitLogController::class, 'store']);
        Route::put('/logs/{visitLog}', [\Modules\Business\Http\Controllers\VisitLogController::class, 'update']);
        Route::delete('/logs/{visitLog}', [\Modules\Business\Http\Controllers\VisitLogController::class, 'destroy']);
        Route::get('/achievement', [\Modules\Business\Http\Controllers\VisitLogController::class, 'achievement']);
        Route::get('/schedule', [\Modules\Business\Http\Controllers\VisitLogController::class, 'schedule']);
    });
    // 业务模块：考勤管理
    Route::prefix('business/attendance')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\AttendanceController::class, 'index']);
        Route::get('/{attendance}', [\Modules\Business\Http\Controllers\AttendanceController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\AttendanceController::class, 'store']);
        Route::put('/{attendance}', [\Modules\Business\Http\Controllers\AttendanceController::class, 'update']);
        Route::delete('/{attendance}', [\Modules\Business\Http\Controllers\AttendanceController::class, 'destroy']);
    });
    // 业务模块：收款管理
    Route::prefix('business/receive')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\ReceiveController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\ReceiveController::class, 'statistics']);
        Route::get('/{id}', [\Modules\Business\Http\Controllers\ReceiveController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\ReceiveController::class, 'store']);
        Route::put('/{id}', [\Modules\Business\Http\Controllers\ReceiveController::class, 'update']);
        Route::post('/{id}/approve', [\Modules\Business\Http\Controllers\ReceiveController::class, 'approve']);
        Route::delete('/{id}', [\Modules\Business\Http\Controllers\ReceiveController::class, 'destroy']);
    });
    // 业务模块：付款管理
    Route::prefix('business/pay')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\PayController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\PayController::class, 'statistics']);
        Route::get('/{id}', [\Modules\Business\Http\Controllers\PayController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\PayController::class, 'store']);
        Route::put('/{id}', [\Modules\Business\Http\Controllers\PayController::class, 'update']);
        Route::post('/{id}/approve', [\Modules\Business\Http\Controllers\PayController::class, 'approve']);
        Route::delete('/{id}', [\Modules\Business\Http\Controllers\PayController::class, 'destroy']);
    });
    // 业务模块：费用管理
    Route::prefix('business/expense')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\ExpenseController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\ExpenseController::class, 'statistics']);
        Route::get('/{id}', [\Modules\Business\Http\Controllers\ExpenseController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\ExpenseController::class, 'store']);
        Route::put('/{id}', [\Modules\Business\Http\Controllers\ExpenseController::class, 'update']);
        Route::post('/{id}/approve', [\Modules\Business\Http\Controllers\ExpenseController::class, 'approve']);
        Route::delete('/{id}', [\Modules\Business\Http\Controllers\ExpenseController::class, 'destroy']);
    });
});

