<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「帮助中心 / 下载中心」菜单 + F6 权限节点，并给 super_admin 默认授权。
 *
 * 与同模块的 2026_11_05_000003_register_archive_menu.php 完全同一套约定：
 *  - 幂等：已存在的 name 只回填 id，不重复插入，可反复执行；
 *  - 权限点同时登记点号名与冒号别名（前端 v-permission 用冒号形式）；
 *  - 父菜单仍是既有的 data（资料管理），sort 取同级最大值 +1/+2，
 *    不给任何既有菜单重新编号（跨机并行时后移别人的菜单会互相覆盖）；
 *  - down() 真删，并把 auth_role_permission 的关联一起清掉。
 */
return new class extends Migration
{
    /** 菜单：[name, 标题, 路径, 组件] */
    private const MENUS = [
        ['name' => 'data.help', 'title' => '帮助中心', 'path' => '/data/help', 'component' => 'data/help/index'],
        ['name' => 'data.download', 'title' => '下载中心', 'path' => '/data/download', 'component' => 'data/download/index'],
    ];

    /** 按钮权限：[父菜单, 短名, 标题] */
    private const BUTTONS = [
        ['data.help', 'view', '查看帮助文档'],
        ['data.help', 'create', '新增帮助文档'],
        ['data.help', 'edit', '编辑帮助文档'],
        ['data.help', 'delete', '删除帮助文档'],
        ['data.download', 'view', '查看下载中心'],
        ['data.download', 'create', '上传下载文件'],
        ['data.download', 'edit', '编辑下载文件'],
        ['data.download', 'delete', '删除下载文件'],
        ['data.download', 'download', '下载文件'],
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

    /** 冒号别名：data.help.view -> data:help:view */
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
