<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修正「借还货管理」分组菜单的 path / component。
 *
 * 上一版把分组做成了自带落地页的容器（path=/business/borrow-return、
 * component=business/borrow-return/index），结果点进去永远停在 loading 文案上：
 * 子菜单是以绝对路径挂在分组下的嵌套路由，子页面要渲染在分组组件内部的
 * <router-view> 里，而这个落地页没有写 <router-view>——子页面永远出不来。
 *
 * 本仓库的约定是：分组菜单 component 留空、path 直接指向第一个子路由
 * （见 BusinessSeeder 顶级菜单、van / delivery 分组）。这里把已部署库的那一行
 * 改回约定形态。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('auth_permission')
            ->where('name', 'borrow-return')
            ->update([
                'path' => '/business/borrow-order',
                'component' => '',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // 不还原：还原只会把那个卡在 loading 的写法带回来
    }
};
