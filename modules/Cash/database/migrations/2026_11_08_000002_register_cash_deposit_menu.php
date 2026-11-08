<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「存取款转账单」子菜单 + 按钮权限（cash:deposit），并给 super_admin 授权。
 *
 * 幂等：全部按 name 先查后插，重复执行不报错不重复。
 * 前端静态路由 meta.perm 为 cash:deposit（冒号写法），按既有约定同时登记
 * 点号 name（cash.deposit）与冒号 alias（cash:deposit），保证 v-permission
 * 与后端授权都能命中。菜单重建口径见 CashSeeder（本 migration 不改 Seeder）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $cashId = DB::table('auth_permission')->where('name', 'cash')->value('id');
        if (! $cashId) {
            // 一级菜单尚未注册（10-28 migration 未跑过），无法挂载，跳过
            return;
        }

        $menuId = $this->ensureNode([
            'title' => '存取款转账单',
            'name' => 'cash.deposit',
            'type' => 'menu',
            'parent_id' => (int) $cashId,
            'path' => '/cashier/deposit',
            'component' => 'cashier/deposit/index',
            'meta' => json_encode(['hidden' => false, 'hiddenBreadcrumb' => false], JSON_UNESCAPED_UNICODE),
            'sort' => 10,
        ]);

        $buttonId = $this->ensureNode([
            'title' => '存取款单维护',
            'name' => 'cash.deposit.view',
            'type' => 'button',
            'parent_id' => $menuId,
            'path' => 'cash.deposit.view',
            'component' => null,
            'meta' => null,
            'sort' => 1,
        ]);
        $depositAliasId = $this->ensureNode([
            'title' => '存取款单维护',
            'name' => 'cash:deposit',
            'type' => 'button',
            'parent_id' => $menuId,
            'path' => 'cash:deposit',
            'component' => null,
            'meta' => null,
            'sort' => 1,
        ]);

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        if ($superRoleId) {
            foreach (array_filter([$menuId, $buttonId, $depositAliasId]) as $permissionId) {
                $has = DB::table('auth_role_permission')
                    ->where('role_id', $superRoleId)
                    ->where('permission_id', $permissionId)
                    ->exists();
                if (! $has) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $superRoleId,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $names = ['cash.deposit', 'cash.deposit.view', 'cash:deposit'];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }

    /** 按 name 先查后插，返回权限节点 id */
    private function ensureNode(array $fields): int
    {
        $existing = DB::table('auth_permission')->where('name', $fields['name'])->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('auth_permission')->insertGetId(array_merge($fields, [
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
};
