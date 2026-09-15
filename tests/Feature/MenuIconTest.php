<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 侧边栏图标契约
 *
 * 背景：菜单图标存在 auth_permission.meta.icon 里，运行时才拿到名字，
 * 前端 <component :is="..."> 解析不到就渲染成空 <el-icon>，且不报任何错。
 * 图标缺失因此长期静默存在：home / miniapp 两行建表时根本没写 meta，
 * 而 2026_09_04_000001_update_menu_icons 只补了其余七个分组。
 *
 * 这三条用例钉住"图标必须真的渲染出来"这件事，防止再静默丢一次。
 */
class MenuIconTest extends TestCase
{
    public function test_every_top_level_menu_has_an_icon_after_migrate(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $missing = DB::table('auth_permission')
            ->where('type', 'menu')
            ->where('parent_id', 0)
            ->get()
            ->filter(function ($menu) {
                $meta = json_decode($menu->meta ?? 'null', true) ?? [];

                return empty($meta['icon']);
            })
            ->pluck('name');

        $this->assertCount(
            0,
            $missing,
            '以下顶层菜单没有 meta.icon，侧边栏会显示成空图标位：'.implode(', ', $missing->all())
        );
    }

    public function test_fill_missing_icons_migration_is_idempotent(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $migration = require database_path('migrations/2026_09_14_000002_fill_missing_menu_icons.php');

        $migration->up();
        $afterFirst = $this->homeIcon();

        $migration->up();

        $this->assertSame($afterFirst, $this->homeIcon(), '重复执行不应改变已有图标');
    }

    /**
     * 回归：2026_09_04_000001_update_menu_icons 直接 meta = ['icon' => ...]，
     * 把同列的 hidden / affix 一并抹掉——图标补上了，常驻标签页的行为却丢了。
     * 新迁移必须合并而不是覆盖。
     */
    public function test_fill_missing_icons_preserves_existing_meta_keys(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        // 先让人工在 home 上设置图标 + 一个同列的其它属性
        $this->updateMeta('home', ['icon' => 'ElIconHouse', 'affix' => true, 'hiddenBreadcrumb' => true]);

        $migration = require database_path('migrations/2026_09_14_000002_fill_missing_menu_icons.php');
        $migration->up();

        $meta = json_decode($this->row('home')->meta, true);

        $this->assertSame('ElIconHouse', $meta['icon'], '已有人工图标不得被覆盖');
        $this->assertTrue($meta['affix'] ?? false, '同列的 affix 不应被抹掉');
        $this->assertTrue($meta['hiddenBreadcrumb'] ?? false, '同列的 hiddenBreadcrumb 不应被抹掉');
    }

    /**
     * meta 列为 NULL 的行（线上 home / miniapp 的真实状态）也要能补上
     */
    public function test_fill_missing_icons_handles_null_meta(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->updateMeta('home', null);
        $this->updateMeta('miniapp', null);

        $migration = require database_path('migrations/2026_09_14_000002_fill_missing_menu_icons.php');
        $migration->up();

        $this->assertSame('ElIconHomeFilled', $this->icon('home'));
        $this->assertSame('ElIconPlatform', $this->icon('miniapp'));
    }

    private function row(string $name): object
    {
        return DB::table('auth_permission')
            ->where('type', 'menu')
            ->where('name', $name)
            ->firstOrFail();
    }

    private function icon(string $name): string
    {
        $meta = json_decode($this->row($name)->meta ?? 'null', true) ?? [];

        return $meta['icon'] ?? '';
    }

    private function homeIcon(): string
    {
        return $this->icon('home');
    }

    private function updateMeta(string $name, mixed $meta): void
    {
        $id = $this->row($name)->id;

        DB::table('auth_permission')
            ->where('id', $id)
            ->update(['meta' => $meta === null ? null : json_encode($meta)]);
    }
}
