<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 恢复「库存核对」菜单，并隐藏两个没有落地页的死菜单。
     *
     * 症状：侧边栏「库存管理」下没有「库存核对」，后端路由
     * business/liankai-stock-check 与前端页面
     * frontend/src/views/business/liankai-stock-check 都已就绪，页面完全不可达。
     *
     * 起因分两层：
     * ① 2026_09_11_000003_restore_stock_check_and_group_auth 确实插入过
     *    inventory.stock-check（线上 migrations 表 batch 116 已执行），但该行
     *    至今不在 auth_permission 里——说明插入后被后续操作抹掉了。
     * ② 真正的复发源：BusinessSeeder 的 $inventoryMenus 从来没有过这一项。
     *    AuthSeeder 在 BusinessSeeder 之前 Permission::truncate() 整张
     *    auth_permission，所以任何一次 db:seed / 换库 / 从备份恢复都会把
     *    这个菜单永久清掉，而它只靠一条一次性迁移撑着。
     *    这一族菜单在 5 天内被 9 条迁移反复增删改名（liankai-stock-check →
     *    monitor → query → stock-check），每次都只修当前库、不改 seeder，
     *    所以问题必然复发。这条迁移负责把当前库修好，seeder 负责以后不再丢。
     *
     * 顺带处理：inventory.scrap（报废录单 → /business/scrap）与
     * inventory.check（盘点单 → /business/inventory-check）既没有后端路由，
     * 也没有前端 view，点击直接落到 404。隐藏而不是删除——页面补上时
     * 把 status 改回 1 即可，不需要重新插行。
     *
     * 幂等：菜单按 name 判重；死菜单用 status 覆盖，天然可重复执行；
     * 角色授权先 exists() 再插入（auth_role_permission 没有
     * (role_id, permission_id) 唯一索引，insertOrIgnore 不会去重）。
     */
    public function up(): void
    {
        $inventoryId = DB::table('auth_permission')->where('name', 'inventory')->value('id');

        if ($inventoryId) {
            $this->insertMenu($inventoryId, [
                'name' => 'inventory.stock-check',
                'title' => '库存核对',
                'path' => '/business/liankai-stock-check',
                'component' => 'business/liankai-stock-check/index',
                'sort' => 20,
            ]);
        }

        foreach (['inventory.scrap', 'inventory.check'] as $dead) {
            DB::table('auth_permission')
                ->where('name', $dead)
                ->update(['status' => 0, 'updated_at' => now()]);
        }

        // 超级管理员走 isSuperAdmin() 分支不查这张表，这里只为其它角色补齐。
        // 判据是"已经能看见库存管理下的任意菜单"（父菜单或任一 inventory.* 子菜单），
        // 而不是只认父菜单——角色可能只被单独授了子菜单。
        $stockCheckId = DB::table('auth_permission')->where('name', 'inventory.stock-check')->value('id');
        if ($stockCheckId) {
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where(function ($q) {
                    $q->where('p.name', 'inventory')->orWhere('p.name', 'like', 'inventory.%');
                })
                ->pluck('rp.role_id');

            foreach ($roleIds as $roleId) {
                $exists = DB::table('auth_role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $stockCheckId)
                    ->exists();

                if (! $exists) {
                    DB::table('auth_role_permission')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $stockCheckId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $stockCheckId = DB::table('auth_permission')->where('name', 'inventory.stock-check')->value('id');
        if ($stockCheckId) {
            DB::table('auth_role_permission')->where('permission_id', $stockCheckId)->delete();
            DB::table('auth_permission')->where('id', $stockCheckId)->delete();
        }

        foreach (['inventory.scrap', 'inventory.check'] as $dead) {
            DB::table('auth_permission')->where('name', $dead)
                ->update(['status' => 1, 'updated_at' => now()]);
        }
    }

    private function insertMenu(int $parentId, array $menu): void
    {
        if (DB::table('auth_permission')->where('name', $menu['name'])->exists()) {
            return;
        }

        DB::table('auth_permission')->insert([
            'title' => $menu['title'],
            'name' => $menu['name'],
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
};
