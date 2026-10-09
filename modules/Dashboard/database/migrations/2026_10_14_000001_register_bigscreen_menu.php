<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修正「智慧大屏」顶级菜单：换 name / path，并补齐 ElIcon 前缀的图标。
 *
 * 2026_10_13_000008 这一版有三个问题，合起来就是「菜单看不见 / 点进去是首页」：
 *  1. path 用了 /dashboard —— systemRoutes.js 里 /dashboard 早就是"首页"
 *     （views/home/index.vue）。同一条路径，静态路由先注册先命中，点大屏实际打开首页。
 *  2. name 用了 dashboard，而判重条件是"存在同名就跳过"，一旦别处先落一行
 *     dashboard，这一行就永远插不进去，菜单直接缺席。
 *  3. meta.icon 写成 'DataLine'，缺 ElIcon 前缀（boot.js 只注册 ElIcon* / AIcon*），
 *     侧边栏渲染成空图标位且不报错。
 *
 * 这里把大屏改成独占的 bigscreen / /big-screen，保留全角色授权。
 */
return new class extends Migration
{
    private const TITLE = '智慧大屏';

    private const MENU_NAME = 'bigscreen';

    private const PATH = '/big-screen';

    private const COMPONENT = 'dashboard/index';

    private const META = ['icon' => 'ElIconMonitor'];

    public function up(): void
    {
        $old = DB::table('auth_permission')
            ->where('name', 'dashboard')
            ->where('component', self::COMPONENT)
            ->first();

        $target = DB::table('auth_permission')->where('name', self::MENU_NAME)->first();

        if ($old && ! $target) {
            // 直接把旧行改成正确形态，顺带继承它已有的角色授权
            DB::table('auth_permission')->where('id', $old->id)->update([
                'title' => self::TITLE,
                'name' => self::MENU_NAME,
                'path' => self::PATH,
                'meta' => json_encode(self::META),
                'updated_at' => now(),
            ]);
            $target = (object) ['id' => $old->id];
        } elseif ($old && $target) {
            // 新旧并存时先搬运授权，再删掉指向首页的旧行
            $roleIds = DB::table('auth_role_permission')->where('permission_id', $old->id)->pluck('role_id');
            foreach ($roleIds as $roleId) {
                $has = DB::table('auth_role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $target->id)
                    ->exists();
                if (! $has) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $target->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::table('auth_role_permission')->where('permission_id', $old->id)->delete();
            DB::table('auth_permission')->where('id', $old->id)->delete();
        }

        if (! $target) {
            $id = DB::table('auth_permission')->insertGetId([
                'title' => self::TITLE,
                'name' => self::MENU_NAME,
                'type' => 'menu',
                'parent_id' => 0,
                'path' => self::PATH,
                'component' => self::COMPONENT,
                'meta' => json_encode(self::META),
                'sort' => 90,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $target = (object) ['id' => $id];
        }

        // 大屏对所有角色可见（与 2026_10_13_000008 的口径一致）
        $roleIds = DB::table('auth_role')->pluck('id');
        foreach ($roleIds as $roleId) {
            $has = DB::table('auth_role_permission')
                ->where('role_id', $roleId)
                ->where('permission_id', $target->id)
                ->exists();
            if (! $has) {
                DB::table('auth_role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $target->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('auth_permission')->where('name', self::MENU_NAME)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }
};
