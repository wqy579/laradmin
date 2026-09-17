<?php

use Illuminate\Support\Facades\Route;

// =============================================================================
// 业务模块（Business）路由 —— 残部
//
// 2026-10 模块化拆分后，库存（→ Stock）与订单（→ Order）两大领域已独立成模块，
// 本文件只剩「员工 / 考勤 / 费用」三块。若要继续拆分，可再抽 Modules\Hr、Modules\Finance。
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名）。
// URL 前缀仍为 business/*（历史遗留，前端按此前缀调用），本次只改后端归属不动 URL。
// =============================================================================

// 收款/付款/费用此前漏在鉴权组之外，现一并纳入。
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 员工
    Route::prefix('business/employee')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\EmployeeController::class, 'index']);
        Route::get('/{employee}', [\Modules\Business\Http\Controllers\EmployeeController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\EmployeeController::class, 'store']);
        Route::put('/{employee}', [\Modules\Business\Http\Controllers\EmployeeController::class, 'update']);
        Route::delete('/{employee}', [\Modules\Business\Http\Controllers\EmployeeController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\Business\Http\Controllers\EmployeeController::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\Business\Http\Controllers\EmployeeController::class, 'batchUpdateStatus']);
    });
    // 考勤管理
    Route::prefix('business/attendance')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\AttendanceController::class, 'index']);
        // 前端 business.js 的 attendance.statistics 声明了它，控制器方法也在，
        // 唯独漏了路由——与其他业务模块（订单/库存/调拨）的 /statistics 口径不一致。
        Route::get('/statistics', [\Modules\Business\Http\Controllers\AttendanceController::class, 'statistics']);
        Route::get('/{attendance}', [\Modules\Business\Http\Controllers\AttendanceController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\AttendanceController::class, 'store']);
        Route::put('/{attendance}', [\Modules\Business\Http\Controllers\AttendanceController::class, 'update']);
        Route::delete('/{attendance}', [\Modules\Business\Http\Controllers\AttendanceController::class, 'destroy']);
    });
    // 费用管理
    Route::prefix('business/expense')->group(function () {
        Route::get('/', [\Modules\Business\Http\Controllers\ExpenseController::class, 'index']);
        Route::get('/statistics', [\Modules\Business\Http\Controllers\ExpenseController::class, 'statistics']);
        Route::get('/{id}', [\Modules\Business\Http\Controllers\ExpenseController::class, 'show']);
        Route::post('/', [\Modules\Business\Http\Controllers\ExpenseController::class, 'store']);
        Route::put('/{id}', [\Modules\Business\Http\Controllers\ExpenseController::class, 'update']);
        Route::post('/{id}/approve', [\Modules\Business\Http\Controllers\ExpenseController::class, 'approve']);
        Route::delete('/{id}', [\Modules\Business\Http\Controllers\ExpenseController::class, 'destroy']);
    });
});
