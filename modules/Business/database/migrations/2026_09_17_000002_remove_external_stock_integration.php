<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 移除外部库存同步集成（连凯）
 *
 * 连凯是外部第三方仓储系统。本系统曾把它的仓库名映射成 vehicles.id、把它的
 * 导出 JSON 写入 liankai_stock_checks / liankai_stock_change_logs，页面也按
 * liankai-stock-check / liankai-stock-monitor 命名。三张相关建表迁移已删除；
 * 本迁移负责已在生产库落地的数据：
 *
 *   ① 删表：两张表只承载连凯同步数据，没有本系统的查询依赖。
 *   ② 改菜单：auth_permission 里残留的 liankai 路径与组件指向会被前端静默丢弃
 *      （loadComponent 解析失败不报错），必须显式改写到 stock-check / stock-monitor。
 *
 * 幂等：dropIfExists / update 均可重复执行；菜单按 path LIKE 兜底，
 * 不依赖迁移是否被重跑（线上库手工补过数据、迁移未必登记进 migrations 表）。
 *
 * 功能不变：库存核对与库存监控页面改走 business/stock-check 与
 * business/stock-monitor，数据源始终是 stocks + stock_snapshots，
 * 从不用过连凯的两张表（旧 checkList 是无路由的死代码，sync 接口的 JSON 文件
 * 在本仓库不存在、也没有生成命令）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('liankai_stock_change_logs');
        Schema::dropIfExists('liankai_stock_checks');

        // 库存核对
        $inventoryId = DB::table('auth_permission')->where('name', 'inventory')->value('id');
        if ($inventoryId) {
            DB::table('auth_permission')
                ->where('name', 'inventory.stock-check')
                ->update([
                    'path' => '/business/stock-check',
                    'component' => 'business/stock-check/index',
                    'updated_at' => now(),
                ]);

            // 旧命名的菜单行（inventory.liankai-stock-check）并入 inventory.stock-check
            $legacy = DB::table('auth_permission')->where('name', 'inventory.liankai-stock-check')->first();
            if ($legacy) {
                DB::table('auth_permission')->where('id', $legacy->id)->update([
                    'name' => 'inventory.stock-check',
                    'path' => '/business/stock-check',
                    'component' => 'business/stock-check/index',
                    'parent_id' => $inventoryId,
                    'updated_at' => now(),
                ]);
                DB::table('auth_role_permission')
                    ->whereIn('permission_id', [
                        $legacy->id,
                        DB::table('auth_permission')->where('name', 'inventory.stock-check')->value('id'),
                    ])
                    ->groupBy('role_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->pluck('role_id')
                    ->each(function ($roleId) {
                        DB::table('auth_role_permission')
                            ->where('role_id', $roleId)
                            ->where('permission_id', '!=', DB::table('auth_permission')->where('name', 'inventory.stock-check')->value('id'))
                            ->delete();
                    });
            }
        }

        // 库存监控
        DB::table('auth_permission')
            ->where('name', 'inventory.query')
            ->update([
                'title' => '库存监控',
                'path' => '/business/stock-monitor',
                'component' => 'business/stock-monitor/index',
                'updated_at' => now(),
            ]);

        // 兜底：任何还指向旧 URL 的菜单行
        DB::table('auth_permission')
            ->whereRaw("path LIKE '%liankai%' OR component LIKE '%liankai%'")
            ->update(['status' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // 不可逆：连凯表结构随其建表迁移一并删除，无法回滚重建。
        // 生产库如需回退，从备份恢复。
    }
};
