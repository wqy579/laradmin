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
use Modules\Performance\Http\Controllers\SalesTargetController;
use Modules\Performance\Http\Controllers\StockWarningController;

// =============================================================================
// 机器 C（绩效分析）路由
//
// 领域边界：提成方案/计算/报表 · 多维利润查询 · 应收账龄/发货未收款 ·
// 库存预警中心 · 销售分析看板 · 商品客户业务员分析 · 销售目标
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
        Route::get('/export', [CommissionRuleController::class, 'export']); // 必须在 /{id} 之前
        Route::get('/{id}', [CommissionRuleController::class, 'show']);
        Route::put('/{id}', [CommissionRuleController::class, 'update']);
        Route::delete('/{id}', [CommissionRuleController::class, 'destroy']);
        Route::post('/{id}/copy', [CommissionRuleController::class, 'copy']);
    });

    // ---- 提成计算（试算不落库 / 确认生成 / 试算明细导出）----
    Route::prefix('perf/commission')->group(function () {
        Route::post('/preview', [CommissionController::class, 'preview']);
        Route::post('/export', [CommissionController::class, 'export']);
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

    // ---- 多维利润查询（路径版维度别名 + 维度入口 + 导出）----
    // 维度别名用显式路由而非 {dimension}+whereIn：路由表是测试黄金文件的比对源，
    // 参数路由会把 5 个前端已声明路径折叠成 1 条、被基线测试判为前端接口缺失；
    // 语义与约束不变（枚举恒等于原 whereIn 列表，值经 defaults() 注入同名参数，
    // 与原参数路由绑定结果一致）；行为不变、路由可枚举。
    Route::prefix('perf/profit')->group(function () {
        Route::get('/', [ProfitController::class, 'index']);
        Route::get('/trend', [ProfitController::class, 'trend']);
        Route::get('/export', [ProfitController::class, 'export']);
        foreach (['orders', 'customers', 'employees', 'products', 'brands'] as $dimension) {
            Route::get('/'.$dimension, [ProfitController::class, 'byDimension'])
                ->defaults('dimension', $dimension);
        }
    });

    // ---- 应收账龄与发货未收款 ----
    Route::prefix('perf/ar')->group(function () {
        Route::get('/aging', [ArAgingController::class, 'aging']);
        Route::get('/aging/export', [ArAgingController::class, 'export']);
        Route::get('/aging/{customerId}/details', [ArAgingController::class, 'details']);
    });
    Route::get('perf/delivery/uncollected', [DeliveryUncollectedController::class, 'index']);
    Route::get('perf/delivery/uncollected/export', [DeliveryUncollectedController::class, 'export']);

    // ---- 库存预警中心（四条路径别名 + 概览 + 一键转采购草稿）----
    // {type} 同 profit/{dimension} 展开为显式路由（可枚举、供路由基线比对），值经 defaults() 注入，约束不变
    Route::prefix('perf/warning')->group(function () {
        Route::get('/overview', [StockWarningController::class, 'overview']);
        Route::post('/safety-stock/purchase-draft', [StockWarningController::class, 'purchaseDraft']);
        Route::get('/', [StockWarningController::class, 'index']);
        foreach (['safety-stock', 'expiry', 'sellable-days', 'stock-exception'] as $type) {
            Route::get('/'.$type, [StockWarningController::class, 'byType'])
                ->defaults('type', $type);
        }
    });

    // ---- 销售分析看板 ----
    Route::get('perf/dashboard/sales-overview', [DashboardController::class, 'salesOverview']);

    // ---- 商品/客户/业务员分析 + 增长退货率 + 各表导出 ----
    Route::prefix('perf/analysis')->group(function () {
        Route::get('/products', [AnalysisController::class, 'products']);
        Route::get('/customers', [AnalysisController::class, 'customers']);
        Route::get('/employees', [AnalysisController::class, 'employees']);
        Route::get('/growth-return', [AnalysisController::class, 'growthReturn']);
        // {name} 展开为显式路由（可枚举、供路由基线比对），值经 defaults() 注入，控制器入参不变
        foreach (['products', 'customers', 'employees', 'growth-return'] as $name) {
            Route::get('/'.$name.'/export', [AnalysisController::class, 'export'])
                ->defaults('name', $name);
        }
    });

    // ---- 销售目标（达成率数据源，任务书 §九）----
    Route::prefix('perf/sales-targets')->group(function () {
        Route::get('/', [SalesTargetController::class, 'index']);
        Route::post('/', [SalesTargetController::class, 'store']);
    });
});
