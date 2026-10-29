<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「开账向导」菜单 + 权限节点，并给 super_admin 默认授权（F6 第二批）。
 *
 * 沿用第一批 2026_11_05_000003 的约定：
 *  - 幂等：已存在的 name 只回填 id，不重复插入，可反复执行。
 *  - 权限点同时登记点号名（data.opening.lock）与冒号别名（data:opening:lock），
 *    前端 v-permission 用冒号形式，后端/授权配置里两种写法都能命中。
 *  - 父菜单是既有的 data（资料管理），不新建一级菜单；sort 取同级最大值 +1，
 *    不给任何既有菜单重新编号（第一批同样做法，避免跨机并行互相覆盖）。
 */
return new class extends Migration
{
    /** 菜单：[name, 标题, 路径, 组件] */
    private const MENUS = [
        ['name' => 'data.opening', 'title' => '开账向导', 'path' => '/data/opening', 'component' => 'data/opening/index'],
    ];

    /** 按钮权限：[父菜单, 短名, 标题] */
    private const BUTTONS = [
        ['data.opening', 'view', '查看开账向导'],
        ['data.opening', 'edit', '编辑开账步骤'],
        ['data.opening', 'import', '导入期初数据'],
        ['data.opening', 'lock', '确认开账锁定'],
        ['data.opening', 'unlock', '反开账解锁'],
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

    /** 冒号别名：data.opening.lock -> data:opening:lock（与第一批、Hr 同一约定） */
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
