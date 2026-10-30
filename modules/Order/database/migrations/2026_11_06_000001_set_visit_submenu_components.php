<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA-09：拜访子菜单补 component 绑定。
 *
 * 「访店达成率 / 拜访明细 / 业务员行程」三条菜单注册时 component 为 NULL，
 * 前端动态路由（router/index.js transformMenusToRoutes）对无组件且无子级的菜单
 * 不挂组件也不 redirect —— 点进去是整块空白、不发任何 XHR，
 * 表象即 QA 报的「拜访日程页面暂无数据 / 未接查询」。页面文件本身一直都在：
 *   business/visit/achievement.vue / business/visit/index.vue / business/visit/schedule.vue
 *
 * 只补「component 仍为 NULL」的行，不覆盖手工调整过的绑定。
 */
return new class extends Migration
{
    private const COMPONENTS = [
        'visit.achievement' => 'business/visit/achievement',
        'visit.detail' => 'business/visit/index',
        'visit.schedule' => 'business/visit/schedule',
    ];

    public function up(): void
    {
        foreach (self::COMPONENTS as $name => $component) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->whereNull('component')
                ->update(['component' => $component, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('auth_permission')
            ->whereIn('name', array_keys(self::COMPONENTS))
            ->whereIn('component', array_values(self::COMPONENTS))
            ->update(['component' => null, 'updated_at' => now()]);
    }
};
