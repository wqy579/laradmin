<?php

namespace Modules\Performance\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Permission;

/**
 * 绩效分析菜单权威定义。
 *
 * AuthSeeder 会 Permission::truncate() 整张 auth_permission，任何只在
 * migration 里注册的菜单都会在 db:seed 时被清掉——所以菜单定义必须同时落在
 * 这里（见 InventoryMenuTest 的教训）。本 seeder 幂等：firstOrCreate 按名判重。
 *
 * 权限节点：九个子菜单（menu 类型）+ 按钮权限（button 类型：
 * 导出/审核/查询/新增/编辑/删除）。
 */
class PerformanceMenuSeeder extends Seeder
{
    private const PARENT = [
        'name' => 'perf',
        'title' => '绩效分析',
        'path' => '/perf/commission-rule',
        'icon' => 'ElIconTrendCharts',
        'sort' => 14,
    ];

    /** 子菜单：name / title / path / component / sort */
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

    /** 权限按钮节点：name / title / parent_name */
    private const BUTTONS = [
        ['name' => 'perf.export', 'title' => '导出绩效数据', 'parent' => 'perf'],
        ['name' => 'perf.audit', 'title' => '审核提成单', 'parent' => 'perf'],
        ['name' => 'perf.query', 'title' => '查询', 'parent' => 'perf'],
        ['name' => 'perf.create', 'title' => '新增', 'parent' => 'perf'],
        ['name' => 'perf.edit', 'title' => '编辑', 'parent' => 'perf'],
        ['name' => 'perf.delete', 'title' => '删除', 'parent' => 'perf'],
    ];

    public function run(): void
    {
        // 顶级分组菜单：component 空串 + path 指向第一个子路由（项目硬约定）
        Permission::firstOrCreate(['name' => self::PARENT['name']], [
            'title' => self::PARENT['title'],
            'type' => 'menu',
            'parent_id' => 0,
            'path' => self::PARENT['path'],
            'component' => '',
            'sort' => self::PARENT['sort'],
            'status' => 1,
            'meta' => ['icon' => self::PARENT['icon']],
        ]);

        $parentId = Permission::where('name', self::PARENT['name'])->value('id');

        foreach (self::CHILDREN as $child) {
            Permission::firstOrCreate(['name' => $child['name']], [
                'title' => $child['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $child['path'],
                'component' => $child['component'],
                'sort' => $child['sort'],
                'status' => 1,
            ]);
        }

        foreach (self::BUTTONS as $btn) {
            $pid = Permission::where('name', $btn['parent'])->value('id') ?? 0;
            Permission::firstOrCreate(['name' => $btn['name']], [
                'title' => $btn['title'],
                'type' => 'button',
                'parent_id' => $pid,
                'path' => null,
                'component' => null,
                'sort' => 99,
                'status' => 1,
            ]);
        }

        // 给超级管理员、管理员角色补授权
        $menuNames = array_merge(
            [self::PARENT['name']],
            array_column(self::CHILDREN, 'name'),
            array_column(self::BUTTONS, 'name')
        );
        $menuIds = Permission::whereIn('name', $menuNames)->pluck('id');
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
}
