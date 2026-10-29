<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「公司档案 / 事业部档案」菜单 + F6 权限节点，并给 super_admin 默认授权。
 *
 * 与 Hr/Cash 菜单迁移同一套约定：
 *  - 幂等：已存在的 name 只回填 id，不重复插入，可反复执行。
 *  - 权限点同时登记点号名与冒号别名，前端 v-permission 与后端授权各命中一种写法。
 *  - 父菜单是既有的 data（资料管理），不新建一级菜单；sort 取同级最大值 +1/+2，
 *    不给任何既有菜单重新编号（与品牌档案当年「插到 sort=1 并整体后移」的做法相反，
 *    后移会动到别人负责的菜单，跨机并行时容易互相覆盖）。
 */
return new class extends Migration
{
    /** 菜单：[name, 标题, 路径, 组件] */
    private const MENUS = [
        ['name' => 'data.company', 'title' => '公司档案', 'path' => '/data/company', 'component' => 'data/company/index'],
        ['name' => 'data.division', 'title' => '事业部档案', 'path' => '/data/division', 'component' => 'data/division/index'],
    ];

    /** 按钮权限：[父菜单, 短名, 标题] */
    private const BUTTONS = [
        ['data.company', 'view', '查看公司档案'],
        ['data.company', 'create', '新增公司档案'],
        ['data.company', 'edit', '编辑公司档案'],
        ['data.company', 'delete', '删除公司档案'],
        ['data.company', 'export', '导出公司档案'],
        ['data.division', 'view', '查看事业部档案'],
        ['data.division', 'create', '新增事业部档案'],
        ['data.division', 'edit', '编辑事业部档案'],
        ['data.division', 'delete', '删除事业部档案'],
        ['data.division', 'export', '导出事业部档案'],
    ];

    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'data')->value('id');
        if (! $parentId) {
            return;
        }

        $idMap = [];
        $sort = (int) DB::table('auth_permission')->where('parent_id', $parentId)->max('sort');

        foreach (self::MENUS as $menu) {
            if ($exists = DB::table('auth_permission')->where('name', $menu['name'])->first()) {
                $idMap[$menu['name']] = $exists->id;

                continue;
            }
            $idMap[$menu['name']] = DB::table('auth_permission')->insertGetId([
                'title' => $menu['title'],
                'name' => $menu['name'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'meta' => json_encode(['hidden' => false, 'hiddenBreadcrumb' => false], JSON_UNESCAPED_UNICODE),
                'sort' => ++$sort,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $buttonSorts = [];
        foreach (self::BUTTONS as [$parentName, $action, $title]) {
            $pid = $idMap[$parentName] ?? 0;
            $dotName = $parentName.'.'.$action;
            $alias = self::alias($parentName, $action);
            $buttonSorts[$parentName] = ($buttonSorts[$parentName] ?? 0) + 1;

            foreach (array_unique([$dotName, $alias]) as $name) {
                if ($exists = DB::table('auth_permission')->where('name', $name)->first()) {
                    $idMap[$name] = $exists->id;

                    continue;
                }
                $idMap[$name] = DB::table('auth_permission')->insertGetId([
                    'title' => $title,
                    'name' => $name,
                    'type' => 'button',
                    'parent_id' => $pid,
                    'path' => $name,
                    'component' => null,
                    'meta' => null,
                    'sort' => $buttonSorts[$parentName],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        if ($superRoleId) {
            foreach ($idMap as $permissionId) {
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

    /** 冒号别名：data.company.view -> data:company:view（与 Hr 的 hr:payroll:view 同一约定） */
    private static function alias(string $parentName, string $action): string
    {
        return str_replace('.', ':', $parentName.'.'.$action);
    }

    public function down(): void
    {
        $names = array_column(self::MENUS, 'name');
        foreach (self::BUTTONS as [$parentName, $action]) {
            $names[] = $parentName.'.'.$action;
            $names[] = self::alias($parentName, $action);
        }

        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
