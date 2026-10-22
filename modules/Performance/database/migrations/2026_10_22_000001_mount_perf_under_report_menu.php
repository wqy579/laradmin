<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T4.1：把「绩效分析」从顶级菜单挂到「报表管理」下，并补登缺失的「退货汇总单」。
 *
 * 两件事都只服务已部署环境——权威定义在 modules/Performance/Seeders/PerformanceMenuSeeder.php
 * （AuthSeeder 会 truncate 整张 auth_permission，seed 后以 Seeder 为准）：
 *   1. perf.parent_id: 0 → report 的 id（任务书「报表 → 绩效分析」）。
 *      report（BusinessSeeder 创建）不存在时保持原状，不造父菜单。
 *   2. 2026_10_13_000002 只登了 9 个子菜单，缺 perf.return-summary（退货汇总单），
 *      此处幂等补一行并补 admin 角色授权（与 000002 的授权逻辑同构）。
 *
 * 幂等：parent_id 先查后改；子菜单按 name 判重；授权按 exists 判重。
 */
return new class extends Migration
{
    private const RETURN_SUMMARY = [
        'name' => 'perf.return-summary',
        'title' => '退货汇总单',
        'path' => '/perf/return-summary',
        'component' => 'perf/return-summary/index',
        'sort' => 10,
    ];

    public function up(): void
    {
        $perfId = DB::table('auth_permission')->where('name', 'perf')->value('id');
        if (! $perfId) {
            return; // 全新安装走 seeder，perf 尚未建立时无需处理
        }

        // 1) 挂到报表管理下（已是 report 的子级则跳过
        $reportId = DB::table('auth_permission')->where('name', 'report')->value('id');
        if ($reportId) {
            DB::table('auth_permission')
                ->where('id', $perfId)
                ->where('parent_id', '!=', $reportId)
                ->update(['parent_id' => $reportId, 'sort' => 6, 'updated_at' => now()]);
        }

        // 2) 补登退货汇总单（000002 漏登的第 10 个子菜单）
        if (! DB::table('auth_permission')->where('name', self::RETURN_SUMMARY['name'])->exists()) {
            DB::table('auth_permission')->insert([
                'title' => self::RETURN_SUMMARY['title'],
                'name' => self::RETURN_SUMMARY['name'],
                'type' => 'menu',
                'parent_id' => $perfId,
                'path' => self::RETURN_SUMMARY['path'],
                'component' => self::RETURN_SUMMARY['component'],
                'sort' => self::RETURN_SUMMARY['sort'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3) 给 admin 角色补授权（000002 同构：超管走 isSuperAdmin 不依赖关联表）
        $returnId = DB::table('auth_permission')->where('name', self::RETURN_SUMMARY['name'])->value('id');
        $roleIds = DB::table('auth_role')->whereIn('code', ['super_admin', 'admin'])->pluck('id');
        foreach ($roleIds as $roleId) {
            if ($returnId && ! DB::table('auth_role_permission')
                ->where('role_id', $roleId)
                ->where('permission_id', $returnId)
                ->exists()) {
                DB::table('auth_role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $returnId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // 恢复 perf 为顶级菜单；退货汇总单随 perf 子树一并保留（不删数据）
        $reportId = DB::table('auth_permission')->where('name', 'report')->value('id');
        $perfId = DB::table('auth_permission')->where('name', 'perf')->value('id');
        if ($reportId && $perfId) {
            DB::table('auth_permission')->where('id', $perfId)->update(['parent_id' => 0, 'sort' => 14]);
        }
    }
};
