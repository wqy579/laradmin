<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修复「车销业务」顶级菜单 path 为空导致前端整棵子树被丢弃的问题。
 *
 * 根因：BusinessSeeder 与 register_van_menu 迁移早期版本把 van 顶级菜单的
 * path 设为空字符串 ''。前端 router/index.js 的 transformMenusToRoutes 用
 * filter(menu => menu && menu.path) 过滤菜单，path 为空的顶级菜单连同其所有
 * children 一并被丢弃，左侧菜单永远不出现"车销业务"入口——即便授权已补全、
 * 即便 super_admin 走全量分支拿到记录，空 path 仍让前端把它过滤掉。
 *
 * 配送管理(delivery)一直正常显示，正是因为其顶级 path 指向第一个子菜单
 * /business/delivery-picking。这里对齐该写法，把 van 顶级 path 指向第一个
 * 子菜单 /business/van-requisition。
 *
 * 幂等：只更新 path 为空的 van 顶级菜单；已正确的不动。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('auth_permission')
            ->where('name', 'van')
            ->where('parent_id', 0)
            ->where(function ($q) {
                $q->where('path', '')->orWhereNull('path');
            })
            ->update(['path' => '/business/van-requisition', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // 数据修复型迁移不回滚（回滚会把线上正在使用的菜单 path 又清空）。
    }
};
