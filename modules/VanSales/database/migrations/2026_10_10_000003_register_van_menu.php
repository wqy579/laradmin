<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「车销业务」菜单：顶级 van + 4 个子菜单。
 *
 * 幂等：按 name 判重再插入；已拥有 van 权限的角色自动补子菜单授权。
 * 注：菜单最终在 BusinessSeeder 里定义（AuthSeeder 会 truncate），本 migration
 * 仅用于已部署环境在 seed 之前刷新菜单。顶层菜单必须带 meta.icon（MenuIconTest）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 顶级菜单「车销业务」
        $vanId = DB::table('auth_permission')->where('name', 'van')->value('id');
        if (! $vanId) {
            $vanId = DB::table('auth_permission')->insertGetId([
                'title' => '车销业务',
                'name' => 'van',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '',
                'component' => '',
                'meta' => json_encode(['icon' => 'Van']),
                'sort' => 11,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $children = [
            ['name' => 'van.requisition', 'title' => '要货申请', 'path' => '/business/van-requisition', 'component' => 'business/van-requisition/index', 'sort' => 1],
            ['name' => 'van.picking', 'title' => '拣货验货', 'path' => '/business/van-picking', 'component' => 'business/van-picking/index', 'sort' => 2],
            ['name' => 'van.sale-order', 'title' => '车销销售单', 'path' => '/business/van-sale-order', 'component' => 'business/van-sale-order/index', 'sort' => 3],
            ['name' => 'van.stock', 'title' => '车上库存', 'path' => '/business/van-stock', 'component' => 'business/van-stock/index', 'sort' => 4],
        ];

        foreach ($children as $child) {
            $exists = DB::table('auth_permission')->where('name', $child['name'])->exists();
            if (! $exists) {
                DB::table('auth_permission')->insert([
                    'title' => $child['title'],
                    'name' => $child['name'],
                    'type' => 'menu',
                    'parent_id' => $vanId,
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
        $names = ['van.requisition', 'van.picking', 'van.sale-order', 'van.stock', 'van'];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
