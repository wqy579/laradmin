<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// =============================================================================
// 内核级管理路由（不属于任何业务模块）
//
// 这条闭包路由跨模块执行 Artisan 命令，不归属任何单一模块，因此留在内核。
// 信封（api + stock.snapshot + /admin 前缀 + admin. 命名）由 bootstrap/app.php 统一施加。
// 路由表变更由 tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// 路由里引用的 Artisan 命令是否真的注册了，由该文件的
// test_artisan_commands_referenced_by_routes_are_registered 兜底。
//
// 曾有第二条 POST /admin/import/old-system 调用 `import:old-system`，但那条命令
// 在这个仓库里从未被实现（从 6b30f8b 全量导入时就带着这个引用，modules/ 下没有任何
// 同名 Command），一旦被调用必然抛 "Command import:old-system is not defined"。
// 前端与 deploy.yml 都没人用它，已删除。旧系统数据导入要落地时需要先把命令写出来。
// =============================================================================

// 远程执行数据库迁移（只跑 Business 模块，保持改造前语义；不带 --path 则跑全部模块）
Route::post('/remote/migrate', function () {
    $exit = Artisan::call('migrate', [
        '--path' => 'modules/Business/database/migrations',
        '--force' => true,
    ]);
    $output = Artisan::output();

    // 此前这里无条件返回「数据库迁移执行成功」——命令失败也报成功，调用方无从判断
    // schema 到底有没有变更。现在按退出码区分，并把原始输出一并返回便于排查。
    if ($exit !== 0) {
        return response()->json([
            'code' => 500,
            'message' => '数据库迁移执行失败',
            'data' => ['exit' => $exit, 'output' => $output],
        ], 500);
    }

    return response()->json([
        'code' => 200,
        'message' => '数据库迁移执行成功',
        'data' => ['exit' => $exit, 'output' => $output],
    ]);
})->middleware(['auth.check:admin', 'log.request']);
