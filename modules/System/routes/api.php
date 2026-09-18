<?php

use Illuminate\Support\Facades\Route;
use Modules\System\Http\Controllers\Api\PingController;
use Modules\System\Http\Controllers\Api\Upload;

// =============================================================================
// 系统模块（System）对外 API
//
// 由 bootstrap/app.php 统一套 api 信封（middleware 'api' + /api 前缀）。
// 路由表变更由 tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// =============================================================================

// 导入状态（无副作用的 JSON 返回）
Route::get('/import/status', [PingController::class, 'importStatus']);

// 系统API
Route::prefix('system')->group(function () {
    // 文件上传：与 admin 侧 system/upload 同权，必须管理员鉴权，并单独限流。
    // 此前这条是整套系统里唯一的无鉴权写接口（POST 即落盘 storage/app/public/uploads/，
    // 10MB/文件，无速率限制），等于把磁盘交给了任意匿名调用方。
    Route::post('/upload', [Upload::class, 'upload'])
        ->middleware(['auth.check:admin', 'rate.limit:upload']);
});
