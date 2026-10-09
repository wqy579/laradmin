<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修复「车销业务」菜单授权死锁：历史 van 菜单迁移只给"已拥有 van 权限的角色"
 * 补授权，而 van 权限是随本组迁移首次创建的——没有角色预先拥有它，授权循环
 * 空转，导致菜单记录存在但任何角色都看不到（2026-10-09 生产实测：van 菜单
 * 11 条全部插入成功、auth_role_permission 授权记录 0 条）。
 *
 * 对照 Delivery 的 grantToOrderRoles 成熟模式：业务菜单授权给"拥有同级业务
 * 菜单权限的角色"。车销与配送同属车辆/物流业务，这里把全部 11 条 van 菜单
 * （顶级 + 10 个子菜单）授权给已拥有 order / delivery / visit 任一权限的角色。
 *
 * 幂等：已存在的授权跳过；菜单记录缺失时静默跳过（不负责创建，创建归
 * register_van_* 迁移与 BusinessSeeder 管）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $vanNames = [
            'van', 'van.requisition', 'van.picking', 'van.sale-order', 'van.stock',
            'van.return-order', 'van.borrow-order', 'van.return-borrow-order',
            'van.exchange-order', 'van.return-to-warehouse', 'van.remit',
        ];

        // 参照角色：已拥有 订单/配送/拜访 任一业务菜单权限的角色。
        // super_admin 走 getUserMenu 的全量分支，无需显式授权也能看到菜单，
        // 但显式授权可保证其"权限管理"界面里的勾选状态与实际一致。
        $roleIds = DB::table('auth_role_permission as rp')
            ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
            ->where(function ($q) {
                $q->where('p.name', 'order')
                    ->orWhere('p.name', 'like', 'order.%')
                    ->orWhere('p.name', 'delivery')
                    ->orWhere('p.name', 'like', 'delivery.%')
                    ->orWhere('p.name', 'visit')
                    ->orWhere('p.name', 'like', 'visit.%');
            })
            ->distinct()
            ->pluck('rp.role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $menuIds = DB::table('auth_permission')->whereIn('name', $vanNames)->pluck('id');
        if ($menuIds->isEmpty()) {
            return;
        }

        foreach ($roleIds as $roleId) {
            $existing = DB::table('auth_role_permission')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $menuIds)
                ->pluck('permission_id')
                ->all();

            $missing = array_diff($menuIds->all(), $existing);
            foreach ($missing as $permissionId) {
                DB::table('auth_role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // 数据修复型迁移不回滚授权（回滚会把线上正在使用的菜单一并收回）。
    }
};
