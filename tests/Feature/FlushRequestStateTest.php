<?php

namespace Tests\Feature;

use App\Http\Middleware\FlushRequestState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Auth\Seeders\AuthSeeder;
use Tests\TestCase;

/**
 * 常驻进程（laravel-s）请求间状态泄漏回归测试
 *
 * 背景：JWTGuard::user() 缓存命中时不校验 token，JWT 单例的 token 缓存也不回读
 * 请求头。一次请求解析出的身份会一直挂在 worker 进程里，直到下一次 flush。
 * 本套件在同一个 TestCase 实例内连发多次请求（容器复用，等价于生产 worker），
 * 断言每个请求只认自己请求上的 token。
 *
 * 这四个用例在修复前全部为红：不带 token 返回 200、第二个用户拿到第一个用户的
 * 身份、已登出 token 仍然有效。
 */
class FlushRequestStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthSeeder::class);
    }

    public function test_flush_clears_resolved_guards_and_jwt_token_cache(): void
    {
        auth('admin')->setUser(User::where('username', 'admin')->first());
        $this->assertTrue(auth('admin')->check(), '前提：guard 已缓存用户');

        app('tymon.jwt')->setToken('a.b.c');
        $this->assertNotNull(app('tymon.jwt')->getToken(), '前提：JWT 单例已缓存 token');

        app(FlushRequestState::class)->flush();

        // 当前没有请求上下文、也没有 Authorization 头，所以必须是「重新解析 → 无身份」
        $this->assertFalse(
            auth('admin')->check(),
            'flush 后 guard 不能沿用上一个请求缓存的用户'
        );
        $this->assertNull(
            app('tymon.jwt')->getToken(),
            'flush 后 JWT 单例的 token 缓存必须清空'
        );
    }

    public function test_request_without_token_cannot_reuse_previous_identity(): void
    {
        $this->withToken($this->login('admin'))->getJson('/admin/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.username', 'admin');

        // 上一个请求已把 admin 写进 guard 缓存；不带任何 token 的下一个请求必须被拒
        // 注意 withoutToken()：withToken() 写的是 defaultHeaders，会原样串到下一个请求上
        $this->withoutToken()->getJson('/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', '未登录或token已过期');
    }

    public function test_second_request_uses_its_own_token_not_previous_identity(): void
    {
        $this->withToken($this->login('admin'))->getJson('/admin/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.username', 'admin');

        // 拿 manager（普通管理员，非超管）的 token 再请求一次，
        // 身份必须是 manager，不能是 guard 里残留的 admin
        $this->withToken($this->login('manager'))->getJson('/admin/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.username', 'manager')
            ->assertJsonPath('data.roles', ['管理员']);
    }

    public function test_blacklisted_token_is_rejected_even_when_guard_was_cached(): void
    {
        $token = $this->login('admin');

        $this->withToken($token)->getJson('/admin/auth/me')->assertStatus(200);

        $this->withToken($token)->postJson('/admin/auth/logout')->assertStatus(200);

        // 登出把 token 放进黑名单；guard 缓存还没清的话，这个请求仍会 200
        $this->withToken($token)->getJson('/admin/auth/me')->assertStatus(401);
    }

    public function test_request_handled_event_flushes_state(): void
    {
        $this->withToken($this->login('admin'))->getJson('/admin/auth/me')
            ->assertStatus(200);

        $this->withoutToken()->getJson('/admin/auth/me')->assertStatus(401);
    }

    // ---------------------------------------------------------------- 助手

    private function login(string $username): string
    {
        $password = match ($username) {
            'admin' => 'admin888',
            default => '123456789',
        };

        $response = $this->postJson('/admin/auth/login', compact('username', 'password'));
        $response->assertStatus(200);

        return (string) $response->json('data.token');
    }
}
