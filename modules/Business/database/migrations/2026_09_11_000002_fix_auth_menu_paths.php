<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 修复权限管理菜单路径：fix_menu_paths 迁移用单数 name（auth.department）匹配，
     * 但 auth_permission 表中 name 已是复数（auth.departments），导致 4 条权限管理
     * 菜单路径未修复，菜单点击仍指向旧单数路径而 404。
     */
    public function up(): void
    {
        $fixes = [
            ['name' => 'auth.users',       'new_path' => '/auth/users'],
            ['name' => 'auth.roles',       'new_path' => '/auth/roles'],
            ['name' => 'auth.permissions', 'new_path' => '/auth/permissions'],
            ['name' => 'auth.departments', 'new_path' => '/auth/departments'],
        ];

        foreach ($fixes as $fix) {
            $affected = DB::table('auth_permission')
                ->where('name', $fix['name'])
                ->update(['path' => $fix['new_path']]);
            echo "  [{$fix['name']}] -> {$fix['new_path']} (affected: $affected)\n";
        }
    }

    public function down(): void
    {
        $reverts = [
            ['name' => 'auth.users',       'new_path' => '/auth/user'],
            ['name' => 'auth.roles',       'new_path' => '/auth/role'],
            ['name' => 'auth.permissions', 'new_path' => '/auth/permission'],
            ['name' => 'auth.departments', 'new_path' => '/auth/department'],
        ];

        foreach ($reverts as $rev) {
            DB::table('auth_permission')
                ->where('name', $rev['name'])
                ->update(['path' => $rev['new_path']]);
        }
    }
};
