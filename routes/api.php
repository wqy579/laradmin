<?php

use Modules\System\Http\Controllers\Api\PingController;
use Modules\System\Http\Controllers\Api\Upload;
use Illuminate\Support\Facades\Route;

// 测试接口 / 导入状态 / 部署占位提示（均为无副作用的 JSON 返回）
Route::get('/test', [PingController::class, 'ping']);
Route::get('/import/status', [PingController::class, 'importStatus']);
Route::post('/deploy', [PingController::class, 'deployNotice']);

// 系统API
Route::prefix('system')->group(function () {
    // 文件上传
    Route::post('/upload', [Upload::class, 'upload']);
});
