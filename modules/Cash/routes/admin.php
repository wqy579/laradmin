<?php

use Illuminate\Support\Facades\Route;
use Modules\Cash\Http\Controllers\CashAccountController;
use Modules\Cash\Http\Controllers\CashCardController;
use Modules\Cash\Http\Controllers\CashDashboardController;
use Modules\Cash\Http\Controllers\CashHandoverController;
use Modules\Cash\Http\Controllers\CashIntegralGrantController;
use Modules\Cash\Http\Controllers\CashIntegralRuleController;

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

    // ===== 积分规则（D5）=====
    Route::prefix('cash/integral/rules')->group(function () {
        Route::get('/', [CashIntegralRuleController::class, 'index']);
        Route::get('/export', [CashIntegralRuleController::class, 'export']);
        Route::get('/{id}', [CashIntegralRuleController::class, 'show'])->whereNumber('id');
        Route::post('/', [CashIntegralRuleController::class, 'store']);
        Route::put('/{id}', [CashIntegralRuleController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/toggle', [CashIntegralRuleController::class, 'toggle'])->whereNumber('id');
        Route::delete('/{id}', [CashIntegralRuleController::class, 'destroy'])->whereNumber('id');
    });

    // ===== 积分发放（D5）=====
    Route::prefix('cash/integral/grants')->group(function () {
        Route::get('/', [CashIntegralGrantController::class, 'index']);
        Route::get('/export', [CashIntegralGrantController::class, 'export']);
        Route::get('/{id}', [CashIntegralGrantController::class, 'show'])->whereNumber('id');
        Route::post('/', [CashIntegralGrantController::class, 'store']);
        Route::put('/{id}', [CashIntegralGrantController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/submit', [CashIntegralGrantController::class, 'submit'])->whereNumber('id');
        Route::post('/{id}/audit', [CashIntegralGrantController::class, 'audit'])->whereNumber('id');
        Route::post('/{id}/void', [CashIntegralGrantController::class, 'void'])->whereNumber('id');
    });

    // ===== 收入上交款 + 上交款查询（D2）=====
    Route::prefix('cash/handover')->group(function () {
        Route::get('/options', [CashHandoverController::class, 'options']);
        Route::get('/orders', [CashHandoverController::class, 'orders']);
        Route::get('/export', [CashHandoverController::class, 'export']);
        Route::get('/', [CashHandoverController::class, 'index']);
        Route::post('/', [CashHandoverController::class, 'store']);
        Route::post('/batch-confirm', [CashHandoverController::class, 'batchConfirm']);
        Route::get('/{id}', [CashHandoverController::class, 'show']);
        Route::post('/{id}/confirm', [CashHandoverController::class, 'confirm']);
    });

    // ===== 出纳工作台（D8，只读聚合）=====
    Route::prefix('cash/dashboard')->group(function () {
        Route::get('/summary', [CashDashboardController::class, 'summary']);
        Route::get('/trend', [CashDashboardController::class, 'trend']);
    });
});
