<?php

namespace Tests;

use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\ProductCategory;
use Modules\Order\Models\Supplier;
use Modules\Stock\Models\Warehouse;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 用例间隔离：清空进程内缓存（包含登录限流计数、幂等标记），避免用例串扰。
        // 顺带清掉「今日已快照」标记：StockSnapshotService 现在是可移植写法，
        // 中间件不再需要在测试里被预先短路。
        Cache::flush();
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
     * 创建一个绑定 super_admin 角色的后台用户，并以真实 JWT 身份登录
     *
     * 保留 actingAsAdmin() 这个名字是为了不改 50 多处调用点，但实现必须走真实 token：
     * 生产是常驻进程（laravel-s），身份只可能来自请求上的 Authorization 头，
     * FlushRequestState 中间件会主动清掉 actingAs() 注入的 guard 身份，
     * 用 actingAs() 在这里必然拿到 401——那不是测试写错，是它在假装一条不存在的链路。
     */
    protected function actingAsAdmin(array $attributes = []): User
    {
        $user = $this->makeAdmin($attributes);
        $this->withToken($this->loginToken($user));

        return $user;
    }

    /**
     * 为指定用户签发一枚真实 JWT（等价于走 login 端点，但不占登录限流计数）
     */
    protected function loginToken(User $user): string
    {
        return auth('admin')->login($user);
    }

    /**
     * 重置 tymon/jwt 的进程内状态，让下一个 HTTP 请求重新走一遍鉴权。
     *
     * 测试进程里所有 $this->getJson() 复用同一个 app 实例，而 tymon/jwt 有两处
     * 跨请求残留：
     *  - `tymon.jwt` 单例缓存着上一次请求的 token，getToken() 不再解析本次
     *    Authorization 头；
     *  - JWTGuard 缓存着上一次请求解析出的 user，user() 直接返回缓存而不校验。
     *
     * 后果：refresh/登出后旧 token 本应被黑名单拦下，却因命中上一轮的缓存而
     * 返回 200（黑名单完全没被问到）。生产环境每个请求都是新进程，不受影响。
     * 凡是「同一用例里换 token 再请求」的地方，请求前调一次本方法。
     */
    protected function resetJwtState(): void
    {
        app('tymon.jwt')->unsetToken();
        app('auth')->forgetGuards();
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
