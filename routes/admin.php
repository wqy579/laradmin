<?php

use Illuminate\Support\Facades\Route;

// =============================================================================
// 内核级管理路由（不属于任何业务模块）
//
// 这两个闭包路由跨模块执行 Artisan 命令，不归属任何单一模块，因此留在内核。
// 信封（api + stock.snapshot + /admin 前缀 + admin. 命名）由 bootstrap/app.php 统一施加。
// 路由表变更由 tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// =============================================================================

// 导入旧系统数据
Route::post('/import/old-system', function () {
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $output = $kernel->call('import:old-system');
    
    return response()->json([
        'status' => 0,
        'message' => '数据导入成功',
        'output' => $output
    ]);
})->middleware(['auth.check:admin', 'log.request']);

// 远程执行数据库迁移（只跑 Business 模块，保持改造前语义；不带 --path 则跑全部模块）
Route::post('/remote/migrate', function () {
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $output = $kernel->call('migrate', [
        '--path' => 'modules/Business/database/migrations',
        '--force' => true,
    ]);
    
    return response()->json([
        'status' => 0,
        'message' => '数据库迁移执行成功',
        'output' => $output
    ]);
})->middleware(['auth.check:admin', 'log.request']);
