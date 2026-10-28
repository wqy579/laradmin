<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「绩效分析」顶级菜单、九个子菜单及按钮权限（已部署环境增量）。
 *
 * 幂等：按 name 判重再插入；已拥有同类业务权限的角色自动补授权。
 *
 * ⚠️ 菜单最终权威定义在 modules/Performance/Seeders/PerformanceMenuSeeder.php：
 * AuthSeeder 会 Permission::truncate() 整张 auth_permission，任何只在 migration
 * 里注册的菜单都会在 db:seed 时被清掉。本 migration 只负责已部署环境在 seed
 * 之前刷新菜单。
 *
 * 硬约定（见 BusinessSeeder 顶部注释）：
 *   - 分组菜单 component 必须留空串 ''，path 指向第一个子路由。
 *   - meta.icon 必须带 ElIcon 前缀（前端 boot.js 只注册 ElIcon* / AIcon*）。
 */
return new class extends Migration
{
    private const PARENT = [
        'name' => 'perf',
        'title' => '绩效分析',
        'path' => '/perf/commission-rule',
        'icon' => 'ElIconTrendCharts',
        'sort' => 14,
    ];

    private const CHILDREN = [
        ['name' => 'perf.commission-rule', 'title' => '提成方案', 'path' => '/perf/commission-rule', 'component' => 'perf/commission-rule/index', 'sort' => 1],
        ['name' => 'perf.commission-calc', 'title' => '提成计算', 'path' => '/perf/commission-calc', 'component' => 'perf/commission-calc/index', 'sort' => 2],
        ['name' => 'perf.commission-report', 'title' => '提成报表', 'path' => '/perf/commission-report', 'component' => 'perf/commission-report/index', 'sort' => 3],
        ['name' => 'perf.profit', 'title' => '利润查询', 'path' => '/perf/profit', 'component' => 'perf/profit/index', 'sort' => 4],
        ['name' => 'perf.ar-aging', 'title' => '应收账龄分析', 'path' => '/perf/ar-aging', 'component' => 'perf/ar-aging/index', 'sort' => 5],
        ['name' => 'perf.delivery-uncollected', 'title' => '发货未收款', 'path' => '/perf/delivery-uncollected', 'component' => 'perf/delivery-uncollected/index', 'sort' => 6],
        ['name' => 'perf.stock-warning', 'title' => '库存预警中心', 'path' => '/perf/stock-warning', 'component' => 'perf/stock-warning/index', 'sort' => 7],
        ['name' => 'perf.dashboard', 'title' => '销售分析看板', 'path' => '/perf/dashboard', 'component' => 'perf/dashboard/index', 'sort' => 8],
        ['name' => 'perf.analysis', 'title' => '商品与客户分析', 'path' => '/perf/analysis', 'component' => 'perf/analysis/index', 'sort' => 9],
    ];

    /** 按钮权限节点（与 PerformanceMenuSeeder::BUTTONS 同构）：name / title / parent */
    private const BUTTONS = [
        ['name' => 'perf.export', 'title' => '导出绩效数据', 'parent' => 'perf'],
        ['name' => 'perf.audit', 'title' => '审核提成单', 'parent' => 'perf'],
        ['name' => 'perf.query', 'title' => '查询', 'parent' => 'perf'],
        ['name' => 'perf.create', 'title' => '新增', 'parent' => 'perf'],
        ['name' => 'perf.edit', 'title' => '编辑', 'parent' => 'perf'],
        ['name' => 'perf.delete', 'title' => '删除', 'parent' => 'perf'],
    ];

    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', self::PARENT['name'])->value('id');
        if (! $parentId) {
            $parentId = DB::table('auth_permission')->insertGetId([
                'title' => self::PARENT['title'],
                'name' => self::PARENT['name'],
                'type' => 'menu',
                'parent_id' => 0,
                'path' => self::PARENT['path'],
                'component' => '',
                'meta' => json_encode(['icon' => self::PARENT['icon']], JSON_UNESCAPED_UNICODE),
                'sort' => self::PARENT['sort'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::CHILDREN as $child) {
            if (! DB::table('auth_permission')->where('name', $child['name'])->exists()) {
                DB::table('auth_permission')->insert([
                    'title' => $child['title'],
                    'name' => $child['name'],
                    'type' => 'menu',
                    'parent_id' => $parentId,
                    'path' => $child['path'],
                    'component' => $child['component'],
                    'sort' => $child['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (self::BUTTONS as $btn) {
            if (! DB::table('auth_permission')->where('name', $btn['name'])->exists()) {
                DB::table('auth_permission')->insert([
                    'title' => $btn['title'],
                    'name' => $btn['name'],
                    'type' => 'button',
                    'parent_id' => DB::table('auth_permission')->where('name', $btn['parent'])->value('id') ?? 0,
                    'path' => null,
                    'component' => null,
                    'sort' => 99,
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // BUG-01/C7：业务员订单汇总挂「报表管理」菜单（report 为父）。
        if (! DB::table('auth_permission')->where('name', 'report.salesman-order')->exists()) {
            DB::table('auth_permission')->insert([
                'title' => '业务员订单汇总',
                'name' => 'report.salesman-order',
                'type' => 'menu',
                'parent_id' => DB::table('auth_permission')->where('name', 'report')->value('id')
                    ?? $parentId
                    ?? 0,
                'path' => '/report/salesman-order',
                'component' => 'report/salesman-order/index',
                'sort' => 10,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 给超级管理员角色补授权（超管虽走 isSuperAdmin，但 admin/manager 角色靠关联表）
        $menuNames = array_merge(
            [self::PARENT['name']],
            array_column(self::CHILDREN, 'name'),
            array_column(self::BUTTONS, 'name'),
            ['report.salesman-order']
        );
        $menuIds = DB::table('auth_permission')->whereIn('name', $menuNames)->pluck('id');
        if ($menuIds->isEmpty()) {
            return;
        }

        $roleIds = DB::table('auth_role')->whereIn('code', ['super_admin', 'admin'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($menuIds as $menuId) {
                if (! DB::table('auth_role_permission')->where('role_id', $roleId)->where('permission_id', $menuId)->exists()) {
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
        $names = array_merge(
            [self::PARENT['name']],
            array_column(self::CHILDREN, 'name'),
            array_column(self::BUTTONS, 'name'),
            ['report.salesman-order']
        );
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
