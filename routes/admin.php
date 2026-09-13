<?php

use Illuminate\Support\Facades\Route;

// API routes first (before SPA fallback)
Route::post('/auth/login', [\App\Http\Controllers\Auth\Admin\Auth::class, 'login'])
    ->middleware('rate.limit:login');

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 认证：登出/刷新/当前用户/修改密码
    Route::post('/auth/logout', [\App\Http\Controllers\Auth\Admin\Auth::class, 'logout']);
    Route::post('/auth/refresh', [\App\Http\Controllers\Auth\Admin\Auth::class, 'refresh']);
    Route::get('/auth/permissions/menu', [\App\Http\Controllers\Auth\Admin\Auth::class, 'menu']);
    Route::get('/auth/me', [\App\Http\Controllers\Auth\Admin\Auth::class, 'me']);
    Route::put('/auth/me', [\App\Http\Controllers\Auth\Admin\Auth::class, 'updateMe']);
    Route::post('/auth/change-password', [\App\Http\Controllers\Auth\Admin\Auth::class, 'changePassword']);

    // 业务模块：产品
    Route::prefix('business/product')->group(function () {
        Route::get('/units', [\App\Http\Controllers\Admin\Business\ProductController::class, 'units']);
        Route::get('/categories', [\App\Http\Controllers\Admin\Business\ProductController::class, 'categories']);
        Route::get('/', [\App\Http\Controllers\Admin\Business\ProductController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\ProductController::class, 'store']);
        Route::get('/{product}', [\App\Http\Controllers\Admin\Business\ProductController::class, 'show']);
        Route::put('/{product}', [\App\Http\Controllers\Admin\Business\ProductController::class, 'update']);
        Route::delete('/{product}', [\App\Http\Controllers\Admin\Business\ProductController::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\Admin\Business\ProductController::class, 'batchDelete']);
        Route::post('/batch-status', [\App\Http\Controllers\Admin\Business\ProductController::class, 'batchUpdateStatus']);
        Route::post('/categories', [\App\Http\Controllers\Admin\Business\ProductController::class, 'storeCategory']);
        Route::put('/categories/{category}', [\App\Http\Controllers\Admin\Business\ProductController::class, 'updateCategory']);
        Route::delete('/categories/{category}', [\App\Http\Controllers\Admin\Business\ProductController::class, 'destroyCategory']);
    });

    // 业务模块：客户
    Route::prefix('business/customers')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\CustomerController::class, 'index']);
        Route::get('/{customer}', [\App\Http\Controllers\Admin\Business\CustomerController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\CustomerController::class, 'store']);
        Route::put('/{customer}', [\App\Http\Controllers\Admin\Business\CustomerController::class, 'update']);
        Route::delete('/{customer}', [\App\Http\Controllers\Admin\Business\CustomerController::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\Admin\Business\CustomerController::class, 'batchDelete']);
        Route::post('/batch-status', [\App\Http\Controllers\Admin\Business\CustomerController::class, 'batchUpdateStatus']);
    });

    // 业务模块：员工
    Route::prefix('business/employee')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\EmployeeController::class, 'index']);
        Route::get('/{employee}', [\App\Http\Controllers\Admin\Business\EmployeeController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\EmployeeController::class, 'store']);
        Route::put('/{employee}', [\App\Http\Controllers\Admin\Business\EmployeeController::class, 'update']);
        Route::delete('/{employee}', [\App\Http\Controllers\Admin\Business\EmployeeController::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\Admin\Business\EmployeeController::class, 'batchDelete']);
        Route::post('/batch-status', [\App\Http\Controllers\Admin\Business\EmployeeController::class, 'batchUpdateStatus']);
    });

    // 业务模块：仓库
    Route::prefix('business/warehouse')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\WarehouseController::class, 'index']);
        Route::get('/{warehouse}', [\App\Http\Controllers\Admin\Business\WarehouseController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\WarehouseController::class, 'store']);
        Route::put('/{warehouse}', [\App\Http\Controllers\Admin\Business\WarehouseController::class, 'update']);
        Route::delete('/{warehouse}', [\App\Http\Controllers\Admin\Business\WarehouseController::class, 'destroy']);
    });

    // 业务模块：车辆
    Route::prefix('business/vehicle')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\VehicleController::class, 'index']);
        Route::get('/{vehicle}', [\App\Http\Controllers\Admin\Business\VehicleController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\VehicleController::class, 'store']);
        Route::put('/{vehicle}', [\App\Http\Controllers\Admin\Business\VehicleController::class, 'update']);
        Route::delete('/{vehicle}', [\App\Http\Controllers\Admin\Business\VehicleController::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\Admin\Business\VehicleController::class, 'batchDelete']);
        Route::post('/batch-status', [\App\Http\Controllers\Admin\Business\VehicleController::class, 'batchUpdateStatus']);
    });

    // 业务模块：供应商
    Route::prefix('business/supplier')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'index']);
        Route::get('/export', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'export']);
        Route::get('/{supplier}', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'store']);
        Route::put('/{supplier}', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'update']);
        Route::delete('/{supplier}', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'batchDelete']);
        Route::post('/batch-status', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'batchUpdateStatus']);
        Route::post('/import', [\App\Http\Controllers\Admin\Business\SupplierController::class, 'import']);
    });

    // 业务模块：线路
    Route::prefix('business/route')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\RouteController::class, 'index']);
        Route::get('/export', [\App\Http\Controllers\Admin\Business\RouteController::class, 'export']);
        Route::get('/{route}', [\App\Http\Controllers\Admin\Business\RouteController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\RouteController::class, 'store']);
        Route::put('/{route}', [\App\Http\Controllers\Admin\Business\RouteController::class, 'update']);
        Route::delete('/{route}', [\App\Http\Controllers\Admin\Business\RouteController::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\Admin\Business\RouteController::class, 'batchDelete']);
        Route::post('/batch-status', [\App\Http\Controllers\Admin\Business\RouteController::class, 'batchUpdateStatus']);
        Route::get('/{route}/customers', [\App\Http\Controllers\Admin\Business\RouteController::class, 'customers']);
        Route::post('/{route}/customers', [\App\Http\Controllers\Admin\Business\RouteController::class, 'addCustomer']);
        Route::delete('/{route}/customers', [\App\Http\Controllers\Admin\Business\RouteController::class, 'removeCustomer']);
        Route::post('/import', [\App\Http\Controllers\Admin\Business\RouteController::class, 'import']);
    });

    // 业务模块：销售订单
    Route::prefix('business/sales-order')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\SalesOrderController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\SalesOrderController::class, 'statistics']);
        Route::get('/{salesOrder}', [\App\Http\Controllers\Admin\Business\SalesOrderController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\SalesOrderController::class, 'store']);
        Route::put('/{salesOrder}', [\App\Http\Controllers\Admin\Business\SalesOrderController::class, 'update']);
        Route::delete('/{salesOrder}', [\App\Http\Controllers\Admin\Business\SalesOrderController::class, 'destroy']);
        Route::post('/{salesOrder}/approve', [\App\Http\Controllers\Admin\Business\SalesOrderController::class, 'approve']);
    });

    // 业务模块：采购订单
    Route::prefix('business/purchase-order')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\PurchaseOrderController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\PurchaseOrderController::class, 'statistics']);
        Route::get('/{purchaseOrder}', [\App\Http\Controllers\Admin\Business\PurchaseOrderController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\PurchaseOrderController::class, 'store']);
        Route::put('/{purchaseOrder}', [\App\Http\Controllers\Admin\Business\PurchaseOrderController::class, 'update']);
        Route::delete('/{purchaseOrder}', [\App\Http\Controllers\Admin\Business\PurchaseOrderController::class, 'destroy']);
        Route::post('/{purchaseOrder}/approve', [\App\Http\Controllers\Admin\Business\PurchaseOrderController::class, 'approve']);
        Route::post('/{purchaseOrder}/receive', [\App\Http\Controllers\Admin\Business\PurchaseOrderController::class, 'receive']);
    });

    // 业务模块：调拨管理
    Route::prefix('business/transfer')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\TransferController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\TransferController::class, 'statistics']);
        Route::get('/{transfer}', [\App\Http\Controllers\Admin\Business\TransferController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\TransferController::class, 'store']);
        Route::put('/{transfer}', [\App\Http\Controllers\Admin\Business\TransferController::class, 'update']);
        Route::delete('/{transfer}', [\App\Http\Controllers\Admin\Business\TransferController::class, 'destroy']);
        Route::post('/{transfer}/execute', [\App\Http\Controllers\Admin\Business\TransferController::class, 'execute']);
    });

    // 业务模块：退货管理
    Route::prefix('business/return')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\ReturnController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\ReturnController::class, 'statistics']);
        Route::get('/{return}', [\App\Http\Controllers\Admin\Business\ReturnController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\ReturnController::class, 'store']);
        Route::put('/{return}', [\App\Http\Controllers\Admin\Business\ReturnController::class, 'update']);
        Route::delete('/{return}', [\App\Http\Controllers\Admin\Business\ReturnController::class, 'destroy']);
        Route::post('/{return}/approve', [\App\Http\Controllers\Admin\Business\ReturnController::class, 'approve']);
        Route::post('/{return}/process', [\App\Http\Controllers\Admin\Business\ReturnController::class, 'process']);
    });

    // 业务模块：发货管理
    Route::prefix('business/delivery')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\DeliveryController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\DeliveryController::class, 'statistics']);
        Route::get('/{delivery}', [\App\Http\Controllers\Admin\Business\DeliveryController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\DeliveryController::class, 'store']);
        Route::put('/{delivery}', [\App\Http\Controllers\Admin\Business\DeliveryController::class, 'update']);
        Route::delete('/{delivery}', [\App\Http\Controllers\Admin\Business\DeliveryController::class, 'destroy']);
        Route::post('/{delivery}/dispatch', [\App\Http\Controllers\Admin\Business\DeliveryController::class, 'dispatch']);
        Route::post('/{delivery}/complete', [\App\Http\Controllers\Admin\Business\DeliveryController::class, 'complete']);
    });

    // 业务模块：成本价格
    Route::prefix('business/cost-price')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\CostPriceController::class, 'index']);
        Route::post('/batch', [\App\Http\Controllers\Admin\Business\CostPriceController::class, 'batchUpdate']);
        Route::put('/{product}', [\App\Http\Controllers\Admin\Business\CostPriceController::class, 'update']);
    });

    // 连凯库存监控（监控库存变动）
    Route::get('business/liankai-stock-monitor', [\App\Http\Controllers\Admin\Business\LiankaiStockCheckController::class, 'monitor']);
    Route::get('business/liankai-stock-check', [\App\Http\Controllers\Admin\Business\LiankaiStockCheckController::class, 'index']);
    Route::post('business/liankai-stock-check/sync', [\App\Http\Controllers\Admin\Business\LiankaiStockCheckController::class, 'sync']);
    Route::post('business/liankai-stock-monitor/sync', [\App\Http\Controllers\Admin\Business\LiankaiStockCheckController::class, 'sync']);

    // 业务模块：库存查询
    Route::prefix('business/stock')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\StockController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\StockController::class, 'statistics']);
        Route::get('/{stock}', [\App\Http\Controllers\Admin\Business\StockController::class, 'show']);
    });

    // 业务模块：入库单
    Route::prefix('business/stock-in')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\StockInController::class, 'index']);
        Route::get('/{stockIn}', [\App\Http\Controllers\Admin\Business\StockInController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\StockInController::class, 'store']);
        Route::put('/{stockIn}', [\App\Http\Controllers\Admin\Business\StockInController::class, 'update']);
        Route::delete('/{stockIn}', [\App\Http\Controllers\Admin\Business\StockInController::class, 'destroy']);
        Route::post('/{stockIn}/approve', [\App\Http\Controllers\Admin\Business\StockInController::class, 'approve']);
    });

    // 业务模块：出库单
    Route::prefix('business/stock-out')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\StockOutController::class, 'index']);
        Route::get('/{stockOut}', [\App\Http\Controllers\Admin\Business\StockOutController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\StockOutController::class, 'store']);
        Route::put('/{stockOut}', [\App\Http\Controllers\Admin\Business\StockOutController::class, 'update']);
        Route::delete('/{stockOut}', [\App\Http\Controllers\Admin\Business\StockOutController::class, 'destroy']);
        Route::post('/{stockOut}/approve', [\App\Http\Controllers\Admin\Business\StockOutController::class, 'approve']);
    });

    // 业务模块：拜访管理
    Route::prefix('business/visit')->group(function () {
        Route::get('/logs', [\App\Http\Controllers\Admin\Business\VisitLogController::class, 'index']);
        Route::get('/logs/{visitLog}', [\App\Http\Controllers\Admin\Business\VisitLogController::class, 'show']);
        Route::post('/logs', [\App\Http\Controllers\Admin\Business\VisitLogController::class, 'store']);
        Route::put('/logs/{visitLog}', [\App\Http\Controllers\Admin\Business\VisitLogController::class, 'update']);
        Route::delete('/logs/{visitLog}', [\App\Http\Controllers\Admin\Business\VisitLogController::class, 'destroy']);
        Route::get('/achievement', [\App\Http\Controllers\Admin\Business\VisitLogController::class, 'achievement']);
        Route::get('/schedule', [\App\Http\Controllers\Admin\Business\VisitLogController::class, 'schedule']);
    });

    // 系统模块：设置
    Route::prefix('system/setting')->group(function () {
        Route::get('/', [\App\Http\Controllers\System\Admin\Config::class, 'index']);
        Route::get('/all', [\App\Http\Controllers\System\Admin\Config::class, 'all']);
        Route::get('/tree', [\App\Http\Controllers\System\Admin\Config::class, 'tree']);
        Route::get('/groups', [\App\Http\Controllers\System\Admin\Config::class, 'groups']);
        Route::get('/{id}', [\App\Http\Controllers\System\Admin\Config::class, 'show']);
        Route::post('/', [\App\Http\Controllers\System\Admin\Config::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\System\Admin\Config::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\System\Admin\Config::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\System\Admin\Config::class, 'batchDelete']);
        Route::post('/batch-status', [\App\Http\Controllers\System\Admin\Config::class, 'batchUpdateStatus']);
        Route::post('/batch-save', [\App\Http\Controllers\System\Admin\Config::class, 'batchSave']);
    });

    // 系统模块：日志
    Route::prefix('system/log')->group(function () {
        Route::get('/', [\App\Http\Controllers\System\Admin\Log::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\System\Admin\Log::class, 'statistics']);
        Route::get('/{id}', [\App\Http\Controllers\System\Admin\Log::class, 'show']);
        Route::delete('/{id}', [\App\Http\Controllers\System\Admin\Log::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\System\Admin\Log::class, 'batchDelete']);
        Route::post('/clear', [\App\Http\Controllers\System\Admin\Log::class, 'clear']);
    });

    // 系统模块：字典
    Route::prefix('system/dictionary')->group(function () {
        Route::get('/', [\App\Http\Controllers\System\Admin\Dictionary::class, 'index']);
        Route::get('/all', [\App\Http\Controllers\System\Admin\Dictionary::class, 'all']);
        Route::get('/{id}', [\App\Http\Controllers\System\Admin\Dictionary::class, 'show']);
        Route::post('/', [\App\Http\Controllers\System\Admin\Dictionary::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\System\Admin\Dictionary::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\System\Admin\Dictionary::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\System\Admin\Dictionary::class, 'batchDelete']);
        Route::post('/batch-status', [\App\Http\Controllers\System\Admin\Dictionary::class, 'batchUpdateStatus']);
    });

    // 系统模块：字典项
    Route::prefix('system/dictionary-item')->group(function () {
        Route::get('/', [\App\Http\Controllers\System\Admin\Dictionary::class, 'getItemsList']);
        Route::get('/all', [\App\Http\Controllers\System\Admin\Dictionary::class, 'getAllItems']);
        Route::get('/{id}', [\App\Http\Controllers\System\Admin\Dictionary::class, 'showItem']);
        Route::post('/', [\App\Http\Controllers\System\Admin\Dictionary::class, 'storeItem']);
        Route::put('/{id}', [\App\Http\Controllers\System\Admin\Dictionary::class, 'updateItem']);
        Route::delete('/{id}', [\App\Http\Controllers\System\Admin\Dictionary::class, 'destroyItem']);
        Route::post('/batch-delete', [\App\Http\Controllers\System\Admin\Dictionary::class, 'batchDeleteItems']);
        Route::post('/batch-status', [\App\Http\Controllers\System\Admin\Dictionary::class, 'batchUpdateItemsStatus']);
    });

    // 系统模块：定时调度
    Route::prefix('system/scheduled')->group(function () {
        Route::get('/', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'index']);
        Route::get('/all', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'all']);
        Route::get('/statistics', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'statistics']);
        Route::get('/{id}', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'batchDelete']);
        Route::post('/{id}/start', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'start']);
        Route::post('/{id}/pause', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'pause']);
        Route::post('/{id}/resume', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'resume']);
        Route::post('/{id}/stop', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'stop']);
        Route::post('/{id}/run', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'run']);
        Route::get('/{id}/logs', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'logs']);
        Route::delete('/{id}/logs', [\App\Http\Controllers\System\Admin\ScheduledController::class, 'clearLogs']);
    });

    // 系统模块：附件
    Route::prefix('system/attachment')->group(function () {
        Route::get('/', [\App\Http\Controllers\System\Admin\Attachment::class, 'index']);
        Route::get('/directories', [\App\Http\Controllers\System\Admin\Attachment::class, 'directories']);
        Route::get('/statistics', [\App\Http\Controllers\System\Admin\Attachment::class, 'statistics']);
        Route::get('/type-distribution', [\App\Http\Controllers\System\Admin\Attachment::class, 'typeDistribution']);
        Route::get('/{id}', [\App\Http\Controllers\System\Admin\Attachment::class, 'show']);
        Route::post('/get-by-ids', [\App\Http\Controllers\System\Admin\Attachment::class, 'getByIds']);
        Route::put('/{id}', [\App\Http\Controllers\System\Admin\Attachment::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\System\Admin\Attachment::class, 'destroy']);
        Route::post('/batch-delete', [\App\Http\Controllers\System\Admin\Attachment::class, 'batchDelete']);
    });

    // 系统模块：上传
    Route::prefix('system/upload')->group(function () {
        Route::post('/', [\App\Http\Controllers\System\Admin\Upload::class, 'upload']);
        Route::post('/multiple', [\App\Http\Controllers\System\Admin\Upload::class, 'uploadMultiple']);
        Route::post('/base64', [\App\Http\Controllers\System\Admin\Upload::class, 'uploadBase64']);
        Route::post('/delete', [\App\Http\Controllers\System\Admin\Upload::class, 'delete']);
        // 分片上传
        Route::post('/chunk/init', [\App\Http\Controllers\System\Admin\Upload::class, 'initChunk']);
        Route::post('/chunk/upload', [\App\Http\Controllers\System\Admin\Upload::class, 'uploadChunk']);
        Route::post('/chunk/merge', [\App\Http\Controllers\System\Admin\Upload::class, 'mergeChunks']);
        Route::get('/chunk/uploaded', [\App\Http\Controllers\System\Admin\Upload::class, 'getUploadedChunks']);
        Route::post('/chunk/cancel', [\App\Http\Controllers\System\Admin\Upload::class, 'cancelChunk']);
    });

    // 权限模块
    Route::prefix('auth')->group(function () {
        Route::get('/users', [\App\Http\Controllers\Auth\Admin\User::class, 'index']);
        Route::get('/users/{id}', [\App\Http\Controllers\Auth\Admin\User::class, 'show']);
        Route::post('/users', [\App\Http\Controllers\Auth\Admin\User::class, 'store']);
        Route::put('/users/{id}', [\App\Http\Controllers\Auth\Admin\User::class, 'update']);
        Route::delete('/users/{id}', [\App\Http\Controllers\Auth\Admin\User::class, 'destroy']);
        Route::post('/users/batch-delete', [\App\Http\Controllers\Auth\Admin\User::class, 'batchDelete']);
        Route::post('/users/batch-status', [\App\Http\Controllers\Auth\Admin\User::class, 'batchUpdateStatus']);
        Route::post('/users/batch-department', [\App\Http\Controllers\Auth\Admin\User::class, 'batchAssignDepartment']);
        Route::post('/users/batch-roles', [\App\Http\Controllers\Auth\Admin\User::class, 'batchAssignRoles']);
        Route::post('/users/import', [\App\Http\Controllers\Auth\Admin\User::class, 'import']);
        Route::get('/users/export', [\App\Http\Controllers\Auth\Admin\User::class, 'export']);
        Route::post('/users/{id}/reset-password', [\App\Http\Controllers\Auth\Admin\User::class, 'resetPassword']);

        Route::get('/roles', [\App\Http\Controllers\Auth\Admin\Role::class, 'index']);
        Route::get('/roles/all', [\App\Http\Controllers\Auth\Admin\Role::class, 'getAll']);
        Route::get('/roles/{id}', [\App\Http\Controllers\Auth\Admin\Role::class, 'show']);
        Route::post('/roles', [\App\Http\Controllers\Auth\Admin\Role::class, 'store']);
        Route::put('/roles/{id}', [\App\Http\Controllers\Auth\Admin\Role::class, 'update']);
        Route::delete('/roles/{id}', [\App\Http\Controllers\Auth\Admin\Role::class, 'destroy']);
        Route::post('/roles/batch-delete', [\App\Http\Controllers\Auth\Admin\Role::class, 'batchDelete']);
        Route::post('/roles/batch-status', [\App\Http\Controllers\Auth\Admin\Role::class, 'batchUpdateStatus']);
        Route::post('/roles/assign-permissions', [\App\Http\Controllers\Auth\Admin\Role::class, 'assignPermissions']);

        Route::get('/permissions', [\App\Http\Controllers\Auth\Admin\Permission::class, 'index']);
        Route::get('/permissions/tree', [\App\Http\Controllers\Auth\Admin\Permission::class, 'tree']);
        Route::post('/permissions', [\App\Http\Controllers\Auth\Admin\Permission::class, 'store']);
        Route::put('/permissions/{id}', [\App\Http\Controllers\Auth\Admin\Permission::class, 'update']);
        Route::delete('/permissions/{id}', [\App\Http\Controllers\Auth\Admin\Permission::class, 'destroy']);
        Route::post('/permissions/batch-delete', [\App\Http\Controllers\Auth\Admin\Permission::class, 'batchDelete']);
        Route::post('/permissions/update-icons', [\App\Http\Controllers\Auth\Admin\Permission::class, 'updateIcons']);

        Route::get('/departments', [\App\Http\Controllers\Auth\Admin\Department::class, 'index']);
        Route::get('/departments/tree', [\App\Http\Controllers\Auth\Admin\Department::class, 'tree']);
        Route::get('/departments/all', [\App\Http\Controllers\Auth\Admin\Department::class, 'getAll']);
        Route::get('/departments/{id}', [\App\Http\Controllers\Auth\Admin\Department::class, 'show']);
        Route::post('/departments', [\App\Http\Controllers\Auth\Admin\Department::class, 'store']);
        Route::put('/departments/{id}', [\App\Http\Controllers\Auth\Admin\Department::class, 'update']);
        Route::delete('/departments/{id}', [\App\Http\Controllers\Auth\Admin\Department::class, 'destroy']);
        Route::post('/departments/batch-delete', [\App\Http\Controllers\Auth\Admin\Department::class, 'batchDelete']);
        Route::post('/departments/batch-status', [\App\Http\Controllers\Auth\Admin\Department::class, 'batchUpdateStatus']);
    });
});

