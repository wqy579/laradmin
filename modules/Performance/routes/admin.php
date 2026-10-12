<?php

use Illuminate\Support\Facades\Route;
use Modules\Performance\Http\Controllers\AnalysisController;
use Modules\Performance\Http\Controllers\ArAgingController;
use Modules\Performance\Http\Controllers\CommissionController;
use Modules\Performance\Http\Controllers\CommissionOrderController;
use Modules\Performance\Http\Controllers\CommissionRuleController;
use Modules\Performance\Http\Controllers\DashboardController;
use Modules\Performance\Http\Controllers\DeliveryUncollectedController;
use Modules\Performance\Http\Controllers\ProfitController;
use Modules\Performance\Http\Controllers\StockWarningController;

// =============================================================================
// 机器 C（绩效分析）路由
//
// 领域边界：提成方案/计算/报表 · 多维利润查询 · 应收账龄/发货未收款 ·
// 库存预警中心 · 销售分析看板 · 商品客户业务员分析
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Performance 模块自己的路由。
//
// URL 前缀 /perf，与菜单 path /perf/* 对齐。纯读模块为主，提成方案/提成单有写操作。
// 机器 A（ledger_）、机器 B（fin_）不在此声明，三机物理隔离。
// =============================================================================

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // ---- 提成方案 ----
    Route::prefix('perf/commission-rules')->group(function () {
        Route::get('/', [CommissionRuleController::class, 'index']);
        Route::post('/', [CommissionRuleController::class, 'store']);
        Route::get('/estimate', [CommissionRuleController::class, 'estimate']);
        Route::get('/{id}', [CommissionRuleController::class, 'show']);
        Route::put('/{id}', [CommissionRuleController::class, 'update']);
        Route::delete('/{id}', [CommissionRuleController::class, 'destroy']);
        Route::post('/{id}/copy', [CommissionRuleController::class, 'copy']);
    });

    // ---- 提成计算 ----
    Route::prefix('perf/commission')->group(function () {
        Route::post('/preview', [CommissionController::class, 'preview']);
        Route::post('/generate', [CommissionController::class, 'generate']);
    });

    // ---- 提成单 ----
    Route::prefix('perf/commission-orders')->group(function () {
        Route::get('/', [CommissionOrderController::class, 'index']);
        Route::get('/{id}', [CommissionOrderController::class, 'show']);
        Route::post('/{id}/audit', [CommissionOrderController::class, 'audit']);
        Route::post('/{id}/reject', [CommissionOrderController::class, 'reject']);
        Route::post('/{id}/pay', [CommissionOrderController::class, 'pay']);
        Route::get('/{id}/export', [CommissionOrderController::class, 'export']);
    });

    // ---- 多维利润查询 ----
    Route::prefix('perf/profit')->group(function () {
        Route::get('/', [ProfitController::class, 'index']);
        Route::get('/trend', [ProfitController::class, 'trend']);
    });

    // ---- 应收账龄与发货未收款 ----
    Route::prefix('perf/ar')->group(function () {
        Route::get('/aging', [ArAgingController::class, 'aging']);
        Route::get('/aging/{customerId}/details', [ArAgingController::class, 'details']);
    });
    Route::get('perf/delivery/uncollected', [DeliveryUncollectedController::class, 'index']);

    // ---- 库存预警中心 ----
    Route::prefix('perf/warning')->group(function () {
        Route::get('/overview', [StockWarningController::class, 'overview']);
        Route::get('/', [StockWarningController::class, 'index']);
    });

    // ---- 销售分析看板 ----
    Route::get('perf/dashboard/sales-overview', [DashboardController::class, 'salesOverview']);

    // ---- 商品/客户/业务员分析 + 增长退货率 ----
    Route::prefix('perf/analysis')->group(function () {
        Route::get('/products', [AnalysisController::class, 'products']);
        Route::get('/customers', [AnalysisController::class, 'customers']);
        Route::get('/employees', [AnalysisController::class, 'employees']);
        Route::get('/growth-return', [AnalysisController::class, 'growthReturn']);
    });
});
