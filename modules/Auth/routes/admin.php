<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\Admin\Auth;
use Modules\Auth\Http\Controllers\Admin\Department;
use Modules\Auth\Http\Controllers\Admin\Permission;
use Modules\Auth\Http\Controllers\Admin\Role;
use Modules\Auth\Http\Controllers\Admin\User;

// =============================================================================
// 权限模块（Auth）路由
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Auth 模块自己的路由。路由归属变更请用
// tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// =============================================================================

// 登录：匿名可访问，单独限流，不进 admin 鉴权组
Route::post('/auth/login', [Auth::class, 'login'])
    ->middleware('rate.limit:login');

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 认证：登出/刷新/当前用户/修改密码
    Route::post('/auth/logout', [Auth::class, 'logout']);
    Route::post('/auth/refresh', [Auth::class, 'refresh']);
    Route::get('/auth/permissions/menu', [Auth::class, 'menu']);
    Route::get('/auth/me', [Auth::class, 'me']);
    Route::put('/auth/me', [Auth::class, 'updateMe']);
    Route::post('/auth/change-password', [Auth::class, 'changePassword']);
    // 权限模块
    Route::prefix('auth')->group(function () {
        Route::get('/users', [User::class, 'index']);
        Route::get('/users/{id}', [User::class, 'show']);
        Route::post('/users', [User::class, 'store']);
        Route::put('/users/{id}', [User::class, 'update']);
        Route::delete('/users/{id}', [User::class, 'destroy']);
        Route::post('/users/batch-delete', [User::class, 'batchDelete']);
        Route::post('/users/batch-status', [User::class, 'batchUpdateStatus']);
        Route::post('/users/batch-department', [User::class, 'batchAssignDepartment']);
        Route::post('/users/batch-roles', [User::class, 'batchAssignRoles']);
        Route::post('/users/import', [User::class, 'import']);
        // 导出是异步任务提交：s-export 组件把 {fields, filters} 作为 JSON body 发上来，
        // 前端 auth.js 也是按 POST 调用的。此前这里声明成 GET，请求方法对不上必然 404。
        Route::post('/users/export', [User::class, 'export']);
        Route::post('/users/{id}/reset-password', [User::class, 'resetPassword']);

        Route::get('/roles', [Role::class, 'index']);
        Route::get('/roles/all', [Role::class, 'getAll']);
        Route::get('/roles/{id}', [Role::class, 'show']);
        Route::post('/roles', [Role::class, 'store']);
        Route::put('/roles/{id}', [Role::class, 'update']);
        Route::delete('/roles/{id}', [Role::class, 'destroy']);
        Route::post('/roles/batch-delete', [Role::class, 'batchDelete']);
        Route::post('/roles/batch-status', [Role::class, 'batchUpdateStatus']);
        Route::post('/roles/assign-permissions', [Role::class, 'assignPermissions']);

        Route::get('/permissions', [Permission::class, 'index']);
        Route::get('/permissions/tree', [Permission::class, 'tree']);
        // 前端 auth.js 的 permission.detail 一直按这个路径调用，此前唯独漏了路由声明，
        // 控制器与 Service 的方法都在，调用却必然 404。
        Route::get('/permissions/{id}', [Permission::class, 'show']);
        Route::post('/permissions', [Permission::class, 'store']);
        Route::put('/permissions/{id}', [Permission::class, 'update']);
        Route::delete('/permissions/{id}', [Permission::class, 'destroy']);
        Route::post('/permissions/batch-delete', [Permission::class, 'batchDelete']);
        Route::post('/permissions/update-icons', [Permission::class, 'updateIcons']);

        Route::get('/departments', [Department::class, 'index']);
        Route::get('/departments/tree', [Department::class, 'tree']);
        Route::get('/departments/all', [Department::class, 'getAll']);
        Route::get('/departments/{id}', [Department::class, 'show']);
        Route::post('/departments', [Department::class, 'store']);
        Route::put('/departments/{id}', [Department::class, 'update']);
        Route::delete('/departments/{id}', [Department::class, 'destroy']);
        Route::post('/departments/batch-delete', [Department::class, 'batchDelete']);
        Route::post('/departments/batch-status', [Department::class, 'batchUpdateStatus']);
    });
});
