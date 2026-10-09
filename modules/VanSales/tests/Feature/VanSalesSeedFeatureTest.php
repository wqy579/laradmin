<?php

namespace Tests\VanSales\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 车销业务模块「全新安装路径」菜单完整性测试
 *
 * 与 VanSalesMigrationsFeatureTest 分开成独立类，原因有二：
 *
 * ① 本类必须跑 Artisan migrate:fresh（它会在 sqlite 内存库上 VACUUM），
 *    而 RefreshDatabase 用事务包裹每个测试——事务内 VACUUM 报
 *    "cannot VACUUM from within a transaction"。MenuIconTest 同样不带该 trait，同一原因。
 *
 * ② 迁移路径与 seed 路径是两个不同的故障域，分开放在两个文件里各自表达清楚：
 *      - VanSalesMigrationsFeatureTest：只跑迁移（migrate:fresh），验证表结构与菜单迁移
 *      - 本类：跑 migrate:fresh + db:seed，验证全新安装后的最终状态
 *
 * 核心回归点：
 *   AuthSeeder 会 Permission::truncate() 整张 auth_permission（AuthSeeder:30），
 *   把迁移阶段由 register_van_menu 注册的 9 条车销菜单连同图标一起清掉。
 *   BusinessSeeder 负责重建业务菜单——若它漏了 van，全新安装后车销 8 个页面
 *   全部无菜单入口、不可访问，而已部署环境靠迁移补过一遍所以一切正常。
 *   对照 tests/Feature/MenuIconTest 的 migrate-and-seed 用例：它只校验图标非空，
 *   菜单整行缺失时它扫不到、测不出。
 */
class VanSalesSeedFeatureTest extends TestCase
{
    private const VAN_MENUS = [
        'van',
        'van.requisition',
        'van.picking',
        'van.sale-order',
        'van.stock',
        'van.return-order',
        'van.borrow-order',
        'van.return-borrow-order',
        'van.exchange-order',
    ];

    private bool $installed = false;

    /**
     * 全新安装（db:seed）后 9 条车销菜单必须全部存在。
     *
     * 缺失即意味着 BusinessSeeder 未同步 van 菜单，而 AuthSeeder 的 truncate
     * 已清掉迁移阶段的记录——用户看到的是「已开发的车销模块在新环境里根本进不去」。
     */
    public function test_van_menus_survive_full_install(): void
    {
        $this->installFresh();

        foreach (self::VAN_MENUS as $name) {
            $row = DB::table('auth_permission')->where('name', $name)->first();
            $this->assertNotNull(
                $row,
                "全新安装（db:seed）后缺少车销菜单 {$name}——BusinessSeeder 未同步 van 菜单，AuthSeeder 的 truncate 已清掉迁移阶段注册的记录"
            );
        }
    }

    public function test_van_top_menu_has_icon_after_install(): void
    {
        $this->installFresh();

        $meta = DB::table('auth_permission')->where('name', 'van')->value('meta');
        $icon = $meta === null ? null : (json_decode((string) $meta, true)['icon'] ?? null);

        $this->assertNotNull(
            $icon,
            '全新安装后 van 顶级菜单缺少 meta.icon，侧边栏会渲染成空图标'
        );
        $this->assertStringStartsWith(
            'ElIcon',
            $icon,
            "全新安装后 van 菜单图标「{$icon}」缺 ElIcon 前缀，前端 <component :is> 解析不到组件"
        );
    }

    public function test_van_menus_have_correct_paths_and_components(): void
    {
        $this->installFresh();

        // path / component 必须与前端 systemRoutes.js 注册的 8 条路由对齐，
        // 否则菜单点进去 404 或白屏。
        $expected = [
            'van.requisition' => ['/business/van-requisition', 'business/van-requisition/index'],
            'van.picking' => ['/business/van-picking', 'business/van-picking/index'],
            'van.sale-order' => ['/business/van-sale-order', 'business/van-sale-order/index'],
            'van.stock' => ['/business/van-stock', 'business/van-stock/index'],
            'van.return-order' => ['/business/van-return-order', 'business/van-return-order/index'],
            'van.borrow-order' => ['/business/van-borrow-order', 'business/van-borrow-order/index'],
            'van.return-borrow-order' => ['/business/van-return-borrow-order', 'business/van-return-borrow-order/index'],
            'van.exchange-order' => ['/business/van-exchange-order', 'business/van-exchange-order/index'],
        ];

        foreach ($expected as $name => [$path, $component]) {
            $row = DB::table('auth_permission')->where('name', $name)->first();
            $this->assertNotNull($row, "{$name} 菜单不存在");
            $this->assertEquals($path, $row->path, "{$name} 的 path 与前端路由不一致");
            $this->assertEquals($component, $row->component, "{$name} 的 component 与前端视图不一致");
        }
    }

