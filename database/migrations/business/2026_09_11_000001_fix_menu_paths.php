<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 修复菜单路径与后端实际路由不匹配的问题。
     * 修复原因：菜单注册时使用了单数形式或旧名称，后端路由已统一为复数或新命名。
     */
    public function up(): void
    {
        $fixes = [
            // 权限管理：单数→复数
            ['name' => 'auth.department',   'path' => '/auth/department',    'new_path' => '/auth/departments'],
            ['name' => 'auth.permission',   'path' => '/auth/permission',    'new_path' => '/auth/permissions'],
            ['name' => 'auth.role',         'path' => '/auth/role',          'new_path' => '/auth/roles'],
            ['name' => 'auth.user',         'path' => '/auth/user',          'new_path' => '/auth/users'],
            // 业务模块：单数→复数 / 命名调整
            ['name' => 'data.customer',     'path' => '/business/customer',  'new_path' => '/business/customers'],
            ['name' => 'price.cost',        'path' => '/business/cost-prices','new_path' => '/business/cost-price'],
            ['name' => 'finance.expense',   'path' => '/business/expenses',  'new_path' => '/business/expense'],
            ['name' => 'inventory.liankai-stock-check', 'path' => '/business/liankai-stock-check', 'new_path' => '/business/liankai-stock-monitor'],
            // 拜访管理：根路径指向logs
            ['name' => 'visit.visit',       'path' => '/business/visit',     'new_path' => '/business/visit/logs'],
        ];

        foreach ($fixes as $fix) {
            DB::table('auth_permission')
                ->where('name', $fix['name'])
                ->where('path', $fix['path'])
                ->update(['path' => $fix['new_path']]);
        }

        // 修复库存监控组件路径（cleanup迁移只更新了path，遗漏了component）
        DB::table('auth_permission')
            ->where('name', 'inventory.query')
            ->where('path', '/business/liankai-stock-monitor')
            ->update(['component' => 'business/liankai-stock-monitor/index']);

        // 删除无对应路由的菜单项（拜访明细 无独立视图，复用 logs 即可）
        DB::table('auth_permission')
            ->where('name', 'visit.detail')
            ->delete();
    }

    public function down(): void
    {
        $reverts = [
            ['name' => 'auth.department',   'path' => '/auth/department'],
            ['name' => 'auth.permission',   'path' => '/auth/permission'],
            ['name' => 'auth.role',         'path' => '/auth/role'],
            ['name' => 'auth.user',         'path' => '/auth/user'],
            ['name' => 'data.customer',     'path' => '/business/customer'],
            ['name' => 'price.cost',        'path' => '/business/cost-prices'],
            ['name' => 'finance.expense',   'path' => '/business/expenses'],
            ['name' => 'inventory.liankai-stock-check', 'path' => '/business/liankai-stock-check'],
            ['name' => 'visit.visit',       'path' => '/business/visit'],
        ];

        foreach ($reverts as $rev) {
            DB::table('auth_permission')
                ->where('name', $rev['name'])
                ->where('path', $rev['path'])
                ->update(['path' => $rev['path']]);
        }

        // 恢复 visit.detail
        DB::table('auth_permission')->insert([
            'title' => '拜访明细',
            'name' => 'visit.detail',
            'type' => 'menu',
            'parent_id' => DB::table('auth_permission')->where('name', 'visit')->value('id'),
            'path' => '/business/visit/detail',
            'component' => null,
            'sort' => 5,
            'status' => 1,
        ]);
    }
};
