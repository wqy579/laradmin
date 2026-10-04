<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'order')->value('id');
        if (! $parentId) {
            return;
        }
        if (! DB::table('auth_permission')->where('name', 'order.purchase-return')->exists()) {
            $id = DB::table('auth_permission')->insertGetId([
                'title' => '采购退货', 'name' => 'order.purchase-return', 'type' => 'menu',
                'parent_id' => $parentId, 'path' => '/business/purchase-return',
                'component' => 'business/purchase-return/index',
                'sort' => 6, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            // 授予已有 order 菜单权限的角色
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where('p.name', 'order')->pluck('rp.role_id');
            foreach ($roleIds as $rid) {
                if (! DB::table('auth_role_permission')->where('role_id', $rid)->where('permission_id', $id)->exists()) {
                    DB::table('auth_role_permission')->insert(['role_id' => $rid, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('auth_permission')->where('name', 'order.purchase-return')->value('id');
        if ($id) {
            DB::table('auth_role_permission')->where('permission_id', $id)->delete();
            DB::table('auth_permission')->where('id', $id)->delete();
        }
    }
};
