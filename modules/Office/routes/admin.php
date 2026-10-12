<?php

use Illuminate\Support\Facades\Route;
use Modules\Office\Http\Controllers\MailController;
use Modules\Office\Http\Controllers\MessageController;
use Modules\Office\Http\Controllers\NoticeController;

// =============================================================================
// 办公模块（Office）路由
//
// 领域边界：内部邮件（收/发/草稿/回收站）+ 公司公告 + 站内消息（铃铛/消息中心）
//
// 信封由 bootstrap/app.php 统一施加，本文件只声明 Office 自己的路由。
// URL 前缀沿用 business/*：菜单 path 早已按此注册（office.mail / office.notice）。
// =============================================================================

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 站内消息（消息通知中心）
    //
    // 前缀用 business/messages 而不是顶层 messages：线上 nginx 用「正则代理 +
    // try_files 兜底」，只有显式列进白名单的前缀（auth/system/business/dashboard
    // /permissions）才会 proxy_pass 到 laravel-s，其余落到 /admin/index.html（SPA
    // 入口）。2026-10 已因此出过一次事故（dashboard 用新顶层前缀，被兜成 517 字节的
    // HTML，接口全线失效而部署仍标绿）。本模块的邮件/公告都挂在 business/ 下，消息
    // 跟着它们走同一前缀，既符合约定，又天然在白名单内。
    //
    // 静态路由（unread-count / read-all）必须先于 {message}，否则会被当成 id 吞掉
    Route::prefix('business/messages')->group(function () {
        Route::get('unread-count', [MessageController::class, 'unreadCount']);
        Route::post('read-all', [MessageController::class, 'markAllAsRead']);
        Route::get('/', [MessageController::class, 'index']);
        Route::post('{message}/read', [MessageController::class, 'markAsRead']);
        Route::delete('{message}', [MessageController::class, 'destroy']);
    });

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
        // 静态路由必须先于 {notice}，否则会被当成 id 吞掉
        Route::get('popup-pending', [NoticeController::class, 'popupPending']);
        Route::get('/', [NoticeController::class, 'index']);
        Route::post('/', [NoticeController::class, 'store']);
        Route::get('{notice}', [NoticeController::class, 'show']);
        Route::put('{notice}', [NoticeController::class, 'update']);
        Route::post('{notice}/publish', [NoticeController::class, 'publish']);
        Route::delete('{notice}', [NoticeController::class, 'destroy']);
    });
});
