<?php

use Illuminate\Support\Facades\Route;
use Modules\Archive\Http\Controllers\CompanyController;
use Modules\Archive\Http\Controllers\DivisionController;

// =============================================================================
// 基础档案模块（Archive）路由 —— F6
//
// 领域边界：公司档案 / 事业部档案；后续 F6 的开账向导与帮助/下载中心也挂本模块。
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Archive 模块自己的路由。
//
// URL 前缀沿用既有习惯：档案页挂在 data/*（前端菜单 path 就是 /data/company），
// 与既有的 data.product 等资料页同级。
// 路由归属变更请用 tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// =============================================================================

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 公司档案（静态段 options/export/batch-status 必须先于 {company}，否则被路由参数吞掉）
    Route::prefix('data/company')->group(function () {
        Route::get('/options', [CompanyController::class, 'options']);
        Route::get('/export', [CompanyController::class, 'export']);
        Route::post('/batch-status', [CompanyController::class, 'batchUpdateStatus']);
        Route::get('/', [CompanyController::class, 'index']);
        Route::post('/', [CompanyController::class, 'store']);
        Route::get('/{company}', [CompanyController::class, 'show']);
        Route::put('/{company}', [CompanyController::class, 'update']);
        Route::delete('/{company}', [CompanyController::class, 'destroy']);
    });

    // 事业部档案
    Route::prefix('data/division')->group(function () {
        Route::get('/options', [DivisionController::class, 'options']);
        Route::get('/export', [DivisionController::class, 'export']);
        Route::post('/batch-status', [DivisionController::class, 'batchUpdateStatus']);
        Route::get('/', [DivisionController::class, 'index']);
        Route::post('/', [DivisionController::class, 'store']);
        Route::get('/{division}', [DivisionController::class, 'show']);
        Route::put('/{division}', [DivisionController::class, 'update']);
        Route::delete('/{division}', [DivisionController::class, 'destroy']);
    });
});