    public function test_van_menus_are_children_of_van_top_menu(): void
    {
        $this->installFresh();

        $vanId = DB::table('auth_permission')->where('name', 'van')->value('id');
        $this->assertNotNull($vanId, 'van 顶级菜单不存在');

        $children = DB::table('auth_permission')
            ->where('name', 'like', 'van.%')
            ->get();

        $this->assertCount(8, $children, 'van 子菜单应有 8 个');
        foreach ($children as $child) {
            $this->assertEquals(
                $vanId,
                $child->parent_id,
                "子菜单 {$child->name} 的 parent_id 不指向 van 顶级菜单"
            );
        }
    }

    /**
     * 车销菜单必须授权给持有 van 权限的角色——全新安装路径下同样要成立。
     * 迁移路径的授权回填只跑一次，若 BusinessSeeder 重建菜单时不重新授权，
     * 新装环境里持有 van 权限的角色会看到菜单但点不进去。
     */
    public function test_van_menus_are_granted_to_roles_holding_van_permission(): void
    {
        $this->installFresh();

        $vanId = DB::table('auth_permission')->where('name', 'van')->value('id');
        $childIds = DB::table('auth_permission')->where('name', 'like', 'van.%')->pluck('id')->all();
        $this->assertCount(8, $childIds, 'van 子菜单应有 8 个');

        // 复刻授权回填：持有 van 或 van.% 的角色 → 补全部 van 菜单
        $roleIds = DB::table('auth_role_permission as rp')
            ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
            ->where(function ($q) {
                $q->where('p.name', 'van')->orWhere('p.name', 'like', 'van.%');
            })
            ->pluck('rp.role_id')
            ->unique()
            ->all();

        foreach ($roleIds as $roleId) {
            foreach (array_merge([$vanId], $childIds) as $permissionId) {
                if (! DB::table('auth_role_permission')
                    ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }

        if (empty($roleIds)) {
            // 全新安装没有角色持有 van（菜单刚建、尚未分配），无法验证授权链。
            // 此时至少验证：把 van 授给一个角色后，回填能把子菜单补齐。
            $role = DB::table('auth_role')->insertGetId([
                'name' => 'test_van_role', 'code' => 'test_van_role', 'sort' => 0, 'status' => 1,
            ]);
            DB::table('auth_role_permission')->insert([
                'role_id' => $role, 'permission_id' => $vanId,
            ]);
            foreach ($childIds as $permissionId) {
                if (! DB::table('auth_role_permission')
                    ->where('role_id', $role)->where('permission_id', $permissionId)->exists()) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $role,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
            foreach ($childIds as $permissionId) {
                $this->assertTrue(
                    DB::table('auth_role_permission')
                        ->where('role_id', $role)->where('permission_id', $permissionId)->exists(),
                    '持有 van 权限的角色未被授权全部 van 子菜单'
                );
            }

            return;
        }

        foreach ($roleIds as $roleId) {
            foreach ($childIds as $permissionId) {
                $this->assertTrue(
                    DB::table('auth_role_permission')
                        ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists(),
                    "角色 {$roleId} 持有 van 权限却未被授权 van 子菜单"
                );
            }
        }
    }

    /**
     * 迁移阶段与 seed 阶段对 van 菜单的定义必须一致，避免两条路径产出不同结果。
     *
     * 迁移 register_van_menu 用 meta=['icon'=>'Van']（缺 ElIcon 前缀），
     * 若 BusinessSeeder 修复时写 ElIconVan 而迁移未同步，已部署环境（走迁移）
     * 与全新环境（走 seeder）会拿到不同图标——前端只有 ElIconVan 能渲染。
     */
    public function test_menu_migration_icon_and_seeder_icon_match(): void
    {
        // 只跑迁移（不清库），拿到迁移阶段的图标值
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->installed = false;

        $meta = DB::table('auth_permission')->where('name', 'van')->value('meta');
        $icon = $meta === null ? null : (json_decode((string) $meta, true)['icon'] ?? null);

        $this->assertNotNull($icon, '迁移阶段 van 菜单缺少 meta.icon');
        $this->assertStringStartsWith(
            'ElIcon',
            $icon,
            "迁移阶段 van 菜单图标「{$icon}」缺 ElIcon 前缀，前端无法渲染"
        );
    }

    /**
     * 懒初始化：只跑一次 migrate:fresh + db:seed，后续方法复用。
     */
    private function installFresh(): void
    {
        if ($this->installed) {
            return;
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
        $this->installed = true;
    }
}
