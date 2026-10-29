<?php

use Illuminate\Support\Facades\Route;
use Modules\Agreement\Http\Controllers\AgreementController;

/**
 * 协议管理模块（Agreement）路由 — F4
 *
 * 统一前缀 /admin/agr，鉴权信封同 Cash/Hr。
 * 陈列协议统计 stats 是静态段，声明在 /{id} 之前。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    Route::prefix('agr/agreements')->group(function () {
        Route::get('/options', [AgreementController::class, 'options']);
        Route::get('/', [AgreementController::class, 'index']);
        Route::get('/export', [AgreementController::class, 'export']);
        Route::get('/stats', [AgreementController::class, 'stats']);
        Route::post('/', [AgreementController::class, 'store']);
        Route::get('/{id}', [AgreementController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [AgreementController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [AgreementController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/terminate', [AgreementController::class, 'terminate'])->whereNumber('id');
        Route::post('/{id}/redeem', [AgreementController::class, 'redeem'])->whereNumber('id');
    });
});
