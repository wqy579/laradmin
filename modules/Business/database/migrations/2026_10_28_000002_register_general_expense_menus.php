<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 已部署环境补丁（生产只跑 migrate、不跑 db:seed）：
 *
 * B9 一般费用单 + 其它收入单接真实数据。BusinessSeeder 的 $financeMenus/$financeButtons
 * 已加对应行，但已部署库不会重跑 seeder，需迁移回填：
 *
 *  1) 其它收入菜单 finance.other-income 的 path 由重复的 /business/other-incomes 收敛
 *     为单数 /business/other-income，并补 component（原先无组件，靠 systemRoutes 兜底）；
 *  2) 「财务管理」下插入 finance.general-expense（/business/general-expense，sort=26）；
 *  3) 插入按钮权限 fin:general-expense:view/edit/approve、fin:other-income:view；
 *  4) 菜单与权限统一授权给 super_admin。
 *
 * 幂等：存在即跳过/按需更新，重复跑无副作用。
 */
return new class extends Migration
{
    public function up(): void
    {
        $top = DB::table('auth_permission')->where('name', 'finance')->where('type', 'menu')->first();
        if (! $top) {
            // 全新库走 BusinessSeeder 即可，无需回填
            return;
        }

        // 1) 其它收入：复数路径 → 单数，并补组件
        $otherIncomeId = DB::table('auth_permission')->where('name', 'finance.other-income')->value('id');
        if ($otherIncomeId) {
            DB::table('auth_permission')->where('id', $otherIncomeId)->update([
                'path' => '/business/other-income',
                'component' => 'business/other-income/index',
                'updated_at' => now(),
            ]);
        }

        // 2) 一般费用单菜单
        $generalExpenseId = DB::table('auth_permission')->where('name', 'finance.general-expense')->value('id');
        if (! $generalExpenseId) {
            $generalExpenseId = DB::table('auth_permission')->insertGetId([
                'title' => '一般费用单',
                'name' => 'finance.general-expense',
                'type' => 'menu',
                'parent_id' => $top->id,
                'path' => '/business/general-expense',
                'component' => 'business/general-expense/index',
                'meta' => null,
                'sort' => 26,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3) 按钮权限点
        $buttons = [
            ['name' => 'fin:general-expense:view', 'title' => '查看一般费用单', 'parent' => $generalExpenseId, 'sort' => 1],
            ['name' => 'fin:general-expense:edit', 'title' => '编辑一般费用单', 'parent' => $generalExpenseId, 'sort' => 2],
            ['name' => 'fin:general-expense:approve', 'title' => '审核一般费用单', 'parent' => $generalExpenseId, 'sort' => 3],
            ['name' => 'fin:other-income:view', 'title' => '查看其它收入单', 'parent' => $otherIncomeId, 'sort' => 1],
        ];

        $permIds = array_filter([$generalExpenseId, $otherIncomeId]);
        foreach ($buttons as $btn) {
            $btnId = DB::table('auth_permission')->where('name', $btn['name'])->value('id');
            if (! $btnId) {
                $btnId = DB::table('auth_permission')->insertGetId([
                    'title' => $btn['title'],
                    'name' => $btn['name'],
                    'type' => 'button',
                    'parent_id' => $btn['parent'],
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
        // 不回滚：菜单补登是幂等的「补数据」，down 保持 no-op
    }
};
