<?php

namespace Modules\Order\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Permission;

/**
 * 订货中心 / 订单域菜单权威定义。
 *
 * AuthSeeder 会 Permission::truncate() 整张 auth_permission，任何只在
 * migration 里注册的菜单都会在 db:seed 时被抹掉（同 PerformanceMenuSeeder
 * 的说明）。这里把 modules/Order/database/migrations 下
 * register_order_center_menu / register_visit_reminder_menu /
 * register_adjust_transfer_menu / register_promotion_menu /
 * register_purchase_return_menu，以及 Business 的 update_menu_structure
 * 里 order.transfer 的字段原样重建，数值一律照迁移抄，不自行"修正"。
 *
 * 幂等：firstOrCreate 按 name 判重，可反复执行。
 */
class OrderMenuSeeder extends Seeder
{
    /** 顶级分组「订货中心」：component 空串 + path 指向第一个子路由（项目硬约定） */
    private const GROUP = [
        'name' => 'order-center',
        'title' => '订货中心',
        'path' => '/order/order-check',
        'icon' => 'ElIconGoods',
        'sort' => 15,
    ];

    /** 挂「订单管理」（order 由 BusinessSeeder 建）的子菜单 */
    private const ORDER_CHILDREN = [
        ['name' => 'order.purchase-return', 'title' => '采购退货', 'path' => '/business/purchase-return', 'component' => 'business/purchase-return/index', 'sort' => 6],
        // 迁移 update_menu_structure 就没写 component（前端走 systemRoutes），
        // sort 取后续 sync_menu_order 覆盖后的最终值 5。
        ['name' => 'order.transfer', 'title' => '订单移库', 'path' => '/business/order-transfer', 'component' => null, 'sort' => 5],
        ['name' => 'order.promotion', 'title' => '促销管理', 'path' => '/business/promotion', 'component' => 'business/promotion/index', 'sort' => 20],
    ];

    /** 订货中心子菜单 */
    private const GROUP_CHILDREN = [
        ['name' => 'order.check', 'title' => '客户订货核对', 'path' => '/order/order-check', 'component' => 'order/order-check/index', 'sort' => 1],
        ['name' => 'order.visit-reminder', 'title' => '业务员拜访提醒', 'path' => '/order/visit-reminder', 'component' => 'order/visit-reminder/index', 'sort' => 2],
        ['name' => 'order.order-adjust', 'title' => '订货调整', 'path' => '/order/order-adjust', 'component' => 'order/order-adjust/index', 'sort' => 3],
        ['name' => 'order.order-transfer', 'title' => '订单移库', 'path' => '/order/order-transfer', 'component' => 'order/order-transfer/index', 'sort' => 4],
    ];

    public function run(): void
    {
        Permission::firstOrCreate(['name' => self::GROUP['name']], [
            'title' => self::GROUP['title'],
            'type' => 'menu',
            'parent_id' => 0,
            'path' => self::GROUP['path'],
            'component' => '',
            'sort' => self::GROUP['sort'],
            'status' => 1,
            'meta' => ['icon' => self::GROUP['icon']],
        ]);

        $groupId = (int) Permission::where('name', self::GROUP['name'])->value('id');
        $orderParentId = Permission::where('name', 'order')->value('id');

        $this->seedMenus(self::GROUP_CHILDREN, $groupId);

        // order 分组由 BusinessSeeder 建；单独跑本 seeder 时它可能还不存在，
        // 跳过而不是写 parent_id=null（该列 NOT NULL DEFAULT 0，写 0 会变成顶级菜单）。
        if ($orderParentId) {
            $this->seedMenus(self::ORDER_CHILDREN, (int) $orderParentId);
        }

        $this->grantToParentHolders(array_merge(
            array_column(self::GROUP_CHILDREN, 'name'),
            array_column(self::ORDER_CHILDREN, 'name')
        ));
    }

    private function seedMenus(array $menus, int $parentId): void
    {
        foreach ($menus as $menu) {
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $parentId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
            ]);
        }
    }

    /**
     * 与迁移同款授权：把菜单授给已持有其父菜单的角色（超管走 isSuperAdmin
     * 不查授权表，这里补的是 admin 等普通角色，否则它们和迁移期不一致）。
     */
    private function grantToParentHolders(array $names): void
    {
        foreach ($names as $name) {
            $permission = Permission::where('name', $name)->first();
            if (! $permission || ! $permission->parent_id) {
                continue;
            }
            $roleIds = DB::table('auth_role_permission')
                ->where('permission_id', $permission->parent_id)
                ->pluck('role_id');
            foreach ($roleIds as $roleId) {
                $granted = DB::table('auth_role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permission->id)
                    ->exists();
                if (! $granted) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permission->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
