<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

// 测试接口
Route::get('/test', function () {
    return response()->json([
        'status' => 0,
        'message' => 'success'
    ]);
});

// 系统API
Route::prefix('system')->group(function () {
    // 文件上传
    Route::post('/upload', [\App\Http\Controllers\System\Api\Upload::class, 'upload']);
});

// 导入旧系统数据状态
Route::get('/import/status', function () {
    return response()->json([
        'status' => 0,
        'message' => '数据导入功能已启用,请在服务器上执行: php artisan import:old-system',
        'note' => '导入命令会在每次部署后自动执行'
    ]);
});

// Gitee Webhook 自动部署
// 注意：实际部署由服务器本地脚本执行（见 README「部署机制」章节），
// 产出日志为 /115.191.21.67.deploy.log（中文格式，含 vite build 与 laravels reload）。
// 早期在此处的闭包实现已废弃：它只 git pull + migrate，不执行前端构建，
// 若被误触发会让服务器拉到新代码却仍服务旧的前端产物。此处保留路由仅作占位提示。
Route::post('/deploy', function () {
    return response()->json([
        'status' => 0,
        'message' => '部署由服务器部署脚本执行，不通过此接口。请检查 Gitee Web 钩子配置。',
    ]);
});
