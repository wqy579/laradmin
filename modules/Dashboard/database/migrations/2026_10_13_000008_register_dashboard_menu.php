<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「智慧大屏」顶级菜单（新窗口打开全屏大屏）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('auth_permission')->where('name', 'dashboard')->exists()) {
            return;
        }

        $id = DB::table('auth_permission')->insertGetId([
            'title' => '智慧大屏',
            'name' => 'dashboard',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/dashboard',
            'component' => 'dashboard/index',
            'meta' => json_encode(['openWindow' => true, 'icon' => 'DataLine']),
            'sort' => 90,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 给所有角色授权，确保大屏可见
        $roleIds = DB::table('auth_role')->pluck('id');
        foreach ($roleIds as $roleId) {
            if (! DB::table('auth_role_permission')->where('role_id', $roleId)->where('permission_id', $id)->exists()) {
                DB::table('auth_role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('auth_permission')->where('name', 'dashboard')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
