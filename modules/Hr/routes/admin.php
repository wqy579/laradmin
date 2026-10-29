<?php

use Illuminate\Support\Facades\Route;
use Modules\Hr\Http\Controllers\CommissionFlowController;
use Modules\Hr\Http\Controllers\PayrollController;

/**
 * 人事薪酬模块（Hr）路由 — F4
 *
 * 统一前缀 /admin/hr，JWT Bearer 鉴权（内核信封施加 api + stock.snapshot，
 * 本文件再叠 auth.check:admin + log.request）。静态段声明在 /{id} 之前。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // ===== 工资核算 + 发放 =====
    Route::prefix('hr/payrolls')->group(function () {
        Route::get('/options', [PayrollController::class, 'options']);
        Route::get('/', [PayrollController::class, 'index']);
        Route::get('/export', [PayrollController::class, 'export']);
        Route::post('/generate', [PayrollController::class, 'generate']);
        Route::get('/{id}', [PayrollController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [PayrollController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PayrollController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/pay', [PayrollController::class, 'pay'])->whereNumber('id');
    });

    // ===== 提成流水（只读快照 + 与 perf 对账）=====
    Route::prefix('hr/commission-flows')->group(function () {
        Route::get('/options', [CommissionFlowController::class, 'options']);
        Route::get('/', [CommissionFlowController::class, 'index']);
        Route::get('/export', [CommissionFlowController::class, 'export']);
        Route::get('/reconcile', [CommissionFlowController::class, 'reconcile']);
    });
});
