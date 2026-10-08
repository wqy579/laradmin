<?php

use Illuminate\Support\Facades\Route;
use Modules\Miniapp\Http\Controllers\MiniappSettingController;

// =============================================================================
// 小程序模块（Miniapp）路由
//
// 领域边界：小程序后台配置（基本设置 / 首页装修 / 商品分类 / 支付 / 消息推送 /
//           配送 / 会员 / 优惠券 / 关于我们）
//
// 只做配置的读写，不下发任何小程序端业务数据——那是小程序自己的接口的事。
// URL 前缀沿用 business/*：菜单 miniapp.setting 的 path 早已按此注册。
// =============================================================================

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    Route::prefix('business/mini-program-settings')->group(function () {
        Route::get('/', [MiniappSettingController::class, 'index']);
        Route::put('{group}', [MiniappSettingController::class, 'update']);
        Route::post('{group}/reset', [MiniappSettingController::class, 'reset']);
    });
});
