<?php

use Illuminate\Support\Facades\Route;
use Modules\Business\Http\Controllers\AttendanceController;
use Modules\Business\Http\Controllers\EmployeeController;
use Modules\Business\Http\Controllers\ExpenseController;
use Modules\Business\Http\Controllers\FinanceBalanceController;
use Modules\Business\Http\Controllers\FinanceFundOrderController;
use Modules\Business\Http\Controllers\FinanceJournalController;
use Modules\Business\Http\Controllers\FinanceMonthCloseController;
use Modules\Business\Http\Controllers\FinanceStatementController;
use Modules\Business\Http\Controllers\FinanceSubjectChangeController;
use Modules\Business\Http\Controllers\FinanceVoucherController;
use Modules\Business\Http\Controllers\GeneralExpenseController;
use Modules\Business\Http\Controllers\OtherIncomeController;
use Modules\Business\Http\Controllers\PayableController;
use Modules\Business\Http\Controllers\PaymentController;
use Modules\Business\Http\Controllers\PriceBatchController;
use Modules\Business\Http\Controllers\ReceivableController;
use Modules\Business\Http\Controllers\SupplierPaymentController;

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
        Route::get('/', [EmployeeController::class, 'index']);
        Route::get('/{employee}', [EmployeeController::class, 'show']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::put('/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/{employee}', [EmployeeController::class, 'destroy']);
        Route::post('/batch-delete', [EmployeeController::class, 'batchDelete']);
        Route::post('/batch-status', [EmployeeController::class, 'batchUpdateStatus']);
    });
    // 考勤管理
    Route::prefix('business/attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index']);
        // 前端 business.js 的 attendance.statistics 声明了它，控制器方法也在，
        // 唯独漏了路由——与其他业务模块（订单/库存/调拨）的 /statistics 口径不一致。
        Route::get('/statistics', [AttendanceController::class, 'statistics']);
        Route::get('/{attendance}', [AttendanceController::class, 'show']);
        Route::post('/', [AttendanceController::class, 'store']);
        Route::put('/{attendance}', [AttendanceController::class, 'update']);
        Route::delete('/{attendance}', [AttendanceController::class, 'destroy']);
    });
    // 费用管理
    Route::prefix('business/expense')->group(function () {
        Route::get('/', [ExpenseController::class, 'index']);
        Route::get('/statistics', [ExpenseController::class, 'statistics']);
        Route::get('/{id}', [ExpenseController::class, 'show']);
        Route::post('/', [ExpenseController::class, 'store']);
        Route::put('/{id}', [ExpenseController::class, 'update']);
        Route::post('/{id}/approve', [ExpenseController::class, 'approve']);
        Route::delete('/{id}', [ExpenseController::class, 'destroy']);
    });
    // 财务：应收余额
    Route::prefix('business/receivable')->group(function () {
        Route::get('/', [ReceivableController::class, 'index']);
    });
    // 财务：应付余额
    Route::prefix('business/payable')->group(function () {
        Route::get('/', [PayableController::class, 'index']);
    });
    // 财务：付款单
    Route::prefix('business/payment')->group(function () {
        Route::get('/', [PaymentController::class, 'index']);
        Route::get('/statistics', [PaymentController::class, 'statistics']);
    });
    // 财务：供应商付款
    Route::prefix('business/supplier-payment')->group(function () {
        Route::get('/', [SupplierPaymentController::class, 'index']);
        Route::get('/statistics', [SupplierPaymentController::class, 'statistics']);
    });
    // 财务：一般费用单
    Route::prefix('business/general-expense')->group(function () {
        Route::get('/', [GeneralExpenseController::class, 'index']);
        Route::get('/statistics', [GeneralExpenseController::class, 'statistics']);
    });
    // 财务：其它收入单（兼容单复数两种前端路径）
    Route::prefix('business/other-income')->group(function () {
        Route::get('/', [OtherIncomeController::class, 'index']);
        Route::get('/statistics', [OtherIncomeController::class, 'statistics']);
    });
    Route::prefix('business/other-incomes')->group(function () {
        Route::get('/', [OtherIncomeController::class, 'index']);
        Route::get('/statistics', [OtherIncomeController::class, 'statistics']);
    });
    // 财务：批量调价
    Route::prefix('business/price-batch')->group(function () {
        Route::get('/', [PriceBatchController::class, 'index']);
        Route::post('/batch', [PriceBatchController::class, 'batch']);
    });
    // 财务日记账（B1）：现金/银行日记账查询视图
    Route::prefix('business/finance-journal')->group(function () {
        Route::get('/', [FinanceJournalController::class, 'index']);
    });
    // 财务凭证（B1）：凭证查询视图（录入/审核走 ledger 视角）
    Route::prefix('business/finance-voucher')->group(function () {
        Route::get('/', [FinanceVoucherController::class, 'index']);
    });
    // 财务月结（B2）：月结向导 + 月结查询（复用 ledger PeriodService）
    Route::prefix('business/finance-month-close')->group(function () {
        Route::get('/', [FinanceMonthCloseController::class, 'index']);
        Route::get('/check/{period}', [FinanceMonthCloseController::class, 'check']);
        Route::post('/close/{period}', [FinanceMonthCloseController::class, 'close']);
        Route::post('/reopen/{period}', [FinanceMonthCloseController::class, 'reopen']);
    });
    // 财务对账单（B3）：客户应收 / 供应商应付 对账单查询视图（读 ledger 往来科目）
    Route::prefix('business/finance-statement')->group(function () {
        Route::get('/', [FinanceStatementController::class, 'index']);
    });
    // 财务余额查询（B3）：客户 / 品牌 / 辅助单位 余额查询视图（读 ledger 分录聚合）
    Route::prefix('business/finance-balance')->group(function () {
        Route::get('/', [FinanceBalanceController::class, 'index']);
    });
    // 财务科目变化明细（B3）：逐科目流水与滚动余额（读 ledger 分录）
    Route::prefix('business/finance-subject-change')->group(function () {
        Route::get('/', [FinanceSubjectChangeController::class, 'index']);
    });
    // 财务资金单族（B4）：6 类资金单据统一 CRUD + 确认/审核/驳回/还款
    // {type} = iou|borrow_repay|fund_io|arap_adjust|customer_balance|supplier_balance
    Route::prefix('business/finance-fund-orders')->group(function () {
        Route::get('/{type}', [FinanceFundOrderController::class, 'index']);
        Route::post('/{type}', [FinanceFundOrderController::class, 'store']);
        Route::get('/{type}/{id}', [FinanceFundOrderController::class, 'show']);
        Route::post('/{type}/{id}/confirm', [FinanceFundOrderController::class, 'confirm']);
        Route::post('/{type}/{id}/approve', [FinanceFundOrderController::class, 'approve']);
        Route::post('/{type}/{id}/reject', [FinanceFundOrderController::class, 'reject']);
        Route::post('/{type}/{id}/repay', [FinanceFundOrderController::class, 'repay']);
    });
});
