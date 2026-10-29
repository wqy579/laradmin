<?php

use Illuminate\Support\Facades\Route;
use Modules\Asset\Http\Controllers\AssetCardController;
use Modules\Asset\Http\Controllers\AssetDepreciationController;
use Modules\Asset\Http\Controllers\AssetPurchaseController;
use Modules\Asset\Http\Controllers\AssetSaleController;

/**
 * 固定资产模块（Asset）路由 — D3 + D4
 *
 * D 域物理隔离：统一前缀 /admin/asset，内核信封已施加 api + stock.snapshot
 * 与 /admin 前缀、admin. 命名，本文件再叠 auth.check:admin + log.request。
 */
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // ===== 资产购置单（左栏表单 + 右栏购置流水）=====
    Route::prefix('asset/purchases')->group(function () {
        Route::get('/options', [AssetPurchaseController::class, 'options'])->name('asset.purchases.options');
        Route::get('/', [AssetPurchaseController::class, 'index'])->name('asset.purchases.index');
        Route::post('/', [AssetPurchaseController::class, 'store'])->name('asset.purchases.store');
    });

    // ===== 固定资产台账（卡片/列表双视图共用）=====
    Route::prefix('asset/cards')->group(function () {
        Route::get('/options', [AssetCardController::class, 'options'])->name('asset.cards.options');
        Route::get('/', [AssetCardController::class, 'index'])->name('asset.cards.index');
    });

    // ===== 资产折旧（左栏本期应折明细 + 右栏执行流水）=====
    Route::prefix('asset/depreciations')->group(function () {
        Route::get('/preview', [AssetDepreciationController::class, 'preview'])->name('asset.depreciations.preview');
        Route::get('/runs', [AssetDepreciationController::class, 'runs'])->name('asset.depreciations.runs');
        Route::get('/records', [AssetDepreciationController::class, 'records'])->name('asset.depreciations.records');
        Route::post('/', [AssetDepreciationController::class, 'store'])->name('asset.depreciations.store');
    });

    // ===== 资产变卖（左栏表单 + 右栏变卖流水）=====
    Route::prefix('asset/sales')->group(function () {
        Route::get('/options', [AssetSaleController::class, 'options'])->name('asset.sales.options');
        Route::get('/', [AssetSaleController::class, 'index'])->name('asset.sales.index');
        Route::post('/', [AssetSaleController::class, 'store'])->name('asset.sales.store');
    });
});
