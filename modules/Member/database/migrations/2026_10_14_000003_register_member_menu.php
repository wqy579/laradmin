<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册「会员管理」顶级菜单及其子菜单（已部署环境增量）。
 *
 * 幂等：按 name 判重再插入；已拥有同类业务权限的角色自动补授权。
 *
 * ⚠️ 菜单最终权威定义在 modules/Business/Seeders/BusinessSeeder.php：
 * AuthSeeder 会 Permission::truncate() 整张 auth_permission，任何只在
 * migration 里注册的菜单都会在 db:seed 时被清掉。本 migration 只负责
 * 已部署环境在 seed 之前刷新菜单（TODO.md 记录过 BusinessSeeder 漏写
 * van 菜单导致新装环境页面不可达的教训）。
 *
 * 硬约定（见 BusinessSeeder 顶部注释）：
 *   - 分组菜单 component 必须留空串 ''，path 指向第一个子路由。
 *     分组一旦自带 component，子页面就要在它内部再渲染一层 <router-view>，
 *     没有的话子页面永远出不来，表现为「菜单在、点进去卡在 loading」。
 *   - meta.icon 必须带 ElIcon 前缀（前端 boot.js 只注册 ElIcon 与 AIcon 前缀的图标），
 *     缺失会静默渲染成空 <el-icon>（见 MenuIconTest）。
 */
return new class extends Migration
{
    /** 子菜单定义：name / title / path / component / sort */
    private const CHILDREN = [
        ['name' => 'member.list', 'title' => '会员档案', 'path' => '/business/member', 'component' => 'business/member/index', 'sort' => 1],
        ['name' => 'member.level', 'title' => '会员等级', 'path' => '/business/member-level', 'component' => 'business/member-level/index', 'sort' => 2],
        ['name' => 'member.point-rule', 'title' => '积分规则', 'path' => '/business/point-rule', 'component' => 'business/point-rule/index', 'sort' => 3],
        ['name' => 'member.recharge', 'title' => '储值充值', 'path' => '/business/member-recharge', 'component' => 'business/member-recharge/index', 'sort' => 4],
        ['name' => 'member.exchange', 'title' => '积分兑换', 'path' => '/business/point-exchange', 'component' => 'business/point-exchange/index', 'sort' => 5],
        ['name' => 'member.consume-log', 'title' => '消费记录', 'path' => '/business/member-consume-log', 'component' => 'business/member-consume-log/index', 'sort' => 6],
    ];

    public function up(): void
    {
        // 顶级分组菜单
        $parentId = 0;
        if (! DB::table('auth_permission')->where('name', 'member')->exists()) {
            $parentId = DB::table('auth_permission')->insertGetId([
                'title' => '会员管理',
                'name' => 'member',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/business/member',
                'component' => '',
                'meta' => json_encode(['icon' => 'ElIconUser'], JSON_UNESCAPED_UNICODE),
                'sort' => 13,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $parentId = DB::table('auth_permission')->where('name', 'member')->value('id');
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

        // 给已有会员相关权限的角色补授权（超管走 isSuperAdmin 不查关联表）
        $menuIds = DB::table('auth_permission')
            ->whereIn('name', array_merge(['member'], array_column(self::CHILDREN, 'name')))
            ->pluck('id');

        if ($menuIds->isEmpty()) {
            return;
        }

        $roleIds = DB::table('auth_role_permission as rp')
            ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('p.name', ['data', 'order', 'finance'])
            ->pluck('rp.role_id');

        foreach ($roleIds as $roleId) {
            foreach ($menuIds as $menuId) {
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
        $names = array_merge(['member'], array_column(self::CHILDREN, 'name'));
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
