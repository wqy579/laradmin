<?php

namespace Tests;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Business\Customer;
use App\Models\Business\Product;
use App\Models\Business\ProductCategory;
use App\Models\Business\Supplier;
use App\Models\Business\Warehouse;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 用例间隔离：清空进程内缓存（包含登录限流计数、幂等标记），避免用例串扰
        Cache::flush();

        // StockSnapshotService::snapshotToday() 使用 MySQL 专用 SQL（CURDATE()/NOW()/ON DUPLICATE KEY），
        // 在 SQLite 测试库上会抛 QueryException，且 StockSnapshotMiddleware 不捕获该异常，
        // 导致所有 /admin/* 请求直接 500。这里预热“今日已快照”标记让中间件短路；
        // 快照服务自身的行为在 Unit\StockSnapshotServiceTest 中单独验证。
        Cache::put('stock_snapshot_last_date', now()->toDateString(), now()->addDays(2));
    }

    /**
     * 创建一个绑定 super_admin 角色的后台用户（不依赖种子）
     */
    protected function makeAdmin(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'username' => 'tester_'.Str::random(8),
            'password' => Hash::make('secret123'),
            'real_name' => '测试管理员',
            'status' => 1,
        ], $attributes));

        $role = Role::query()->firstOrCreate(
            ['code' => 'super_admin'],
            ['name' => '超级管理员', 'sort' => 0, 'status' => 1]
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /**
     * 创建并以 admin guard 身份登录
     */
    protected function actingAsAdmin(array $attributes = []): User
    {
        $user = $this->makeAdmin($attributes);
        $this->actingAs($user, 'admin');

        return $user;
    }

    protected function makeCategory(array $attributes = []): ProductCategory
    {
        return ProductCategory::create(array_merge([
            'name' => '分类_'.Str::random(6),
            'parent_id' => 0,
            'is_main' => false,
            'sort_order' => 0,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => '商品_'.Str::random(8),
            'code' => 'P'.strtoupper(Str::random(6)),
            'is_active' => true,
            'is_online' => true,
        ], $attributes));
    }

    protected function makeWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'code' => 'WH'.strtoupper(Str::random(6)),
            'name' => '仓库_'.Str::random(6),
            'is_active' => true,
        ], $attributes));
    }

    protected function makeSupplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'code' => 'SUP'.strtoupper(Str::random(6)),
            'name' => '供应商_'.Str::random(6),
            'is_active' => true,
        ], $attributes));
    }

    protected function makeCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'CUST'.strtoupper(Str::random(6)),
            'name' => '客户_'.Str::random(6),
            'is_active' => true,
        ], $attributes));
    }
}
