<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 已部署环境补丁（生产只跑 migrate、不跑 db:seed）：
 *
 * 为 A8 总账驾驶舱补登「总账驾驶舱」子菜单——000006/LedgerSeeder 的 $menus 数组
 * 已新增 dashboard 行，但对已存在的 auth_permission 行 000006 只读 id 不改（见其
 * 循环里 `if (! $exists) insert` 分支），所以已部署库必须用迁移回填：
 *
 *  1) 在「总账管理」顶级菜单下插入 ledger.dashboard（sort=1 排首）；
 *  2) 现有 8 个子菜单 sort 整体 +1（subject 1→2 … report 8→9）保持顺序；
 *
 * 幂等：dashboard 已存在则跳过，sort 更新为覆盖式幂等（重复跑无副作用）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $top = DB::table('auth_permission')->where('name', 'ledger')->where('type', 'menu')->first();
        if (! $top) {
            // 顶级菜单都还没有，说明全新库走 000006 即可，无需回填
            return;
        }

        // 1) 插入 dashboard 子菜单（不存在才插）
        $exists = DB::table('auth_permission')->where('name', 'ledger.dashboard')->where('type', 'menu')->exists();
        if (! $exists) {
            DB::table('auth_permission')->insert([
                'title' => '总账驾驶舱',
                'name' => 'ledger.dashboard',
                'type' => 'menu',
                'parent_id' => $top->id,
                'path' => '/ledger/dashboard',
                'component' => 'ledger/dashboard/index',
                'meta' => json_encode(['hidden' => false, 'hiddenBreadcrumb' => false], JSON_UNESCAPED_UNICODE),
                'sort' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2) 现有 8 个子菜单 sort 顺延 +1
        $shift = [
            'ledger.subject' => 2,
            'ledger.opening' => 3,
            'ledger.voucher' => 4,
            'ledger.voucher-query' => 5,
            'ledger.voucher-audit' => 6,
            'ledger.period' => 7,
            'ledger.balance' => 8,
            'ledger.report' => 9,
        ];
        foreach ($shift as $name => $sort) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->where('type', 'menu')
                ->update(['sort' => $sort, 'updated_at' => now()]);
        }

        // 3) dashboard 按钮权限节点（parent 为 dashboard 菜单）
        $dashboardMenu = DB::table('auth_permission')->where('name', 'ledger.dashboard')->where('type', 'menu')->first();
        if ($dashboardMenu) {
            $btnExists = DB::table('auth_permission')->where('name', 'ledger.dashboard.view')->where('type', 'button')->exists();
            if (! $btnExists) {
                DB::table('auth_permission')->insert([
                    'title' => '查看驾驶舱',
                    'name' => 'ledger.dashboard.view',
                    'type' => 'button',
                    'parent_id' => $dashboardMenu->id,
                    'path' => 'ledger.dashboard.view',
                    'component' => null,
                    'meta' => null,
                    'sort' => 1,
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // 不回滚：菜单插入与 sort 顺延都是幂等的「补数据」，down 保持 no-op
    }
};
