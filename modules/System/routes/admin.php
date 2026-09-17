<?php

use Illuminate\Support\Facades\Route;
use Modules\System\Http\Controllers\Admin\Attachment;
use Modules\System\Http\Controllers\Admin\Config;
use Modules\System\Http\Controllers\Admin\Dictionary;
use Modules\System\Http\Controllers\Admin\Log;
use Modules\System\Http\Controllers\Admin\Notification;
use Modules\System\Http\Controllers\Admin\ScheduledController;
use Modules\System\Http\Controllers\Admin\Upload;

// =============================================================================
// 系统模块（System）路由
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 System 模块自己的路由。路由归属变更请用
// tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// =============================================================================
Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 系统模块：设置
    Route::prefix('system/setting')->group(function () {
        Route::get('/', [Config::class, 'index']);
        Route::get('/all', [Config::class, 'all']);
        Route::get('/tree', [Config::class, 'tree']);
        Route::get('/groups', [Config::class, 'groups']);
        Route::get('/{id}', [Config::class, 'show']);
        Route::post('/', [Config::class, 'store']);
        Route::put('/{id}', [Config::class, 'update']);
        Route::delete('/{id}', [Config::class, 'destroy']);
        Route::post('/batch-delete', [Config::class, 'batchDelete']);
        Route::post('/batch-status', [Config::class, 'batchUpdateStatus']);
        Route::post('/batch-save', [Config::class, 'batchSave']);
    });
    // 系统模块：日志
    Route::prefix('system/log')->group(function () {
        Route::get('/', [Log::class, 'index']);
        Route::get('/statistics', [Log::class, 'getStatistics']);
        Route::get('/{id}', [Log::class, 'show']);
        Route::delete('/{id}', [Log::class, 'destroy']);
        Route::post('/batch-delete', [Log::class, 'batchDelete']);
        Route::post('/clear', [Log::class, 'clearLogs']);
    });
    // 系统模块：字典
    Route::prefix('system/dictionary')->group(function () {
        Route::get('/', [Dictionary::class, 'index']);
        Route::get('/all', [Dictionary::class, 'all']);
        Route::get('/{id}', [Dictionary::class, 'show']);
        Route::post('/', [Dictionary::class, 'store']);
        Route::put('/{id}', [Dictionary::class, 'update']);
        Route::delete('/{id}', [Dictionary::class, 'destroy']);
        Route::post('/batch-delete', [Dictionary::class, 'batchDelete']);
        Route::post('/batch-status', [Dictionary::class, 'batchUpdateStatus']);
    });
    // 系统模块：字典项
    Route::prefix('system/dictionary-item')->group(function () {
        Route::get('/', [Dictionary::class, 'getItemsList']);
        Route::get('/all', [Dictionary::class, 'getAllItems']);
        Route::get('/{id}', [Dictionary::class, 'showItem']);
        Route::post('/', [Dictionary::class, 'storeItem']);
        Route::put('/{id}', [Dictionary::class, 'updateItem']);
        Route::delete('/{id}', [Dictionary::class, 'destroyItem']);
        Route::post('/batch-delete', [Dictionary::class, 'batchDeleteItems']);
        Route::post('/batch-status', [Dictionary::class, 'batchUpdateItemsStatus']);
    });
    // 系统模块：定时调度
    Route::prefix('system/scheduled')->group(function () {
        Route::get('/', [ScheduledController::class, 'index']);
        Route::get('/all', [ScheduledController::class, 'all']);
        Route::get('/statistics', [ScheduledController::class, 'statistics']);
        Route::get('/{id}', [ScheduledController::class, 'show']);
        Route::post('/', [ScheduledController::class, 'store']);
        Route::put('/{id}', [ScheduledController::class, 'update']);
        Route::delete('/{id}', [ScheduledController::class, 'destroy']);
        Route::post('/batch-delete', [ScheduledController::class, 'batchDelete']);
        Route::post('/{id}/start', [ScheduledController::class, 'start']);
        Route::post('/{id}/pause', [ScheduledController::class, 'pause']);
        Route::post('/{id}/resume', [ScheduledController::class, 'resume']);
        Route::post('/{id}/stop', [ScheduledController::class, 'stop']);
        Route::post('/{id}/run', [ScheduledController::class, 'runNow']);
        Route::get('/{id}/logs', [ScheduledController::class, 'executionLogs']);
        Route::delete('/{id}/logs', [ScheduledController::class, 'clearLogs']);
    });
    // 系统模块：附件
    Route::prefix('system/attachment')->group(function () {
        Route::get('/', [Attachment::class, 'index']);
        Route::get('/directories', [Attachment::class, 'directories']);
        Route::get('/statistics', [Attachment::class, 'statistics']);
        Route::get('/type-distribution', [Attachment::class, 'typeDistribution']);
        Route::get('/{id}', [Attachment::class, 'show']);
        Route::post('/get-by-ids', [Attachment::class, 'getByIds']);
        Route::put('/{id}', [Attachment::class, 'update']);
        Route::delete('/{id}', [Attachment::class, 'destroy']);
        Route::post('/batch-delete', [Attachment::class, 'batchDelete']);
    });
    // 系统模块：上传
    Route::prefix('system/upload')->group(function () {
        Route::post('/', [Upload::class, 'upload']);
        Route::post('/multiple', [Upload::class, 'uploadMultiple']);
        Route::post('/base64', [Upload::class, 'uploadBase64']);
        Route::post('/delete', [Upload::class, 'delete']);
        // 分片上传
        Route::post('/chunk/init', [Upload::class, 'initChunk']);
        Route::post('/chunk/upload', [Upload::class, 'uploadChunk']);
        Route::post('/chunk/merge', [Upload::class, 'mergeChunks']);
        Route::get('/chunk/uploaded', [Upload::class, 'getUploadedChunks']);
        Route::post('/chunk/cancel', [Upload::class, 'cancelChunk']);
    });
    // 系统模块：站内通知
    //
    // 这组接口此前完全没有路由声明——控制器与 Service 都在，前端
    // frontend/src/api/system.js 的 notification 块也一直按下面的路径调用，
    // 结果是全线 404，且无任何报错提示。路径与方法名逐条对齐前端声明。
    // 只补前端实际声明的 11 个接口；send / retryUnsent 目前没有调用方，
    // 先不暴露（retryUnsent 已有 console 命令与 notifications:retry-unsent 调度）。
    // 注意 /{id} 必须排在 /unread 等固定路径之后，否则 GET /unread 会被当成 id。
    Route::prefix('system/notification')->group(function () {
        Route::get('/', [Notification::class, 'index']);
        Route::get('/unread', [Notification::class, 'unread']);
        Route::get('/unread-count', [Notification::class, 'unreadCount']);
        Route::get('/statistics', [Notification::class, 'statistics']);
        Route::get('/{id}', [Notification::class, 'show']);
        Route::post('/{id}/read', [Notification::class, 'markAsRead']);
        Route::post('/batch-read', [Notification::class, 'batchMarkAsRead']);
        Route::post('/read-all', [Notification::class, 'markAllAsRead']);
        Route::post('/batch-delete', [Notification::class, 'batchDelete']);
        Route::post('/clear-read', [Notification::class, 'clearRead']);
        Route::delete('/{id}', [Notification::class, 'destroy']);
    });
});
