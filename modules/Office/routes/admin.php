<?php

use Illuminate\Support\Facades\Route;
use Modules\Office\Http\Controllers\MailController;
use Modules\Office\Http\Controllers\NoticeController;

// =============================================================================
// 办公模块（Office）路由
//
// 领域边界：内部邮件（收/发/草稿/回收站）+ 公司公告
//
// 信封由 bootstrap/app.php 统一施加，本文件只声明 Office 自己的路由。
// URL 前缀沿用 business/*：菜单 path 早已按此注册（office.mail / office.notice）。
// =============================================================================

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 内部邮件
    Route::prefix('business/mail')->group(function () {
        // 静态路由必须先于 {mail}，否则会被当成 id 吞掉
        Route::get('unread-count', [MailController::class, 'unreadCount']);
        Route::get('contacts', [MailController::class, 'contacts']);
        Route::post('batch-read', [MailController::class, 'batchRead']);
        Route::post('batch-unread', [MailController::class, 'batchUnread']);
        Route::post('batch-delete', [MailController::class, 'batchDelete']);

        Route::get('/', [MailController::class, 'index']);
        Route::post('/', [MailController::class, 'store']);
        Route::get('{mail}', [MailController::class, 'show']);
        Route::put('{mail}', [MailController::class, 'update']);
        Route::delete('{mail}', [MailController::class, 'destroy']);
        Route::post('{mail}/read', [MailController::class, 'markRead']);
        Route::post('{mail}/unread', [MailController::class, 'markUnread']);
    });

    // 公司公告
    Route::prefix('business/notice')->group(function () {
        Route::get('/', [NoticeController::class, 'index']);
        Route::post('/', [NoticeController::class, 'store']);
        Route::get('{notice}', [NoticeController::class, 'show']);
        Route::put('{notice}', [NoticeController::class, 'update']);
        Route::delete('{notice}', [NoticeController::class, 'destroy']);
    });
});
