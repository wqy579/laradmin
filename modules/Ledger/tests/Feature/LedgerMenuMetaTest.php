<?php

namespace Tests\Ledger\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 「总账管理」菜单 meta 契约
 *
 * 2026-10 验收缺陷②：LedgerSeeder / 000006 的 meta 三元在「有 icon」分支
 * 只写 {icon}，顶级「总账管理」丢了 hidden:false / hiddenBreadcrumb:false，
 * 登录后侧边栏看不到入口。
 *
 * 钉住两件事：
 *  1) migrate 之后（生产 migrate-only 路径，不跑 db:seed）meta 必须带 hidden:false；
 *  2) migrate + seed 之后同样如此（000008 回填 + LedgerSeeder 合并写入都幂等）。
 */
class LedgerMenuMetaTest extends TestCase
{
    public function test_ledger_menu_has_hidden_false_after_migrate(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertLedgerTopMeta();
    }

    public function test_ledger_menu_has_hidden_false_after_migrate_and_seed(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

        $this->assertLedgerTopMeta();
    }

    public function test_ledger_children_have_hidden_false_after_migrate(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $children = DB::table('auth_permission')
            ->where('type', 'menu')
            ->where('name', 'like', 'ledger.%')
            ->get();

        $this->assertCount(8, $children, '总账应有 8 个子菜单');
        foreach ($children as $child) {
            $meta = json_decode($child->meta ?? 'null', true) ?? [];
            $this->assertSame(false, $meta['hidden'] ?? null, "{$child->name} 缺 meta.hidden=false");
        }
    }

    public function test_ledger_top_menu_path_points_to_first_child(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $top = DB::table('auth_permission')->where('name', 'ledger')->where('type', 'menu')->first();
        $this->assertNotNull($top, '顶级菜单 ledger 不存在');
        // 车销坑：path 留空整棵子树会被 transformMenusToRoutes 丢弃
        $this->assertSame('/ledger/subject', $top->path);
        $this->assertNotEmpty($top->path, '顶级 path 绝不能为空字符串');
    }

    public function test_preset_subjects_seeded_by_migration_without_db_seed(): void
    {
        // 生产部署只跑 migrate（见 deploy.yml），不跑 db:seed；
        // 预置科目必须由迁移 000008 播种，否则会计科目/凭证页全空
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertGreaterThan(
            0,
            (int) DB::table('ledger_subjects')->where('code', '1001')->count(),
            'migrate 后应有预置科目 1001 库存现金（000008 播种）'
        );
        $this->assertGreaterThan(
            0,
            (int) DB::table('ledger_subjects')->count(),
            'migrate 后 ledger_subjects 不应为空'
        );
    }

    private function assertLedgerTopMeta(): void
    {
        $row = DB::table('auth_permission')->where('name', 'ledger')->where('type', 'menu')->first();
        $this->assertNotNull($row, '顶级菜单 ledger 不存在');

        $meta = json_decode($row->meta ?? 'null', true) ?? [];
        $this->assertSame('ElIconNotebook', $meta['icon'] ?? null, '缺 meta.icon');
        $this->assertSame(false, $meta['hidden'] ?? null, '缺 meta.hidden=false（菜单会被过滤）');
        $this->assertSame(false, $meta['hiddenBreadcrumb'] ?? null, '缺 meta.hiddenBreadcrumb=false');
    }
}
