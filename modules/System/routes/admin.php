<?php

use Illuminate\Support\Facades\Route;

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
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Config::class, 'index']);
        Route::get('/all', [\Modules\System\Http\Controllers\Admin\Config::class, 'all']);
        Route::get('/tree', [\Modules\System\Http\Controllers\Admin\Config::class, 'tree']);
        Route::get('/groups', [\Modules\System\Http\Controllers\Admin\Config::class, 'groups']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Config::class, 'show']);
        Route::post('/', [\Modules\System\Http\Controllers\Admin\Config::class, 'store']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\Config::class, 'update']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Config::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Config::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\System\Http\Controllers\Admin\Config::class, 'batchUpdateStatus']);
        Route::post('/batch-save', [\Modules\System\Http\Controllers\Admin\Config::class, 'batchSave']);
    });
    // 系统模块：日志
    Route::prefix('system/log')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Log::class, 'index']);
        Route::get('/statistics', [\Modules\System\Http\Controllers\Admin\Log::class, 'getStatistics']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Log::class, 'show']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Log::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Log::class, 'batchDelete']);
        Route::post('/clear', [\Modules\System\Http\Controllers\Admin\Log::class, 'clearLogs']);
    });
    // 系统模块：字典
    Route::prefix('system/dictionary')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'index']);
        Route::get('/all', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'all']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'show']);
        Route::post('/', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'store']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'update']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'batchDelete']);
        Route::post('/batch-status', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'batchUpdateStatus']);
    });
    // 系统模块：字典项
    Route::prefix('system/dictionary-item')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'getItemsList']);
        Route::get('/all', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'getAllItems']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'showItem']);
        Route::post('/', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'storeItem']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'updateItem']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'destroyItem']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'batchDeleteItems']);
        Route::post('/batch-status', [\Modules\System\Http\Controllers\Admin\Dictionary::class, 'batchUpdateItemsStatus']);
    });
    // 系统模块：定时调度
    Route::prefix('system/scheduled')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'index']);
        Route::get('/all', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'all']);
        Route::get('/statistics', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'statistics']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'show']);
        Route::post('/', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'store']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'update']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'batchDelete']);
        Route::post('/{id}/start', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'start']);
        Route::post('/{id}/pause', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'pause']);
        Route::post('/{id}/resume', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'resume']);
        Route::post('/{id}/stop', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'stop']);
        Route::post('/{id}/run', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'runNow']);
        Route::get('/{id}/logs', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'executionLogs']);
        Route::delete('/{id}/logs', [\Modules\System\Http\Controllers\Admin\ScheduledController::class, 'clearLogs']);
    });
    // 系统模块：附件
    Route::prefix('system/attachment')->group(function () {
        Route::get('/', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'index']);
        Route::get('/directories', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'directories']);
        Route::get('/statistics', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'statistics']);
        Route::get('/type-distribution', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'typeDistribution']);
        Route::get('/{id}', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'show']);
        Route::post('/get-by-ids', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'getByIds']);
        Route::put('/{id}', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'update']);
        Route::delete('/{id}', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'destroy']);
        Route::post('/batch-delete', [\Modules\System\Http\Controllers\Admin\Attachment::class, 'batchDelete']);
    });
    // 系统模块：上传
    Route::prefix('system/upload')->group(function () {
        Route::post('/', [\Modules\System\Http\Controllers\Admin\Upload::class, 'upload']);
        Route::post('/multiple', [\Modules\System\Http\Controllers\Admin\Upload::class, 'uploadMultiple']);
        Route::post('/base64', [\Modules\System\Http\Controllers\Admin\Upload::class, 'uploadBase64']);
        Route::post('/delete', [\Modules\System\Http\Controllers\Admin\Upload::class, 'delete']);
        // 分片上传
        Route::post('/chunk/init', [\Modules\System\Http\Controllers\Admin\Upload::class, 'initChunk']);
        Route::post('/chunk/upload', [\Modules\System\Http\Controllers\Admin\Upload::class, 'uploadChunk']);
        Route::post('/chunk/merge', [\Modules\System\Http\Controllers\Admin\Upload::class, 'mergeChunks']);
        Route::get('/chunk/uploaded', [\Modules\System\Http\Controllers\Admin\Upload::class, 'getUploadedChunks']);
        Route::post('/chunk/cancel', [\Modules\System\Http\Controllers\Admin\Upload::class, 'cancelChunk']);
    });
});

