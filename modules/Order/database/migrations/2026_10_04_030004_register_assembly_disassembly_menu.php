<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「商品组装单」「商品拆分单」菜单，挂在 inventory（库存管理）下。
 * 仿 register_stocktaking_menu：按 name 判重幂等，再为已拥有 inventory
 * 权限的角色补授权（超管走 isSuperAdmin 不查此表）。
 */
return new class extends Migration
{
    private function registerMenu(string $name, string $title, string $path, string $component, int $sort): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'inventory')->value('id');
        if (! $parentId) {
            return;
        }

        if (! DB::table('auth_permission')->where('name', $name)->exists()) {
            DB::table('auth_permission')->insert([
                'title' => $title,
                'name' => $name,
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $path,
                'component' => $component,
                'sort' => $sort,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menuId = DB::table('auth_permission')->where('name', $name)->value('id');
        if ($menuId) {
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where(function ($q) {
                    $q->where('p.name', 'inventory')->orWhere('p.name', 'like', 'inventory.%');
                })
                ->pluck('rp.role_id');

            foreach ($roleIds as $roleId) {
                $exists = DB::table('auth_role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $menuId)
                    ->exists();
                if (! $exists) {
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

    private function unregisterMenu(string $name): void
    {
        $menuId = DB::table('auth_permission')->where('name', $name)->value('id');
        if ($menuId) {
            DB::table('auth_role_permission')->where('permission_id', $menuId)->delete();
            DB::table('auth_permission')->where('id', $menuId)->delete();
        }
    }

    public function up(): void
    {
        $this->registerMenu('inventory.assembly', '商品组装单', '/business/assembly', 'business/assembly/index', 10);
        $this->registerMenu('inventory.disassembly', '商品拆分单', '/business/disassembly', 'business/disassembly/index', 11);
    }

    public function down(): void
    {
        $this->unregisterMenu('inventory.assembly');
        $this->unregisterMenu('inventory.disassembly');
    }
};
