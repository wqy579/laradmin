<?php

use Illuminate\Support\Facades\Route;
use Modules\Ledger\Http\Controllers\BalanceController;
use Modules\Ledger\Http\Controllers\OpeningBalanceController;
use Modules\Ledger\Http\Controllers\PeriodController;
use Modules\Ledger\Http\Controllers\ReportController;
use Modules\Ledger\Http\Controllers\SubjectController;
use Modules\Ledger\Http\Controllers\VoucherController;

/**
 * 总账管理模块（Ledger）路由
 *
 * 三机物理隔离：统一前缀 /admin/ledger，JWT Bearer 鉴权（内核信封施加
 * auth.check:admin + log.request + stock.snapshot）。本文件只声明 Ledger 自己的路由。
 *
 * 静态路由须声明在 /{id} 之前，避免被路由参数吞掉。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // ===== 会计科目 =====
    Route::prefix('ledger/subjects')->group(function () {
        Route::get('/', [SubjectController::class, 'index']);
        Route::get('/tree', [SubjectController::class, 'tree']);
        Route::get('/options', [SubjectController::class, 'options']);
        Route::get('/export', [SubjectController::class, 'export']);
        Route::post('/', [SubjectController::class, 'store']);
        Route::get('/{id}', [SubjectController::class, 'show']);
        Route::put('/{id}', [SubjectController::class, 'update']);
        Route::delete('/{id}', [SubjectController::class, 'destroy']);
        Route::put('/{id}/status', [SubjectController::class, 'toggleStatus']);
    });

    // ===== 期初余额 =====
    Route::prefix('ledger/opening-balances')->group(function () {
        Route::get('/', [OpeningBalanceController::class, 'index']);
        Route::post('/', [OpeningBalanceController::class, 'save']);
        Route::post('/balance-check', [OpeningBalanceController::class, 'balanceCheck']);
    });

    // ===== 记账凭证 =====
    Route::prefix('ledger/vouchers')->group(function () {
        Route::get('/', [VoucherController::class, 'index']);
        Route::get('/export', [VoucherController::class, 'export']);
        Route::post('/batch-audit', [VoucherController::class, 'batchAudit']);
        Route::post('/batch-reverse', [VoucherController::class, 'batchReverse']);
        Route::get('/templates', [VoucherController::class, 'templates']);
        Route::post('/templates', [VoucherController::class, 'saveTemplate']);
        Route::get('/subject-ledger', [VoucherController::class, 'subjectLedger']);
        Route::post('/', [VoucherController::class, 'store']);
        Route::get('/{id}', [VoucherController::class, 'show']);
        Route::put('/{id}', [VoucherController::class, 'update']);
        Route::delete('/{id}', [VoucherController::class, 'destroy']);
        Route::post('/{id}/audit', [VoucherController::class, 'audit']);
        Route::post('/{id}/reverse-audit', [VoucherController::class, 'reverseAudit']);
        Route::post('/{id}/reverse', [VoucherController::class, 'reverse']);
    });

    // ===== 财务月结 =====
    Route::prefix('ledger/periods')->group(function () {
        Route::get('/', [PeriodController::class, 'index']);
        Route::post('/{period}/check', [PeriodController::class, 'check']);
        Route::post('/{period}/carry-forward', [PeriodController::class, 'carryForward']);
        Route::post('/{period}/close', [PeriodController::class, 'close']);
        Route::post('/{period}/reopen', [PeriodController::class, 'reopen']);
    });

    // ===== 科目余额表 =====
    Route::prefix('ledger/balance')->group(function () {
        Route::get('/', [BalanceController::class, 'index']);
        Route::get('/export', [BalanceController::class, 'export']);
    });

    // ===== 账簿报表 =====
    Route::prefix('ledger/reports')->group(function () {
        Route::get('/subject-balance', [ReportController::class, 'subjectBalance']);
        Route::get('/balance-sheet', [ReportController::class, 'balanceSheet']);
        Route::get('/income-statement', [ReportController::class, 'incomeStatement']);
        Route::get('/journal', [ReportController::class, 'journal']);
        Route::get('/export', [ReportController::class, 'export']);
    });
});
