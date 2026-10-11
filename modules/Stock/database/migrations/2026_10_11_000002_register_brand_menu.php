<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「品牌档案」菜单。
 *
 * 父菜单 data（资料管理）已存在。菜单排在商品档案（data.product, sort=1）之前，
 * 与连凯「品牌作为分类一级维度」的定位一致——品牌是商品的主维度，应当最靠前。
 *
 * 幂等：按 name 判重，再为已拥有 data 权限的角色补授权（超管走 isSuperAdmin 不查此表）。
 * 同步把 data.product 之后的所有同级菜单 sort+1，给品牌腾出 sort=1 的位置。
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'data')->value('id');
        if (! $parentId) {
            return;
        }

        if (! DB::table('auth_permission')->where('name', 'data.brand')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '品牌档案',
                'name' => 'data.brand',
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => '/business/brand',
                'component' => 'business/brand/index',
                'sort' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 后移现有同级菜单，给品牌让出 sort=1
            DB::table('auth_permission')
                ->where('parent_id', $parentId)
                ->where('name', '!=', 'data.brand')
                ->where('sort', '>=', 1)
                ->increment('sort');
        }

        // 为已拥有 data 权限的角色补授权品牌菜单
        $menuId = DB::table('auth_permission')->where('name', 'data.brand')->value('id');
        if ($menuId) {
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where(function ($q) {
                    $q->where('p.name', 'data')->orWhere('p.name', 'like', 'data.%');
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
        $menuId = DB::table('auth_permission')->where('name', 'data.brand')->value('id');
        if ($menuId) {
            DB::table('auth_role_permission')->where('permission_id', $menuId)->delete();
            DB::table('auth_permission')->where('id', $menuId)->delete();
        }
        // 回退 sort 后移
        DB::table('auth_permission')
            ->where('name', 'like', 'data.%')
            ->where('sort', '>', 1)
            ->decrement('sort');
    }
};
