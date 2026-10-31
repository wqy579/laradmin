<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 已部署环境补丁（生产只跑 migrate、不跑 db:seed）：
 *
 * 一般费用单 / 其它收入单列表新增 CSV 导出，BusinessSeeder 的 $financeButtons
 * 已加对应行，但已部署库不会重跑 seeder，需迁移回填：
 *
 *  1) 插入按钮权限 fin:general-expense:export、fin:other-income:export；
 *  2) 权限统一授权给 super_admin。
 *
 * 幂等：存在即跳过，重复跑无副作用。写法照抄 2026_10_28_000002_register_general_expense_menus.php。
 */
return new class extends Migration
{
    public function up(): void
    {
        $buttons = [
            ['name' => 'fin:general-expense:export', 'title' => '导出一般费用单', 'parent' => 'finance.general-expense', 'sort' => 4],
            ['name' => 'fin:other-income:export', 'title' => '导出其它收入单', 'parent' => 'finance.other-income', 'sort' => 2],
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
