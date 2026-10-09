<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「借还货管理」子菜单（库存管理 → 借还货管理 → 借货单/还货单/借货汇总）。
 *
 * 幂等：按 name 判重再插入；已拥有 inventory 权限的角色自动补子菜单授权。
 * 注：菜单最终在 AuthSeeder 里定义（AuthSeeder 会 truncate），本 migration
 * 仅用于已部署环境在 seed 之前刷新菜单。
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'inventory')->value('id');

        $children = [
            ['name' => 'borrow-return', 'title' => '借还货管理', 'path' => '/business/borrow-return', 'component' => 'business/borrow-return/index', 'sort' => 40, 'is_parent' => true],
            ['name' => 'borrow.order', 'title' => '借货单', 'path' => '/business/borrow-order', 'component' => 'business/borrow-return/borrow-order/index', 'sort' => 10, 'parent' => 'borrow-return'],
            ['name' => 'borrow.return', 'title' => '还货单', 'path' => '/business/return-order', 'component' => 'business/borrow-return/return-order/index', 'sort' => 20, 'parent' => 'borrow-return'],
            ['name' => 'borrow.summary', 'title' => '借货汇总', 'path' => '/business/borrow-summary', 'component' => 'business/borrow-return/borrow-summary/index', 'sort' => 30, 'parent' => 'borrow-return'],
        ];

        $idMap = [];
        foreach ($children as $child) {
            $exists = DB::table('auth_permission')->where('name', $child['name'])->exists();
            $pid = ($child['is_parent'] ?? false) ? ($parentId ?? 0) : ($idMap[$child['parent']] ?? $parentId ?? 0);
            if (! $exists) {
                $id = DB::table('auth_permission')->insertGetId([
                    'title' => $child['title'],
                    'name' => $child['name'],
                    'type' => 'menu',
                    'parent_id' => $pid,
                    'path' => $child['path'],
                    'component' => $child['component'],
                    'meta' => json_encode([]),
                    'sort' => $child['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $idMap[$child['name']] = $id;
            } else {
                $idMap[$child['name']] = DB::table('auth_permission')->where('name', $child['name'])->value('id');
            }

            // 已拥有父菜单权限的角色，自动补子菜单授权
            if (isset($idMap[$child['name']])) {
                $roleIds = DB::table('auth_role_permission as rp')
                    ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                    ->where(function ($q) use ($parentId) {
                        $q->where('p.id', $parentId ?? 0)->orWhere('p.name', 'like', 'inventory.%');
                    })
                    ->pluck('rp.role_id');

                foreach ($roleIds as $roleId) {
                    $has = DB::table('auth_role_permission')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $idMap[$child['name']])
                        ->exists();
                    if (! $has) {
                        DB::table('auth_role_permission')->insert([
                            'role_id' => $roleId,
                            'permission_id' => $idMap[$child['name']],
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
        $names = ['borrow.order', 'borrow.return', 'borrow.summary', 'borrow-return'];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
