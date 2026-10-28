<?php

use Illuminate\Support\Facades\Route;
use Modules\Cash\Http\Controllers\CashAccountController;
use Modules\Cash\Http\Controllers\CashCardController;

/**
 * 出纳资金模块（Cash）路由
 *
 * D 域物理隔离：统一前缀 /admin/cash，JWT Bearer 鉴权（内核信封施加
 * api + stock.snapshot，本文件再叠 auth.check:admin + log.request）。
 * 静态段须声明在 /{id} 之前，避免被路由参数吞掉。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // ===== 现金账户收支查询（D-29）=====
    Route::prefix('cash/accounts')->group(function () {
        Route::get('/options', [CashAccountController::class, 'options']);
        Route::get('/{id}/flows', [CashAccountController::class, 'flows']);
        Route::get('/{id}/export', [CashAccountController::class, 'export']);
    });

    // ===== 刷卡收款记录（P0-10）=====
    Route::prefix('cash/card-txns')->group(function () {
        Route::get('/options', [CashCardController::class, 'options']);
        Route::get('/', [CashCardController::class, 'index']);
        Route::get('/export', [CashCardController::class, 'export']);
        Route::post('/{id}/refund', [CashCardController::class, 'refund']);
    });
});
