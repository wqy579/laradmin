<?php

use Illuminate\Support\Facades\Route;
use Modules\Member\Http\Controllers\MemberConsumeLogController;
use Modules\Member\Http\Controllers\MemberController;
use Modules\Member\Http\Controllers\MemberLevelController;
use Modules\Member\Http\Controllers\MemberRechargeController;
use Modules\Member\Http\Controllers\PointOrderController;
use Modules\Member\Http\Controllers\PointProductController;
use Modules\Member\Http\Controllers\PointRuleController;

// =============================================================================
// 会员管理（Member）路由
//
// 领域边界：会员档案 / 会员等级 / 积分规则 / 储值充值 / 积分兑换 / 消费记录
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Member 模块自己的路由。
//
// URL 前缀沿用 business/member* 惯例（对齐 customer/supplier/sales-order），
// 前端按此前缀调用，避免为会员单独引入一套 URL 约定。
// =============================================================================

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 会员档案
    // 静态路由（statistics/search/by-customer/export/batch-status）必须先于 /{id}，
    // 否则会被路由参数吞掉（对齐 sales-order 的 recent-prices 写法）。
    Route::prefix('business/member')->group(function () {
        Route::get('/', [MemberController::class, 'index']);
        Route::get('/statistics', [MemberController::class, 'statistics']);
        Route::get('/search', [MemberController::class, 'search']);
        Route::get('/by-customer', [MemberController::class, 'byCustomer']);
        Route::get('/export', [MemberController::class, 'export']);
        Route::post('/batch-status', [MemberController::class, 'batchStatus']);
        Route::get('/{id}', [MemberController::class, 'show']);
        Route::post('/', [MemberController::class, 'store']);
        Route::put('/{id}', [MemberController::class, 'update']);
        Route::delete('/{id}', [MemberController::class, 'destroy']);
        Route::post('/{id}/freeze', [MemberController::class, 'freeze']);
        Route::post('/{id}/unfreeze', [MemberController::class, 'unfreeze']);
        Route::get('/{id}/logs', [MemberController::class, 'memberLogs']);
    });

    // 会员等级
    Route::prefix('business/member-level')->group(function () {
        Route::get('/', [MemberLevelController::class, 'index']);
        Route::post('/', [MemberLevelController::class, 'store']);
        Route::put('/{id}', [MemberLevelController::class, 'update']);
        Route::delete('/{id}', [MemberLevelController::class, 'destroy']);
        Route::post('/{id}/enable', [MemberLevelController::class, 'enable']);
        Route::post('/{id}/disable', [MemberLevelController::class, 'disable']);
    });

    // 积分规则
    Route::prefix('business/point-rule')->group(function () {
        Route::get('/', [PointRuleController::class, 'index']);
        Route::put('/', [PointRuleController::class, 'update']);
        Route::post('/reset', [PointRuleController::class, 'reset']);
        Route::get('/{key}', [PointRuleController::class, 'show']);
    });

    // 储值充值
    Route::prefix('business/member-recharge')->group(function () {
        Route::get('/', [MemberRechargeController::class, 'index']);
        Route::get('/statistics', [MemberRechargeController::class, 'statistics']);
        Route::get('/{id}', [MemberRechargeController::class, 'show']);
        Route::post('/', [MemberRechargeController::class, 'store']);
        Route::put('/{id}', [MemberRechargeController::class, 'update']);
        Route::delete('/{id}', [MemberRechargeController::class, 'destroy']);
        Route::post('/{id}/approve', [MemberRechargeController::class, 'approve']);
        Route::post('/{id}/reject', [MemberRechargeController::class, 'reject']);
        Route::post('/{id}/cancel', [MemberRechargeController::class, 'cancel']);
    });

    // 积分兑换商品
    Route::prefix('business/point-product')->group(function () {
        Route::get('/', [PointProductController::class, 'index']);
        Route::get('/on-shelf', [PointProductController::class, 'onShelf']);
        Route::get('/{id}', [PointProductController::class, 'show']);
        Route::post('/', [PointProductController::class, 'store']);
        Route::put('/{id}', [PointProductController::class, 'update']);
        Route::delete('/{id}', [PointProductController::class, 'destroy']);
        Route::post('/{id}/on-shelf', [PointProductController::class, 'onShelfAction']);
        Route::post('/{id}/off-shelf', [PointProductController::class, 'offShelfAction']);
    });

    // 积分兑换订单
    Route::prefix('business/point-order')->group(function () {
        Route::get('/', [PointOrderController::class, 'index']);
        Route::get('/statistics', [PointOrderController::class, 'statistics']);
        Route::get('/{id}', [PointOrderController::class, 'show']);
        Route::post('/', [PointOrderController::class, 'store']);
        Route::post('/{id}/approve', [PointOrderController::class, 'approve']);
        Route::post('/{id}/reject', [PointOrderController::class, 'reject']);
        Route::post('/{id}/cancel', [PointOrderController::class, 'cancel']);
        Route::post('/{id}/ship', [PointOrderController::class, 'ship']);
        Route::post('/{id}/complete', [PointOrderController::class, 'complete']);
    });

    // 会员消费记录
    Route::prefix('business/member-consume-log')->group(function () {
        Route::get('/', [MemberConsumeLogController::class, 'index']);
        Route::get('/statistics', [MemberConsumeLogController::class, 'statistics']);
        Route::get('/{id}', [MemberConsumeLogController::class, 'show']);
    });
});
