<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 已部署环境补丁（生产只跑 migrate、不跑 db:seed）：
 *
 * 拜访记录 / 考勤记录列表新增 CSV 导出，BusinessSeeder 的 $visitButtons
 * 已加对应行，但已部署库不会重跑 seeder，需迁移回填：
 *
 *  1) 插入按钮权限 fin:visit:export（parent visit.visit）、
 *     fin:attendance:export（parent visit.attendance）；
 *  2) 权限统一授权给 super_admin。
 *
 * 幂等：存在即跳过，重复跑无副作用。写法照抄 2026_10_31_000001_register_expense_income_export_perms.php。
 */
return new class extends Migration
{
    public function up(): void
    {
        $buttons = [
            ['name' => 'fin:visit:export', 'title' => '导出拜访记录', 'parent' => 'visit.visit', 'sort' => 1],
            ['name' => 'fin:attendance:export', 'title' => '导出考勤记录', 'parent' => 'visit.attendance', 'sort' => 1],
        ];

        $permIds = [];
        foreach ($buttons as $btn) {
            $parentId = DB::table('auth_permission')->where('name', $btn['parent'])->value('id');
            if (! $parentId) {
                // 全新库走 BusinessSeeder 即可，无需回填
                continue;
            }
            $btnId = DB::table('auth_permission')->where('name', $btn['name'])->value('id');
            if (! $btnId) {
                $btnId = DB::table('auth_permission')->insertGetId([
                    'title' => $btn['title'],
                    'name' => $btn['name'],
                    'type' => 'button',
                    'parent_id' => $parentId,
                    'path' => $btn['name'],
                    'component' => null,
                    'meta' => null,
                    'sort' => $btn['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $permIds[] = $btnId;
        }

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        if ($superRoleId) {
            foreach ($permIds as $permId) {
                DB::table('auth_role_permission')->updateOrInsert(
                    ['role_id' => $superRoleId, 'permission_id' => $permId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        // 不回滚：权限补登是幂等的「补数据」，down 保持 no-op
    }
};
