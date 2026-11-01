<?php

namespace Modules\Stock\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 库存 / 价格 / 品牌档案菜单权威定义。
 *
 * AuthSeeder 会 Permission::truncate() 整张 auth_permission，只在 migration 里
 * 注册的菜单在 db:seed 后全部消失（同 PerformanceMenuSeeder 的说明）。这里重建：
 *   - inventory.*  ← Stock 的 register_stocktaking/stock_adjust_menu、
 *                    Order 的 register_purchase_application_menu、
 *                    Order 的 register_assembly_disassembly_menu、
 *                    Business 的 update_menu_structure（inventory.purchase）
 *   - price.*      ← Stock 的 register_price_menus、register_price_plan_menus
 *   - data.brand   ← Stock 的 register_brand_menu（父级 data 由 BusinessSeeder 建）
 * 字段照迁移抄；sort 取迁移之后 sync_menu_order/fix_menu_order 覆盖完的最终值。
 *
 * 刻意不重建 inventory.purchase：见 run() 里的注释。
 *
 * 幂等：firstOrCreate 按 name 判重，可反复执行。
 */
class StockMenuSeeder extends Seeder
{
    /** 挂「库存管理」（inventory 由 BusinessSeeder 建） */
    private const INVENTORY_CHILDREN = [
        ['name' => 'inventory.stocktaking', 'title' => '库存盘点', 'path' => '/business/stocktaking', 'component' => 'business/stocktaking/index', 'sort' => 30],
        ['name' => 'inventory.stock-adjust', 'title' => '库存调整', 'path' => '/business/stock-adjust', 'component' => 'business/stock-adjust/index', 'sort' => 31],
        ['name' => 'inventory.purchase-application', 'title' => '采购申请', 'path' => '/business/purchase-application', 'component' => 'business/purchase-application/index', 'sort' => 32],
        ['name' => 'inventory.assembly', 'title' => '商品组装单', 'path' => '/business/assembly', 'component' => 'business/assembly/index', 'sort' => 10],
        ['name' => 'inventory.disassembly', 'title' => '商品拆分单', 'path' => '/business/disassembly', 'component' => 'business/disassembly/index', 'sort' => 11],
    ];

    /** 挂「价格管理」（price 由 BusinessSeeder 建） */
    private const PRICE_CHILDREN = [
        ['name' => 'price.level', 'title' => '客户等级', 'path' => '/business/customer-level', 'component' => 'business/customer-level/index', 'sort' => 3],
        ['name' => 'price.product', 'title' => '商品价格', 'path' => '/business/product-price', 'component' => 'business/product-price/index', 'sort' => 4],
        ['name' => 'price.batch', 'title' => '批量调价', 'path' => '/business/price-batch', 'component' => 'business/price-batch/index', 'sort' => 5],
        ['name' => 'price.plan', 'title' => '价格方案', 'path' => '/business/price-plan', 'component' => 'business/price-plan/index', 'sort' => 6],
        ['name' => 'price.special', 'title' => '客户特价', 'path' => '/business/customer-special-price', 'component' => 'business/customer-special-price/index', 'sort' => 7],
    ];

    /** 挂「资料管理」（data 由 BusinessSeeder 建） */
    private const DATA_CHILDREN = [
        ['name' => 'data.brand', 'title' => '品牌档案', 'path' => '/business/brand', 'component' => 'business/brand/index', 'sort' => 1],
    ];

    public function run(): void
    {
        // inventory.purchase 不重建：迁移给的 component 是 business/purchase-order/index，
        // 该 .vue 不存在、也没有任何前端路由/后端路由叫 business/purchase-order，
        // 前端 loadComponent 解析失败会静默丢掉整个节点——它早就是死菜单，
        // 且与 inventory.purchase-application 同名（采购申请）。照抄会让
        // InventoryMenuTest 的「component 必须指向真实视图」用例失败，
        // 造页面又超出本次范围，故留空；已在 PR 说明里登记待转交。
        $this->seedUnder('inventory', self::INVENTORY_CHILDREN);
        $this->seedUnder('price', self::PRICE_CHILDREN);
        $this->seedUnder('data', self::DATA_CHILDREN);

        $this->grantToParentHolders(array_merge(
            array_column(self::INVENTORY_CHILDREN, 'name'),
            array_column(self::PRICE_CHILDREN, 'name'),
            array_column(self::DATA_CHILDREN, 'name')
        ));
    }

    private function seedUnder(string $parentName, array $menus): void
    {
        // 父级都由 BusinessSeeder 建；单独跑本 seeder 时可能还没有，
        // 跳过而不是写 parent_id=0（那会把子菜单变成顶级菜单）。
        $parentId = DB::table('auth_permission')->where('name', $parentName)->value('id');
        if (! $parentId) {
            return;
        }

        // 不借用 Modules\Auth\Models\Permission：Stock 与 Auth 之间本没有依赖边
        // （ArchitectureTest 的模块依赖白名单里没有 Stock->Auth），为写死数据
        // 引入跨模块模型不值得。权限迁移本来也用 DB::table 直写本表。
        // 注册 Permission 模型后跑本 seeder 请自行补 now() 时间戳。
        $existing = DB::table('auth_permission')
            ->whereIn('name', array_column($menus, 'name'))
            ->pluck('name')
            ->all();

        foreach ($menus as $menu) {
            if (in_array($menu['name'], $existing, true)) {
                continue;
            }
            DB::table('auth_permission')->insert([
                'name' => $menu['name'],
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * 与迁移同款授权：把菜单授给已持有其父菜单的角色（超管走 isSuperAdmin
     * 不查授权表，这里补的是 admin 等普通角色）。
     */
    private function grantToParentHolders(array $names): void
    {
        $permissions = DB::table('auth_permission')
            ->whereIn('name', $names)
            ->pluck('parent_id', 'name');
        foreach ($names as $name) {
            $parentId = $permissions[$name] ?? null;
            if (! $parentId) {
                continue;
            }
            $permissionId = DB::table('auth_permission')->where('name', $name)->value('id');
            $roleIds = DB::table('auth_role_permission')
                ->where('permission_id', $parentId)
                ->pluck('role_id');
            foreach ($roleIds as $roleId) {
                $granted = DB::table('auth_role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();
                if (! $granted) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
