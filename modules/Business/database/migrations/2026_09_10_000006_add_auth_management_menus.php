<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 确保权限管理菜单存在
        DB::table('auth_permission')->updateOrInsert(
            ['name' => 'auth.permissions'],
            [
                'title' => '权限管理',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/auth/permission',
                'component' => 'auth/permission/index',
                'meta' => json_encode(['icon' => 'ElIconLock']),
                'sort' => 1,
                'status' => 1,
            ]
        );

        // 确保角色管理菜单存在
        DB::table('auth_permission')->updateOrInsert(
            ['name' => 'auth.roles'],
            [
                'title' => '角色管理',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/auth/role',
                'component' => 'auth/role/index',
                'meta' => json_encode(['icon' => 'ElIconUserFilled']),
                'sort' => 2,
                'status' => 1,
            ]
        );

        // 确保用户管理菜单存在
        DB::table('auth_permission')->updateOrInsert(
            ['name' => 'auth.users'],
            [
                'title' => '用户管理',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/auth/user',
                'component' => 'auth/user/index',
                'meta' => json_encode(['icon' => 'ElIconUser']),
                'sort' => 3,
                'status' => 1,
            ]
        );

        // 确保部门管理菜单存在
        DB::table('auth_permission')->updateOrInsert(
            ['name' => 'auth.departments'],
            [
                'title' => '部门管理',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/auth/department',
                'component' => 'auth/department/index',
                'meta' => json_encode(['icon' => 'ElIconOfficeBuilding']),
                'sort' => 4,
                'status' => 1,
            ]
        );
    }

    public function down(): void
    {
        DB::table('auth_permission')->where('name', 'auth.permissions')->delete();
        DB::table('auth_permission')->where('name', 'auth.roles')->delete();
        DB::table('auth_permission')->where('name', 'auth.users')->delete();
        DB::table('auth_permission')->where('name', 'auth.departments')->delete();
    }
};
