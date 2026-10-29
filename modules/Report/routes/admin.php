<?php

use Illuminate\Support\Facades\Route;
use Modules\Report\Http\Controllers\CombinedReportController;
use Modules\Report\Http\Controllers\CustomerReportController;
use Modules\Report\Http\Controllers\PriceRankController;
use Modules\Report\Http\Controllers\ProcurementController;
use Modules\Report\Http\Controllers\RecentPriceController;
use Modules\Report\Http\Controllers\ReportColumnController;
use Modules\Report\Http\Controllers\ReportOptionController;
use Modules\Report\Http\Controllers\ReportTemplateController;
use Modules\Report\Http\Controllers\SalesAnalysisController;
use Modules\Report\Http\Controllers\SalesmanReportController;
use Modules\Report\Http\Controllers\SalesReportController;
use Modules\Report\Http\Controllers\StockAnalysisController;
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

        // 列配置持久化：每用户每报表一份列显隐/排序偏好
        Route::prefix('columns')->group(function () {
            Route::get('/', [ReportColumnController::class, 'show']);
            Route::post('/', [ReportColumnController::class, 'store']);
        });

        // 销售报表（13 个汇总维度）
        Route::prefix('sales')->group(function () {
            Route::get('/', [SalesReportController::class, 'index']);
            Route::get('/export', [SalesReportController::class, 'export']);
            // 穿透：双击行 → 源单明细抽屉
            Route::get('/drill', [SalesReportController::class, 'drill']);
        });

        // 客户报表（E2，6 个汇总维度）
        Route::prefix('customer')->group(function () {
            Route::get('/', [CustomerReportController::class, 'index']);
            Route::get('/export', [CustomerReportController::class, 'export']);
            // 穿透：客户维度双击 → 该客户订单列表抽屉
            Route::get('/drill', [CustomerReportController::class, 'drill']);
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

        // 销售分析族报表（E3，7 个维度：业务员/商品/排行/特价/促销/品项销售/品项铺市）
        Route::prefix('sales-analysis')->group(function () {
            Route::get('/', [SalesAnalysisController::class, 'index']);
            Route::get('/export', [SalesAnalysisController::class, 'export']);
        });

        // 排行+价格类报表（E4，4 个维度：销售排行总表/商品价格/客户特价价格/促销价格）
        Route::prefix('price-rank')->group(function () {
            Route::get('/', [PriceRankController::class, 'index']);
            Route::get('/export', [PriceRankController::class, 'export']);
        });

        // 库存报表族（E5，8 个维度：超期/安全库存/可销天数/变动/明细/盘盈盘亏/拆装/库存分析）
        Route::prefix('stock-analysis')->group(function () {
            Route::get('/', [StockAnalysisController::class, 'index']);
            Route::get('/export', [StockAnalysisController::class, 'export']);
        });

        // 采购+入出库+费用+价格报表（E6，8 个维度：采购汇总/入库明细/调拨明细/报废报损/
        // 入库汇总/收发存汇总/费用查询/价格方案）
        Route::prefix('procurement')->group(function () {
            Route::get('/', [ProcurementController::class, 'index']);
            Route::get('/export', [ProcurementController::class, 'export']);
            Route::get('/drill', [ProcurementController::class, 'drill']);
        });
    });
});
