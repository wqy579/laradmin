<?php

use Illuminate\Support\Facades\Route;
use Modules\Order\Http\Controllers\AssemblyController;
use Modules\Order\Http\Controllers\BusinessHistoryController;
use Modules\Order\Http\Controllers\CashFlowController;
use Modules\Order\Http\Controllers\CustomerController;
use Modules\Order\Http\Controllers\DeliveryController;
use Modules\Order\Http\Controllers\PayController;
use Modules\Order\Http\Controllers\ProductBomController;
use Modules\Order\Http\Controllers\ProfitController;
use Modules\Order\Http\Controllers\PromotionController;
use Modules\Order\Http\Controllers\PurchaseApplicationController;
use Modules\Order\Http\Controllers\PurchaseReturnController;
use Modules\Order\Http\Controllers\ReceiveController;
use Modules\Order\Http\Controllers\ReturnController;
use Modules\Order\Http\Controllers\RouteController;
use Modules\Order\Http\Controllers\SalesOrderController;
use Modules\Order\Http\Controllers\SalesReturnController;
use Modules\Order\Http\Controllers\SplitController;
use Modules\Order\Http\Controllers\StatementController;
use Modules\Order\Http\Controllers\SupplierController;
use Modules\Order\Http\Controllers\VisitLogController;

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
        Route::get('/', [CustomerController::class, 'index']);
        Route::get('/{customer}', [CustomerController::class, 'show']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::put('/{customer}', [CustomerController::class, 'update']);
        Route::delete('/{customer}', [CustomerController::class, 'destroy']);
        Route::post('/batch-delete', [CustomerController::class, 'batchDelete']);
        Route::post('/batch-status', [CustomerController::class, 'batchUpdateStatus']);
    });
    // 供应商
    Route::prefix('business/supplier')->group(function () {
        Route::get('/', [SupplierController::class, 'index']);
        Route::get('/{supplier}', [SupplierController::class, 'show']);
        Route::post('/', [SupplierController::class, 'store']);
        Route::put('/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/{supplier}', [SupplierController::class, 'destroy']);
        Route::post('/batch-delete', [SupplierController::class, 'batchDelete']);
        Route::post('/batch-status', [SupplierController::class, 'batchUpdateStatus']);
    });
    // 线路
    Route::prefix('business/route')->group(function () {
        Route::get('/', [RouteController::class, 'index']);
        Route::get('/{route}', [RouteController::class, 'show']);
        Route::post('/', [RouteController::class, 'store']);
        Route::put('/{route}', [RouteController::class, 'update']);
        Route::delete('/{route}', [RouteController::class, 'destroy']);
        Route::post('/batch-delete', [RouteController::class, 'batchDelete']);
        Route::post('/batch-status', [RouteController::class, 'batchUpdateStatus']);
        Route::get('/{route}/customers', [RouteController::class, 'customers']);
        Route::post('/{route}/customers', [RouteController::class, 'addCustomer']);
        Route::delete('/{route}/customers', [RouteController::class, 'removeCustomer']);
    });
    // 销售订单
    Route::prefix('business/sales-order')->group(function () {
        Route::get('/', [SalesOrderController::class, 'index']);
        Route::get('/statistics', [SalesOrderController::class, 'statistics']);
        Route::get('/summary', [SalesOrderController::class, 'summary']);
        // 静态路由必须在 {salesOrder} 之前，否则被路由参数吞掉
        Route::get('/recent-prices', [SalesOrderController::class, 'recentPrices']);
        Route::post('/batch-red-flush', [SalesOrderController::class, 'batchRedFlush']);
        Route::post('/batch-update', [SalesOrderController::class, 'batchUpdate']);
        Route::get('/{salesOrder}', [SalesOrderController::class, 'show']);
        Route::post('/', [SalesOrderController::class, 'store']);
        Route::put('/{salesOrder}', [SalesOrderController::class, 'update']);
        Route::delete('/{salesOrder}', [SalesOrderController::class, 'destroy']);
        Route::post('/{salesOrder}/approve', [SalesOrderController::class, 'approve']);
        Route::post('/{salesOrder}/cancel', [SalesOrderController::class, 'cancel']);
        Route::post('/{salesOrder}/print', [SalesOrderController::class, 'print']);
        Route::post('/{salesOrder}/advance', [SalesOrderController::class, 'approve']);
    });

    // 促销管理（静态路由必须在 {id} 之前，避免被路由参数吞掉）
    Route::get('business/promotion/active', [PromotionController::class, 'active']);
    Route::post('business/promotion/calculate', [PromotionController::class, 'calculate']);
    Route::get('business/promotion/products', [PromotionController::class, 'products']);
    Route::get('business/promotion/report', [PromotionController::class, 'report']);
    Route::prefix('business/promotion')->group(function () {
        Route::get('/', [PromotionController::class, 'index']);
        Route::post('/', [PromotionController::class, 'store']);
        Route::get('/{id}', [PromotionController::class, 'show']);
        Route::put('/{id}', [PromotionController::class, 'update']);
        Route::delete('/{id}', [PromotionController::class, 'destroy']);
        Route::post('/{id}/enable', [PromotionController::class, 'enable']);
        Route::post('/{id}/disable', [PromotionController::class, 'disable']);
    });
    // 采购订单
    // 采购订单已移除（进货录单由入库单 stock-in 承担）
    // 退货管理（采购退货）
    Route::prefix('business/return')->group(function () {
        Route::get('/', [ReturnController::class, 'index']);
        Route::get('/statistics', [ReturnController::class, 'statistics']);
        Route::get('/{return}', [ReturnController::class, 'show']);
        Route::post('/', [ReturnController::class, 'store']);
        Route::put('/{return}', [ReturnController::class, 'update']);
        Route::delete('/{return}', [ReturnController::class, 'destroy']);
        Route::post('/{return}/approve', [ReturnController::class, 'approve']);
        Route::post('/{return}/process', [ReturnController::class, 'process']);
    });
    // 销售退货管理
    Route::prefix('business/sales-return')->group(function () {
        Route::get('/', [SalesReturnController::class, 'index']);
        Route::get('/order-products', [SalesReturnController::class, 'orderProducts']);
        Route::get('/export', [SalesReturnController::class, 'export']);
        Route::get('/{id}', [SalesReturnController::class, 'show']);
        Route::post('/', [SalesReturnController::class, 'store']);
        Route::put('/{id}', [SalesReturnController::class, 'update']);
        Route::delete('/{id}', [SalesReturnController::class, 'destroy']);
        Route::post('/{id}/submit', [SalesReturnController::class, 'submit']);
        Route::post('/{id}/approve', [SalesReturnController::class, 'approve']);
        Route::post('/{id}/reject', [SalesReturnController::class, 'reject']);
        Route::post('/{id}/cancel', [SalesReturnController::class, 'cancel']);
    });
    // 采购退货管理
    Route::prefix('business/purchase-return')->group(function () {
        Route::get('/', [PurchaseReturnController::class, 'index']);
        Route::get('/stock-in-products', [PurchaseReturnController::class, 'stockInProducts']);
        Route::get('/export', [PurchaseReturnController::class, 'export']);
        Route::post('/batch-approve', [PurchaseReturnController::class, 'batchApprove']);
        Route::get('/{id}', [PurchaseReturnController::class, 'show']);
        Route::post('/', [PurchaseReturnController::class, 'store']);
        Route::put('/{id}', [PurchaseReturnController::class, 'update']);
        Route::delete('/{id}', [PurchaseReturnController::class, 'destroy']);
        Route::post('/{id}/submit', [PurchaseReturnController::class, 'submit']);
        Route::post('/{id}/approve', [PurchaseReturnController::class, 'approve']);
        Route::post('/{id}/reject', [PurchaseReturnController::class, 'reject']);
        Route::post('/{id}/cancel', [PurchaseReturnController::class, 'cancel']);
    });
    // 采购申请管理（采购流程起点：草稿→提交→审批→转采购入库）
    // 静态路由必须先于 /{id}，否则 export/batch-* 会被当成 id 吞掉
    Route::prefix('business/purchase-application')->group(function () {
        Route::get('/', [PurchaseApplicationController::class, 'index']);
        Route::get('/export', [PurchaseApplicationController::class, 'export']);
        Route::post('/batch-submit', [PurchaseApplicationController::class, 'batchSubmit']);
        Route::post('/batch-approve', [PurchaseApplicationController::class, 'batchApprove']);
        Route::get('/{id}', [PurchaseApplicationController::class, 'show']);
        Route::post('/', [PurchaseApplicationController::class, 'store']);
        Route::put('/{id}', [PurchaseApplicationController::class, 'update']);
        Route::delete('/{id}', [PurchaseApplicationController::class, 'destroy']);
        Route::post('/{id}/submit', [PurchaseApplicationController::class, 'submit']);
        Route::post('/{id}/approve', [PurchaseApplicationController::class, 'approve']);
        Route::post('/{id}/reject', [PurchaseApplicationController::class, 'reject']);
        Route::post('/{id}/cancel', [PurchaseApplicationController::class, 'cancel']);
        Route::post('/{id}/transfer', [PurchaseApplicationController::class, 'transfer']);
    });
    // 商品组装管理
    Route::prefix('business/assembly')->group(function () {
        Route::get('/', [AssemblyController::class, 'index']);
        Route::get('/bom-by-product', [AssemblyController::class, 'bomByProduct']);
        Route::get('/export', [AssemblyController::class, 'export']);
        Route::post('/batch-approve', [AssemblyController::class, 'batchApprove']);
        Route::get('/{id}', [AssemblyController::class, 'show']);
        Route::post('/', [AssemblyController::class, 'store']);
        Route::put('/{id}', [AssemblyController::class, 'update']);
        Route::delete('/{id}', [AssemblyController::class, 'destroy']);
        Route::post('/{id}/submit', [AssemblyController::class, 'submit']);
        Route::post('/{id}/approve', [AssemblyController::class, 'approve']);
        Route::post('/{id}/reject', [AssemblyController::class, 'reject']);
        Route::post('/{id}/cancel', [AssemblyController::class, 'cancel']);
    });
    // 商品拆分管理
    Route::prefix('business/disassembly')->group(function () {
        Route::get('/', [SplitController::class, 'index']);
        Route::get('/bom-by-product', [SplitController::class, 'bomByProduct']);
        Route::get('/export', [SplitController::class, 'export']);
        Route::post('/batch-approve', [SplitController::class, 'batchApprove']);
        Route::get('/{id}', [SplitController::class, 'show']);
        Route::post('/', [SplitController::class, 'store']);
        Route::put('/{id}', [SplitController::class, 'update']);
        Route::delete('/{id}', [SplitController::class, 'destroy']);
        Route::post('/{id}/submit', [SplitController::class, 'submit']);
        Route::post('/{id}/approve', [SplitController::class, 'approve']);
        Route::post('/{id}/reject', [SplitController::class, 'reject']);
        Route::post('/{id}/cancel', [SplitController::class, 'cancel']);
    });
    // 商品 BOM 配置（商品档案编辑弹窗调用）
    Route::get('business/product/{id}/bom', [ProductBomController::class, 'show']);
    Route::put('business/product/{id}/bom', [ProductBomController::class, 'update']);
    // 发货管理
    Route::prefix('business/delivery')->group(function () {
        Route::get('/', [DeliveryController::class, 'index']);
        Route::get('/statistics', [DeliveryController::class, 'statistics']);
        Route::get('/{delivery}', [DeliveryController::class, 'show']);
        Route::post('/', [DeliveryController::class, 'store']);
        Route::put('/{delivery}', [DeliveryController::class, 'update']);
        Route::delete('/{delivery}', [DeliveryController::class, 'destroy']);
        Route::post('/{delivery}/dispatch', [DeliveryController::class, 'dispatch']);
        Route::post('/{delivery}/complete', [DeliveryController::class, 'complete']);
    });
    // 拜访管理
    Route::prefix('business/visit')->group(function () {
        Route::get('/logs', [VisitLogController::class, 'index']);
        Route::get('/logs/{visitLog}', [VisitLogController::class, 'show']);
        Route::post('/logs', [VisitLogController::class, 'store']);
        Route::put('/logs/{visitLog}', [VisitLogController::class, 'update']);
        Route::delete('/logs/{visitLog}', [VisitLogController::class, 'destroy']);
        Route::get('/achievement', [VisitLogController::class, 'achievement']);
        // 达成率走势（双击行开的折线图）与地图轨迹：静态路由，必须落在 /logs/{id} 之外
        Route::get('/trend', [VisitLogController::class, 'trend']);
        Route::get('/trajectory', [VisitLogController::class, 'trajectory']);
        Route::get('/schedule/export', [VisitLogController::class, 'scheduleExport']);
        Route::get('/schedule', [VisitLogController::class, 'schedule']);
    });
    // 收款管理
    Route::prefix('business/receive')->group(function () {
        Route::get('/', [ReceiveController::class, 'index']);
        Route::get('/statistics', [ReceiveController::class, 'statistics']);
        Route::get('/receivable', [ReceiveController::class, 'receivable']);
        Route::get('/unpaid-orders', [ReceiveController::class, 'unpaidOrders']);
        Route::get('/{id}', [ReceiveController::class, 'show']);
        Route::post('/', [ReceiveController::class, 'store']);
        Route::put('/{id}', [ReceiveController::class, 'update']);
        Route::post('/{id}/approve', [ReceiveController::class, 'approve']);
        Route::post('/{id}/red-flush', [ReceiveController::class, 'redFlush']);
        Route::delete('/{id}', [ReceiveController::class, 'destroy']);
    });
    // 客户对账
    Route::prefix('business/customer-statement')->group(function () {
        Route::get('/customers', [StatementController::class, 'customers']);
        Route::get('/', [StatementController::class, 'index']);
        Route::get('/export', [StatementController::class, 'export']);
        Route::get('/print', [StatementController::class, 'print']);
    });
    // 往来对账（旧接口，兼容前端）
    Route::get('business/statement', [ReceiveController::class, 'receivable']);
    // 付款管理
    Route::prefix('business/pay')->group(function () {
        Route::get('/', [PayController::class, 'index']);
        Route::get('/statistics', [PayController::class, 'statistics']);
        Route::get('/payable', [PayController::class, 'payable']);
        Route::get('/{id}', [PayController::class, 'show']);
        Route::post('/', [PayController::class, 'store']);
        Route::put('/{id}', [PayController::class, 'update']);
        Route::post('/{id}/approve', [PayController::class, 'approve']);
        Route::delete('/{id}', [PayController::class, 'destroy']);
    });
    // 现金流水
    Route::get('business/cash-flow', [CashFlowController::class, 'index']);
    // 经营历程（UNION ALL 各业务表）
    Route::get('business/history', [BusinessHistoryController::class, 'index']);
    Route::get('business/history/summary', [BusinessHistoryController::class, 'summary']);
    Route::get('business/history/export', [BusinessHistoryController::class, 'export']);
    // 月度利润
    Route::get('business/profit', [ProfitController::class, 'index']);
    // 往来对账
    Route::get('business/statement', [ReceiveController::class, 'statement']);
});