// 导入旧系统数据
Route::post('/import/old-system', function () {
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $output = $kernel->call('import:old-system');
    
    return response()->json([
        'status' => 0,
        'message' => '数据导入成功',
        'output' => $output
    ]);
})->middleware('auth:sanctum');

// 远程执行数据库迁移
Route::post('/remote/migrate', function () {
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $output = $kernel->call('migrate', [
        '--path' => 'database/migrations/business',
        '--force' => true,
    ]);
    
    return response()->json([
        'status' => 0,
        'message' => '数据库迁移执行成功',
        'output' => $output
    ]);
})->middleware(['auth.check:admin', 'log.request']);

    // 业务模块：收款/付款/费用（纳入鉴权组，此前漏在中间件组之外）
    Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 业务模块：考勤管理
    Route::prefix('business/attendance')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\AttendanceController::class, 'index']);
        Route::get('/{attendance}', [\App\Http\Controllers\Admin\Business\AttendanceController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\AttendanceController::class, 'store']);
        Route::put('/{attendance}', [\App\Http\Controllers\Admin\Business\AttendanceController::class, 'update']);
        Route::delete('/{attendance}', [\App\Http\Controllers\Admin\Business\AttendanceController::class, 'destroy']);
    });

    // 业务模块：收款管理
    Route::prefix('business/receive')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\ReceiveController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\ReceiveController::class, 'statistics']);
        Route::get('/{id}', [\App\Http\Controllers\Admin\Business\ReceiveController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\ReceiveController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Admin\Business\ReceiveController::class, 'update']);
        Route::post('/{id}/approve', [\App\Http\Controllers\Admin\Business\ReceiveController::class, 'approve']);
        Route::delete('/{id}', [\App\Http\Controllers\Admin\Business\ReceiveController::class, 'destroy']);
    });

    // 业务模块：付款管理
    Route::prefix('business/pay')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\PayController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\PayController::class, 'statistics']);
        Route::get('/{id}', [\App\Http\Controllers\Admin\Business\PayController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\PayController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Admin\Business\PayController::class, 'update']);
        Route::post('/{id}/approve', [\App\Http\Controllers\Admin\Business\PayController::class, 'approve']);
        Route::delete('/{id}', [\App\Http\Controllers\Admin\Business\PayController::class, 'destroy']);
    });

    // 业务模块：费用管理
    Route::prefix('business/expense')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\Business\ExpenseController::class, 'index']);
        Route::get('/statistics', [\App\Http\Controllers\Admin\Business\ExpenseController::class, 'statistics']);
        Route::get('/{id}', [\App\Http\Controllers\Admin\Business\ExpenseController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Admin\Business\ExpenseController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Admin\Business\ExpenseController::class, 'update']);
        Route::post('/{id}/approve', [\App\Http\Controllers\Admin\Business\ExpenseController::class, 'approve']);
        Route::delete('/{id}', [\App\Http\Controllers\Admin\Business\ExpenseController::class, 'destroy']);
    });
    });
