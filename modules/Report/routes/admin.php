<?php

use Illuminate\Support\Facades\Route;
use Modules\Report\Http\Controllers\CombinedReportController;
use Modules\Report\Http\Controllers\RecentPriceController;
use Modules\Report\Http\Controllers\ReportOptionController;
use Modules\Report\Http\Controllers\ReportTemplateController;
use Modules\Report\Http\Controllers\SalesmanReportController;
use Modules\Report\Http\Controllers\SalesReportController;
use Modules\Report\Http\Controllers\StockReportController;

// =============================================================================
// 报表模块（Report）路由
//
// 领域边界：最近价格 / 销售报表 / 库存报表 / 业务员报表 / 综合报表 / 查询模版
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Report 模块自己的路由。
//
// URL 前缀沿用 business/*：菜单 path 早已按此注册（见 BusinessSeeder），
// 前端也按此前缀调用，改前缀要前后端同步发版，此处不动。
//
// 报表是纯读模块：不写业务表，只写自己的 report_templates（查询模版）。
// =============================================================================

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 最近价格（价格管理 → 最近价格）
    Route::prefix('business/recent-prices')->group(function () {
        Route::get('/', [RecentPriceController::class, 'index']);
        Route::get('/export', [RecentPriceController::class, 'export']);
    });

    Route::prefix('business/report')->group(function () {
        // 报表筛选项下拉数据源（客户/商品/仓库/品牌/分类/业务员/枚举…）
        Route::get('options', [ReportOptionController::class, 'index']);

        // 查询模版：保存/复用一套查询条件
        Route::prefix('templates')->group(function () {
            Route::get('/', [ReportTemplateController::class, 'index']);
            Route::post('/', [ReportTemplateController::class, 'store']);
            Route::delete('/{id}', [ReportTemplateController::class, 'destroy']);
        });

        // 销售报表（13 个汇总维度）
        Route::prefix('sales')->group(function () {
            Route::get('/', [SalesReportController::class, 'index']);
            Route::get('/export', [SalesReportController::class, 'export']);
        });

        // 库存报表（4 个汇总维度）
        Route::prefix('stock')->group(function () {
            Route::get('/', [StockReportController::class, 'index']);
            Route::get('/export', [StockReportController::class, 'export']);
        });

        // 业务员报表（5 个汇总维度）
        Route::prefix('salesman')->group(function () {
            Route::get('/', [SalesmanReportController::class, 'index']);
            Route::get('/export', [SalesmanReportController::class, 'export']);
        });

        // 综合报表（概览卡片 + 6 个分析维度）
        Route::prefix('combined')->group(function () {
            Route::get('/', [CombinedReportController::class, 'index']);
            Route::get('/export', [CombinedReportController::class, 'export']);
        });
    });
});
