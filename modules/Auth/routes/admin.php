<?php

use Illuminate\Support\Facades\Route;

// =============================================================================
// 权限模块（Auth）路由
//
// 由 bootstrap/app.php 统一套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
// 本文件只声明 Auth 模块自己的路由。路由归属变更请用
// tests/Feature/RouteBaselineTest.php 的快照兜底校验。
// =============================================================================

// 登录：匿名可访问，单独限流，不进 admin 鉴权组
Route::post('/auth/login', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'login'])
    ->middleware('rate.limit:login');

Route::middleware(['auth.check:admin', 'log.request'])->group(function () {
    // 认证：登出/刷新/当前用户/修改密码
    Route::post('/auth/logout', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'logout']);
    Route::post('/auth/refresh', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'refresh']);
    Route::get('/auth/permissions/menu', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'menu']);
    Route::get('/auth/me', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'me']);
    Route::put('/auth/me', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'updateMe']);
    Route::post('/auth/change-password', [\Modules\Auth\Http\Controllers\Admin\Auth::class, 'changePassword']);
    // 权限模块
    Route::prefix('auth')->group(function () {
        Route::get('/users', [\Modules\Auth\Http\Controllers\Admin\User::class, 'index']);
        Route::get('/users/{id}', [\Modules\Auth\Http\Controllers\Admin\User::class, 'show']);
        Route::post('/users', [\Modules\Auth\Http\Controllers\Admin\User::class, 'store']);
        Route::put('/users/{id}', [\Modules\Auth\Http\Controllers\Admin\User::class, 'update']);
        Route::delete('/users/{id}', [\Modules\Auth\Http\Controllers\Admin\User::class, 'destroy']);
        Route::post('/users/batch-delete', [\Modules\Auth\Http\Controllers\Admin\User::class, 'batchDelete']);
        Route::post('/users/batch-status', [\Modules\Auth\Http\Controllers\Admin\User::class, 'batchUpdateStatus']);
        Route::post('/users/batch-department', [\Modules\Auth\Http\Controllers\Admin\User::class, 'batchAssignDepartment']);
        Route::post('/users/batch-roles', [\Modules\Auth\Http\Controllers\Admin\User::class, 'batchAssignRoles']);
        Route::post('/users/import', [\Modules\Auth\Http\Controllers\Admin\User::class, 'import']);
        // 导出是异步任务提交：s-export 组件把 {fields, filters} 作为 JSON body 发上来，
        // 前端 auth.js 也是按 POST 调用的。此前这里声明成 GET，请求方法对不上必然 404。
        Route::post('/users/export', [\Modules\Auth\Http\Controllers\Admin\User::class, 'export']);
        Route::post('/users/{id}/reset-password', [\Modules\Auth\Http\Controllers\Admin\User::class, 'resetPassword']);

        Route::get('/roles', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'index']);
        Route::get('/roles/all', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'getAll']);
        Route::get('/roles/{id}', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'show']);
        Route::post('/roles', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'store']);
        Route::put('/roles/{id}', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'update']);
        Route::delete('/roles/{id}', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'destroy']);
        Route::post('/roles/batch-delete', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'batchDelete']);
        Route::post('/roles/batch-status', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'batchUpdateStatus']);
        Route::post('/roles/assign-permissions', [\Modules\Auth\Http\Controllers\Admin\Role::class, 'assignPermissions']);

        Route::get('/permissions', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'index']);
        Route::get('/permissions/tree', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'tree']);
        // 前端 auth.js 的 permission.detail 一直按这个路径调用，此前唯独漏了路由声明，
        // 控制器与 Service 的方法都在，调用却必然 404。
        Route::get('/permissions/{id}', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'show']);
        Route::post('/permissions', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'store']);
        Route::put('/permissions/{id}', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'update']);
        Route::delete('/permissions/{id}', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'destroy']);
        Route::post('/permissions/batch-delete', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'batchDelete']);
        Route::post('/permissions/update-icons', [\Modules\Auth\Http\Controllers\Admin\Permission::class, 'updateIcons']);

        Route::get('/departments', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'index']);
        Route::get('/departments/tree', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'tree']);
        Route::get('/departments/all', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'getAll']);
        Route::get('/departments/{id}', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'show']);
        Route::post('/departments', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'store']);
        Route::put('/departments/{id}', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'update']);
        Route::delete('/departments/{id}', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'destroy']);
        Route::post('/departments/batch-delete', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'batchDelete']);
        Route::post('/departments/batch-status', [\Modules\Auth\Http\Controllers\Admin\Department::class, 'batchUpdateStatus']);
    });
});

