<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'price')->value('id');
        if (! $parentId) {
            return;
        }
        $items = [
            ['name' => 'price.level', 'title' => '客户等级', 'path' => '/business/customer-level', 'component' => 'business/customer-level/index', 'sort' => 3],
            ['name' => 'price.product', 'title' => '商品价格', 'path' => '/business/product-price', 'component' => 'business/product-price/index', 'sort' => 4],
            ['name' => 'price.batch', 'title' => '批量调价', 'path' => '/business/price-batch', 'component' => 'business/price-batch/index', 'sort' => 5],
        ];
        foreach ($items as $m) {
            if (! DB::table('auth_permission')->where('name', $m['name'])->exists()) {
                DB::table('auth_permission')->insert([
                    'title' => $m['title'], 'name' => $m['name'], 'type' => 'menu',
                    'parent_id' => $parentId, 'path' => $m['path'], 'component' => $m['component'],
                    'sort' => $m['sort'], 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $mid = DB::table('auth_permission')->where('name', $m['name'])->value('id');
            $roleIds = DB::table('auth_role_permission as rp')
                ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
                ->where('p.name', 'price')->pluck('rp.role_id');
            foreach ($roleIds as $rid) {
                if (! DB::table('auth_role_permission')->where('role_id', $rid)->where('permission_id', $mid)->exists()) {
                    DB::table('auth_role_permission')->insert(['role_id' => $rid, 'permission_id' => $mid, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['price.level', 'price.product', 'price.batch'] as $n) {
            $id = DB::table('auth_permission')->where('name', $n)->value('id');
            if ($id) {
                DB::table('auth_role_permission')->where('permission_id', $id)->delete();
                DB::table('auth_permission')->where('id', $id)->delete();
            }
        }
    }
};
