<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Permission;
use RuntimeException;
use Tests\TestCase;

/**
 * 菜单 name 治理（F8 任务书）
 *
 * 背景：前端 SPA 用 menu.name 注册路由。重复 name 会覆盖前一个路由，
 * 非英文 name 让路由名不合法——两种都表现为"点开某菜单白屏"，且不报错。
 *
 * 契约：
 *  1. 整库种子（DatabaseSeeder）后，所有 type='menu' 的 name 全局唯一且为英文；
 *  2. Permission 模型 saving 钩子：type='menu' 写非英文 name 抛异常；
 *     同一次运行内重复声明 name 抛异常（而非静默覆盖）；
 *  3. 钩子不误伤：更新一行且 name 不变（或改成一个未占用的英文 name）应放行。
 */
class MenuNameGovernanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 每个用例独立建库：模型级用例直接 create，种子用例在用例内 migrate+seed。
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_all_menu_names_are_unique_and_english_after_full_seed(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

        $names = DB::table('auth_permission')
            ->where('type', 'menu')
            ->pluck('name')
            ->map(fn ($n) => (string) $n);

        $this->assertNotEmpty($names, '整库种子后不应没有菜单');

        $duplicates = $names->duplicates()->unique()->values()->all();
        $this->assertSame([], $duplicates, '菜单 name 重复会导致 SPA 路由覆盖白屏，发现：'.implode(', ', $duplicates));

        $nonEnglish = $names->filter(fn ($n) => ! preg_match('/^[a-zA-Z0-9._:-]+$/', $n))->unique()->values()->all();
        $this->assertSame([], $nonEnglish, '非英文菜单 name 会让 SPA 路由名不合法，发现：'.implode(', ', $nonEnglish));
    }

    public function test_model_guard_rejects_non_english_menu_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/菜单 name.*非法/');

        Permission::create([
            'title' => '中文菜单',
            'name' => '中文菜单',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/chinese-menu',
            'sort' => 99,
            'status' => 1,
        ]);
    }

    public function test_model_guard_rejects_duplicate_menu_name_in_same_run(): void
    {
        Permission::create([
            'title' => '已有菜单',
            'name' => 'dup.menu',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/dup-menu',
            'sort' => 98,
            'status' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/菜单 name.*重复/');

        Permission::create([
            'title' => '重复菜单',
            'name' => 'dup.menu',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/dup-menu-2',
            'sort' => 97,
            'status' => 1,
        ]);
    }

    public function test_model_guard_allows_same_name_on_update(): void
    {
        $menu = Permission::create([
            'title' => '原菜单',
            'name' => 'stable.menu',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/stable-menu',
            'sort' => 96,
            'status' => 1,
        ]);

        // 改 title 不改 name：isDirty('name') 为 false，钩子直接放行
        $menu->update(['title' => '改标题']);
        $this->assertSame('改标题', $menu->fresh()->title);
    }

    public function test_model_guard_allows_renaming_to_an_unused_name(): void
    {
        $menu = Permission::create([
            'title' => '旧菜单',
            'name' => 'old.menu',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/old-menu',
            'sort' => 95,
            'status' => 1,
        ]);

        $menu->update(['name' => 'new.menu']);
        $this->assertSame('new.menu', $menu->fresh()->name);
    }

    public function test_model_guard_does_not_touch_non_menu_rows(): void
    {
        // type=button 不受约束：允许任意 name（含中文/重复），不应抛异常
        $perm = Permission::create([
            'title' => '导出按钮',
            'name' => '导出按钮',
            'type' => 'button',
            'parent_id' => 0,
            'path' => null,
            'sort' => 94,
            'status' => 1,
        ]);

        $this->assertSame('导出按钮', $perm->fresh()->name);
    }
}
