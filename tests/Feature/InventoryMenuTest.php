<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 「库存核对」菜单回归守卫
 *
 * 背景：侧边栏完全由 auth_permission 驱动（frontend/src/config/routes.js 的
 * userRoutes 是空数组，无任何静态菜单兜底），所以菜单行一旦从数据库消失，
 * 页面在后端路由和前端 view 都就绪的情况下也不可达。
 *
 * 2026-09-16 线上丢过一次「库存核对」：它只被 2026_09_11_000003 一次性迁移
 * 插入过，BusinessSeeder 的 $inventoryMenus 里从来没有这一项；而 AuthSeeder
 * 会在 BusinessSeeder 之前 Permission::truncate() 整张 auth_permission，
 * 任何一次 db:seed / 换库 / 从备份恢复都会把它永久清掉。同一族菜单在 5 天内
 * 被 9 条迁移反复增删改名（stock-check → monitor → query →
 * stock-check），每次都只修当前库、不改 seeder，所以问题必然复发。
 *
 * 这三条用例钉住：菜单定义必须落在 seeder 里（不只靠迁移）、恢复迁移必须
 * 幂等（部署时会重复执行）、菜单声明的 component 必须指向真实的前端文件。
 */
class InventoryMenuTest extends TestCase
{
    public function test_stock_check_menu_is_seeded_under_inventory(): void
    {
        // 必须连 seeder 一起跑：单跑迁移测不出"seed 把菜单清掉"这条路径
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

        $menu = $this->menu('inventory.stock-check');
        $this->assertNotNull($menu, 'auth_permission 缺少 inventory.stock-check（库存核对）');

        $inventoryId = DB::table('auth_permission')->where('name', 'inventory')->value('id');
        $this->assertNotNull($inventoryId, '缺少 inventory 父菜单');

        $this->assertSame('库存核对', $menu->title);
        $this->assertSame('menu', $menu->type);
        $this->assertSame($inventoryId, $menu->parent_id, '库存核对必须挂在库存管理下');
        $this->assertSame('/business/stock-check', $menu->path);
        $this->assertSame('business/stock-check/index', $menu->component);
        $this->assertSame(1, (int) $menu->status, '库存核对必须启用，否则侧边栏不显示');

        // 侧边栏可见性走 getUserMenu()：type=menu 且 status=1
        $visible = DB::table('auth_permission')
            ->where('name', 'inventory.stock-check')
            ->where('type', 'menu')
            ->where('status', 1)
            ->exists();
        $this->assertTrue($visible, '库存核对必须能通过 getUserMenu() 的 type/status 过滤');
    }

    public function test_restore_inventory_menu_migration_is_idempotent(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

        // 模拟真实授权形态：角色只被单独授了子菜单，没有拿父菜单。
        // seeder 自己完全不写 auth_role_permission，所以这里手工造一条。
        $roleId = DB::table('auth_role')->where('name', '管理员')->value('id');
        $childId = DB::table('auth_permission')->where('name', 'inventory.stock-in')->value('id');
        DB::table('auth_role_permission')->insert([
            'role_id' => $roleId, 'permission_id' => $childId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 直接调用 up() 而不是 artisan migrate：migrate 只跑未记录的迁移，
        // 而线上库手工补过数据、这条迁移并未登记进 migrations 表，
        // deploy.yml 的 `php artisan migrate --force` 会真正执行它——
        // 这个场景必须真的被跑到。
        $migration = require $this->migrationFile();
        $migration->up();
        $afterFirst = DB::table('auth_role_permission')->where('permission_id', $this->stockCheckId())->pluck('role_id');
        $migration->up();

        $this->assertSame(1, DB::table('auth_permission')->where('name', 'inventory.stock-check')->count(),
            '重复执行迁移不应产生重复菜单行');

        $this->assertCount(1, $afterFirst, '持有 inventory.* 任一菜单的角色应当被授权库存核对');
        $this->assertSame($roleId, $afterFirst[0], '授权的应当是持有 inventory.* 的那个角色');

        $afterSecond = DB::table('auth_role_permission')->where('permission_id', $this->stockCheckId())->pluck('role_id');
        $this->assertEqualsCanonicalizing($afterFirst->all(), $afterSecond->all(), '重复执行迁移不应产生重复角色授权');
        $this->assertSame($afterSecond->count(), $afterSecond->unique()->count(), '同一角色不应被授权两次');

        // 隐藏的死菜单不能被幂等执行意外恢复
        foreach (['inventory.scrap', 'inventory.check'] as $dead) {
            $row = DB::table('auth_permission')->where('name', $dead)->first();
            if ($row) {
                $this->assertSame(0, (int) $row->status, "$dead 没有落地页，应保持隐藏");
            }
        }
    }

    private function stockCheckId(): int
    {
        return DB::table('auth_permission')->where('name', 'inventory.stock-check')->value('id');
    }

    private function migrationFile(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/Business/database/migrations/2026_09_16_000001_restore_inventory_stock_check_menu.php';
    }

    /**
     * 菜单行声明的 component 必须指向真实存在的前端文件。
     *
     * loadComponent() 在 frontend/src/router/index.js 里的解析规则是
     * ../views/{component}.vue 或 ../views/{component}/index.vue，
     * 解析失败时整个菜单节点被静默丢弃（if (!comp) return），不报任何错。
     */
    public function test_every_declared_menu_component_resolves_to_a_real_view(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

        $missing = [];
        foreach (DB::table('auth_permission')->whereNotNull('component')->get() as $menu) {
            if (! $this->viewExists($menu->component)) {
                $missing[] = sprintf('%s (%s) → %s', $menu->name, $menu->title, $menu->component);
            }
        }

        $this->assertSame([], $missing, '这些菜单的 component 指向前端不存在的文件：'.implode(', ', $missing));
    }

    private function viewExists(string $component): bool
    {
        $root = dirname(__DIR__, 2).'/frontend/src/views/';

        return is_file($root.$component.'.vue') || is_file($root.$component.'/index.vue');
    }

    private function menu(string $name): ?object
    {
        return DB::table('auth_permission')->where('name', $name)->first();
    }
}
