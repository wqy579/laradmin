<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册车销阶段二菜单：退货单 + 借货单 + 还货单 + 换货单。
 *
 * 幂等：按 name 判重再插入；已拥有 van 顶级权限的角色自动补子菜单授权。
 * 顶级「车销业务」由 2026_10_10_000003_register_van_menu.php 注册，本迁移
 * 只补阶段二的 4 个子菜单（sort 5-8，排在车上库存之后）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 顶级菜单必须已存在（阶段一注册）；缺失时本迁移仍能跑，但子菜单会挂到空 parent_id
        $vanId = DB::table('auth_permission')->where('name', 'van')->value('id');

        $children = [
            ['name' => 'van.return-order', 'title' => '车销退货单', 'path' => '/business/van-return-order', 'component' => 'business/van-return-order/index', 'sort' => 5],
            ['name' => 'van.borrow-order', 'title' => '车销借货单', 'path' => '/business/van-borrow-order', 'component' => 'business/van-borrow-order/index', 'sort' => 6],
            ['name' => 'van.return-borrow-order', 'title' => '车销还货单', 'path' => '/business/van-return-borrow-order', 'component' => 'business/van-return-borrow-order/index', 'sort' => 7],
            ['name' => 'van.exchange-order', 'title' => '车销换货单', 'path' => '/business/van-exchange-order', 'component' => 'business/van-exchange-order/index', 'sort' => 8],
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

            // 给已拥有 van 顶级权限的角色补子菜单授权
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
        $names = ['van.return-order', 'van.borrow-order', 'van.return-borrow-order', 'van.exchange-order'];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
