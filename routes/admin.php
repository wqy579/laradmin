<?php

use Illuminate\Support\Facades\Route;

// API routes first (before SPA fallback)
Route::post('/auth/login', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'login'])
    ->middleware('rate.limit:login');

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 认证：登出/刷新/当前用户/修改密码
    Route::post('/auth/logout', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'logout']);
    Route::post('/auth/refresh', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'refresh']);
    Route::get('/auth/permissions/menu', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'menu']);
    Route::get('/auth/me', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'me']);
    Route::put('/auth/me', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'updateMe']);
    Route::post('/auth/change-password', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'changePassword']);

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
        Route::get('/export', [\Modules\Business\Http\Controllers\SupplierController::class, 'export']);
        Route::get('/{supplier}', [\Modules\Business\Http\Controllers\SupplierController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\SupplierController::class, 'store']);
        Route::put('/{supplier}', [\Modules\Business\Http\Controllers\SupplierController::class, 'update']);
        Route::delete('/{supplier}', [\Modules\Business\Http\Controllers\SupplierController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\SupplierController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\SupplierController::class, 'batchUpdateStatus']);
        Route::post('/import', [\Modules\Business\Http\Controllers\SupplierController::class, 'import']);
    });

    // 业务模块：线路
    Route::prefix('business/route')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\RouteController::class, 'index']);
        Route::get('/export', [\Modules\Business\Http\Controllers\RouteController::class, 'export']);
        Route::get('/{route}', [\Modules\Business\Http\Controllers\RouteController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\RouteController::class, 'store']);
        Route::put('/{route}', [\Modules\Business\Http\Controllers\RouteController::class, 'update']);
        Route::delete('/{route}', [\Modules\Business\Http\Controllers\RouteController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\RouteController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\RouteController::class, 'batchUpdateStatus']);
        Route::get('/{route}/customers', [\Modules\Business\Http\Controllers\RouteController::class, 'customers']);
        Route::post('/{route}/customers', [\Modules\Business\Http\Controllers\RouteController::class, 'addCustomer']);
        Route::delete('/{route}/customers', [\Modules\Business\Http\Controllers\RouteController::class, 'removeCustomer']);
        Route::post('/import', [\Modules\Business\Http\Controllers\RouteController::class, 'import']);
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
        Route::get('/{stockIn}', [\Modules\Business\Http\Controllers\StockInController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\StockInController::class, 'store']);
        Route::put('/{stockIn}', [\Modules\Business\Http\Controllers\StockInController::class, 'update']);
        Route::delete('/{stockIn}', [\Modules\Business\Http\Controllers\StockInController::class, 'destroy']);
        Route::post('/{stockIn}/approve', [\Modules\Business\Http\Controllers\StockInController::class, 'approve']);
    });

    // 业务模块：出库单
    Route::prefix('business/stock-out')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\StockOutController::class, 'index']);
        Route::get('/{stockOut}', [\Modules\Business\Http\Controllers\StockOutController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\StockOutController::class, 'store']);
        Route::put('/{stockOut}', [\Modules\Business\Http\Controllers\StockOutController::class, 'update']);
        Route::delete('/{stockOut}', [\Modules\Business\Http\Controllers\StockOutController::class, 'destroy']);
        Route::post('/{stockOut}/approve', [\Modules\Business\Http\Controllers\StockOutController::class, 'approve']);
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

    // 系统模块：设置
    Route::prefix('system/setting')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Config::class, 'index']);
        Route::get('/all', [\Modules\System\Http\Controllers\Admin\Config::class, 'all']);
        Route::get('/tree', [\Modules\System\Http\Controllers\Admin\Config::class, 'tree']);
        Route::get('/groups', [\Modules\System\Http\Controllers\Admin\Config::class, 'groups']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Config::class, 'show']);
        Route::post('/', [\Modules\System\Http\Controllers\Admin\Config::class, 'store']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\Config::class, 'update']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Config::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Config::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\System\Http\Controllers\Admin\Config::class, 'batchUpdateStatus']);
        Route::post('/batch-save', [\Modules\System\Http\Controllers\Admin\Config::class, 'batchSave']);
    });

    // 系统模块：日志
    Route::prefix('system/log')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Log::class, 'index']);
        Route::get('/statistics', [\Modules\System\Http\Controllers\Admin\Log::class, 'statistics']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Log::class, 'show']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Log::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Log::class, 'batchDelete']);
        Route::post('/clear', [\Modules\System\Http\Controllers\Admin\Log::class, 'clear']);
    });

    // 系统模块：字典
    Route::prefix('system/dictionary')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'index']);
        Route::get('/all', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'all']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'show']);
        Route::post('/', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'store']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'update']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'batchUpdateStatus']);
    });

    // 系统模块：字典项
    Route::prefix('system/dictionary-item')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'getItemsList']);
        Route::get('/all', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'getAllItems']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'showItem']);
        Route::post('/', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'storeItem']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'updateItem']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'destroyItem']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'batchDeleteItems']);
        Route::post('/batch-status', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'batchUpdateItemsStatus']);
    });

    // 系统模块：定时调度
    Route::prefix('system/scheduled')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'index']);
        Route::get('/all', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'all']);
        Route::get('/statistics', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'statistics']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'show']);
        Route::post('/', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'store']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'update']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'batchDelete']);
        Route::post('/{id}/start', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'start']);
        Route::post('/{id}/pause', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'pause']);
        Route::post('/{id}/resume', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'resume']);
        Route::post('/{id}/stop', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'stop']);
        Route::post('/{id}/run', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'run']);
        Route::get('/{id}/logs', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'logs']);
        Route::delete('/{id}/logs', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'clearLogs']);
    });

    // 系统模块：附件
    Route::prefix('system/attachment')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'index']);
        Route::get('/directories', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'directories']);
        Route::get('/statistics', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'statistics']);
        Route::get('/type-distribution', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'typeDistribution']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'show']);
        Route::post('/get-by-ids', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'getByIds']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'update']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'batchDelete']);
    });

    // 系统模块：上传
    Route::prefix('system/upload')->group(function () {
        Route::post('/', [\Modules\System\Http\Controllers\Admin\Upload::class, 'upload']);
        Route::post('/multiple', [\Modules\System\Http\Controllers\Admin\Upload::class, 'uploadMultiple']);
        Route::post('/base64', [\Modules\System\Http\Controllers\Admin\Upload::class, 'uploadBase64']);
        Route::post('/delete', [\Modules\System\Http\Controllers\Admin\Upload::class, 'delete']);
        // 分片上传
        Route::post('/chunk/init', [\Modules\System\Http\Controllers\Admin\Upload::class, 'initChunk']);
        Route::post('/chunk/upload', [\Modules\System\Http\Controllers\Admin\Upload::class, 'uploadChunk']);
        Route::post('/chunk/merge', [\Modules\System\Http\Controllers\Admin\Upload::class, 'mergeChunks']);
        Route::get('/chunk/uploaded', [\Modules\System\Http\Controllers\Admin\Upload::class, 'getUploadedChunks']);
        Route::post('/chunk/cancel', [\Modules\System\Http\Controllers\Admin\Upload::class, 'cancelChunk']);
    });

    // 权限模块
    Route::prefix('auth')->group(function () {
        Route::get('/users', [\Modules\Auth\Http\Controllers\Admin\User::class, 'index']);
        Route::get('/users/{id}', [\Modules\Auth\Http\Controllers\Admin\User::class, 'show']);
        Route::post('/users', [\Modules\Auth\Http\Controllers\Admin\User::class, 'store']);
        Route::put('/users/{id}', [\Modules\Auth\Http\Controllers\Admin\User::class, 'update']);
        Route::delete('/users/{id}', [\Modules\Auth\Http\Controllers\Admin\User::class, 'destroy']);
        Route::post('/users/batch-delete', [\Modules\Auth\Http\Controllers\Admin\User::class, 'batchDelete']);
        Route::post('/users/batch-status', [\Modules\Auth\Http\Controllers\Admin\User::class, 'batchUpdateStatus']);
        Route::post('/users/batch-department', [\Modules\Auth\Http\Controllers\Admin\User::class, 'batchAssignDepartment']);
        Route::post('/users/batch-roles', [\Modules\Auth\Http\Controllers\Admin\User::class, 'batchAssignRoles']);
        Route::post('/users/import', [\Modules\Auth\Http\Controllers\Admin\User::class, 'import']);
        Route::get('/users/export', [\Modules\Auth\Http\Controllers\Admin\User::class, 'export']);
        Route::post('/users/{id}/reset-password', [\Modules\Auth\Http\Controllers\Admin\User::class, 'resetPassword']);

        Route::get('/roles', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'index']);
        Route::get('/roles/all', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'getAll']);
        Route::get('/roles/{id}', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'show']);
        Route::post('/roles', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'store']);
        Route::put('/roles/{id}', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'update']);
        Route::delete('/roles/{id}', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'destroy']);
        Route::post('/roles/batch-delete', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'batchDelete']);
        Route::post('/roles/batch-status', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'batchUpdateStatus']);
        Route::post('/roles/assign-permissions', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'assignPermissions']);

        Route::get('/permissions', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'index']);
        Route::get('/permissions/tree', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'tree']);
        Route::post('/permissions', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'store']);
        Route::put('/permissions/{id}', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'update']);
        Route::delete('/permissions/{id}', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'destroy']);
        Route::post('/permissions/batch-delete', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'batchDelete']);
        Route::post('/permissions/update-icons', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'updateIcons']);

        Route::get('/departments', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'index']);
        Route::get('/departments/tree', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'tree']);
        Route::get('/departments/all', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'getAll']);
        Route::get('/departments/{id}', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'show']);
        Route::post('/departments', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'store']);
        Route::put('/departments/{id}', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'update']);
        Route::delete('/departments/{id}', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'destroy']);
        Route::post('/departments/batch-delete', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'batchDelete']);
        Route::post('/departments/batch-status', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'batchUpdateStatus']);
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
