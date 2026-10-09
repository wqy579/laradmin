<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「换货管理」子菜单（库存管理 → 换货管理 → 换货单/换货汇总）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'inventory')->value('id');

        $children = [
            // 交换箭头：菜单图标存在 meta.icon 里，运行时才拿到名字，缺失会静默渲染成空 <el-icon>
            // path 指向第一个真实子路由、component 留空，理由同借还货分组。
            ['name' => 'exchange', 'title' => '换货管理', 'path' => '/business/exchange-order', 'component' => '', 'sort' => 50, 'is_parent' => true, 'icon' => 'ElIconSort'],
            ['name' => 'exchange.order', 'title' => '换货单', 'path' => '/business/exchange-order', 'component' => 'business/exchange/exchange-order/index', 'sort' => 10, 'parent' => 'exchange'],
            ['name' => 'exchange.summary', 'title' => '换货汇总', 'path' => '/business/exchange-summary', 'component' => 'business/exchange/exchange-summary/index', 'sort' => 20, 'parent' => 'exchange'],
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
                    'meta' => json_encode(isset($child['icon']) ? ['icon' => $child['icon']] : []),
                    'sort' => $child['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $idMap[$child['name']] = $id;
            } else {
                $idMap[$child['name']] = DB::table('auth_permission')->where('name', $child['name'])->value('id');
            }

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
        $names = ['exchange.order', 'exchange.summary', 'exchange'];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
