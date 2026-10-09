<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 重命名旧 order.dispatch 菜单为「调度配送」，补 component，消除与独立顶级
 * 「配送管理」(delivery) 菜单的同名歧义。
 *
 * 背景：order.dispatch（2026_02_26_000001 注册）挂在「订单管理」下，title
 * 历经"配送管理"/"配送单"（2026_09_09_000003_sync_menu_order 同步为"配送单"）。
 * 新的独立顶级 delivery 菜单（2026_10_09_000008）也叫"配送管理"，两者并存
 * 造成侧边栏两个同名入口。dispatch 页面有实际功能（批量打印/配送状态切换），
 * 不删除，仅重命名区分。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('auth_permission')->where('name', 'order.dispatch')->update([
            'title' => '调度配送',
            'component' => 'business/dispatch/index',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // 恢复为 sync_menu_order 迁移同步后的标题"配送单"，清回 component 壳状态
        DB::table('auth_permission')->where('name', 'order.dispatch')->update([
            'title' => '配送单',
            'component' => null,
            'updated_at' => now(),
        ]);
    }
};
