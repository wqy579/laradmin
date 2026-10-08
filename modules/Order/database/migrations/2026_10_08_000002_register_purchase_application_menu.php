<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「采购申请」菜单（库存管理 → 采购申请）。
 * 幂等：按 name 判重再插入；已拥有 inventory 权限的角色自动补授权。
 *
 * 父菜单 = inventory（库存管理），sort=32（在 stock-adjust sort=31 之后）。
 * 用户决策：采购申请挂在库存管理下（采购流程起点，与入库/出库/调拨并列）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'inventory')->value('id');
        if (! $parentId) {
            return;
        }

        if (! DB::table('auth_permission')->where('name', 'inventory.purchase-application')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '采购申请',
                'name' => 'inventory.purchase-application',
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => '/business/purchase-application',
                'component' => 'business/purchase-application/index',
                'sort' => 32,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 给已有 inventory 菜单权限的角色补授权（照抄 register_stock_adjust_menu 模式）
        $menuId = DB::table('auth_permission')->where('name', 'inventory.purchase-application')->value('id');
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

    public function down(): void
    {
        $id = DB::table('auth_permission')->where('name', 'inventory.purchase-application')->value('id');
        if ($id) {
            DB::table('auth_role_permission')->where('permission_id', $id)->delete();
            DB::table('auth_permission')->where('id', $id)->delete();
        }
    }
};
