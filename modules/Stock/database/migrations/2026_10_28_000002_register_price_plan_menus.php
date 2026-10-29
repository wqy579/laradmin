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
            ['name' => 'price.plan', 'title' => '价格方案', 'path' => '/business/price-plan', 'component' => 'business/price-plan/index', 'sort' => 6],
            ['name' => 'price.special', 'title' => '客户特价', 'path' => '/business/customer-special-price', 'component' => 'business/customer-special-price/index', 'sort' => 7],
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
        foreach (['price.plan', 'price.special'] as $n) {
            $id = DB::table('auth_permission')->where('name', $n)->value('id');
            if ($id) {
                DB::table('auth_role_permission')->where('permission_id', $id)->delete();
                DB::table('auth_permission')->where('id', $id)->delete();
            }
        }
    }
};
