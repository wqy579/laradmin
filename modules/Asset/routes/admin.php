<?php

use Illuminate\Support\Facades\Route;
use Modules\Asset\Http\Controllers\AssetCardController;
use Modules\Asset\Http\Controllers\AssetPurchaseController;

/**
 * 固定资产模块（Asset）路由 — D3
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
});
