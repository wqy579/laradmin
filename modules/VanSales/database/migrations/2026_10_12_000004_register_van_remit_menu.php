<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册车销「上交货款」子菜单。
 *
 * 幂等：按 name 判重再插入；已拥有 van 权限的角色自动补子菜单授权。
 * 注：菜单最终在 BusinessSeeder 里定义（AuthSeeder 会 truncate），本 migration
 * 仅用于已部署环境在 seed 之前刷新菜单。
 */
return new class extends Migration
{
    public function up(): void
    {
        $vanId = DB::table('auth_permission')->where('name', 'van')->value('id');

        $children = [
            ['name' => 'van.remit', 'title' => '上交货款', 'path' => '/business/van-remit', 'component' => 'business/van-remit/index', 'sort' => 10],
        ];

        foreach ($children as $child) {
            $exists = DB::table('auth_permission')->where('name', $child['name'])->exists();
            if (! $exists) {
                DB::table('auth_permission')->insert([
                    'title' => $child['title'],
                    'name' => $child['name'],
                    'type' => 'menu',
                    'parent_id' => $vanId ?? 0,
                    'path' => $child['path'],
                    'component' => $child['component'],
                    'meta' => json_encode([]),
                    'sort' => $child['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $menuId = DB::table('auth_permission')->where('name', $child['name'])->value('id');
            if ($menuId) {
                $roleIds = DB::table('auth_role_permission as rp')
                    ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                    ->where(function ($q) {
                        $q->where('p.name', 'van')->orWhere('p.name', 'like', 'van.%');
                    })
                    ->pluck('rp.role_id');

                foreach ($roleIds as $roleId) {
                    $has = DB::table('auth_role_permission')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $menuId)
                        ->exists();
                    if (! $has) {
                        DB::table('auth_role_permission')->insert([
                            'role_id' => $roleId,
                            'permission_id' => $menuId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $names = ['van.remit'];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
