<?php

use Illuminate\Support\Facades\Route;
use Modules\Archive\Http\Controllers\CompanyController;
use Modules\Archive\Http\Controllers\DivisionController;
use Modules\Archive\Http\Controllers\DownloadFileController;
use Modules\Archive\Http\Controllers\HelpDocController;
use Modules\Archive\Http\Controllers\OpeningWizardController;

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

    // 开账向导（F6 第二批）：静态段 lock-status / template 必须先声明，
    // 否则 GET /data/opening/lock-status 会被参数型路由吃掉
    Route::prefix('data/opening')->group(function () {
        Route::get('/lock-status', [OpeningWizardController::class, 'lockStatus']);
        Route::get('/template/{kind}', [OpeningWizardController::class, 'downloadTemplate']);
        Route::get('/', [OpeningWizardController::class, 'show']);
        Route::post('/steps/{step}', [OpeningWizardController::class, 'saveStep']);
        Route::post('/import', [OpeningWizardController::class, 'import']);
        Route::post('/lock', [OpeningWizardController::class, 'lock']);
        Route::post('/unlock', [OpeningWizardController::class, 'unlock']);
    });

    // 帮助中心（F6 第三批）：同样是静态段先于 {doc}
    Route::prefix('data/help')->group(function () {
        Route::post('/preview', [HelpDocController::class, 'preview']);
        Route::get('/categories', [HelpDocController::class, 'categories']);
        Route::get('/', [HelpDocController::class, 'index']);
        Route::post('/', [HelpDocController::class, 'store']);
        Route::get('/{doc}', [HelpDocController::class, 'show']);
        Route::put('/{doc}', [HelpDocController::class, 'update']);
        Route::delete('/{doc}', [HelpDocController::class, 'destroy']);
        Route::post('/{doc}/read', [HelpDocController::class, 'read']);
        Route::post('/{doc}/publish', [HelpDocController::class, 'publish']);
        Route::post('/{doc}/withdraw', [HelpDocController::class, 'withdraw']);
    });

    // 下载中心：download 是动作型子路径，放在 {file} 参数路由之后不影响匹配
    Route::prefix('data/download')->group(function () {
        Route::get('/categories', [DownloadFileController::class, 'categories']);
        Route::get('/', [DownloadFileController::class, 'index']);
        Route::post('/', [DownloadFileController::class, 'store']);
        Route::get('/{file}', [DownloadFileController::class, 'show']);
        Route::put('/{file}', [DownloadFileController::class, 'update']);
        Route::delete('/{file}', [DownloadFileController::class, 'destroy']);
        Route::get('/{file}/download', [DownloadFileController::class, 'download']);
    });
});
