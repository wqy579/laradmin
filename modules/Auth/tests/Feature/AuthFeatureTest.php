<?php

namespace Tests\Auth\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;
use Modules\Auth\Seeders\AuthSeeder;
use Tests\TestCase;

/**
 * T3 认证 Feature 测试
 *
 * 覆盖：登录成功/密码错误/账号禁用、参数校验、JWT 刷新与登出黑名单、
 * 登录限流（5 次/15 分钟，RateLimitMiddleware）、菜单与权限返回结构。
 *
 * ⚠️ 认证统一走 loginAs()/withToken()（真实 JWT），不要用 actingAs()。
 * 生产是常驻进程（laravel-s），身份只可能来自请求上的 token；
 * FlushRequestState 中间件会主动清掉进程内注入的 guard 身份，
 * actingAs() 在这里必然拿到 401——那不是测试写错，是它在假装一条不存在的链路。
 */
class AuthFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthSeeder::class);
    }

    // ---------------------------------------------------------------- 登录

    public function test_login_success_returns_token_user_menu_and_permissions(): void
    {
        $response = $this->postJson('/admin/auth/login', [
            'username' => 'admin',
            'password' => 'admin888',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('message', '登录成功');

        $data = $response->json('data');
        $this->assertNotEmpty($data['token'], '登录响应缺少 token');
        $this->assertGreaterThan(0, $data['expires_in'], '登录响应缺少 expires_in');
        $this->assertSame('admin', $data['user']['username']);
        $this->assertContains('超级管理员', $data['user']['roles'] ?? []);
        $this->assertIsArray($data['menu'], '登录响应缺少 menu');
        $this->assertIsArray($data['permissions'], '登录响应缺少 permissions');

        // 种子账号 admin 绑定 super_admin，应拿到全部菜单与权限
        $this->assertNotEmpty($data['menu']);
        $this->assertContains('home', $data['permissions']);
        $this->assertContains('auth.users.view', $data['permissions']);

        // 登录成功后更新 last_login_at
        $this->assertNotNull(User::where('username', 'admin')->value('last_login_at'));
    }

    public function test_login_wrong_password_returns_error(): void
    {
        $response = $this->postJson('/admin/auth/login', [
            'username' => 'admin',
            'password' => 'definitely-wrong',
        ]);

        // 凭证错误是 401，不是 500：此前 catch(Exception) 把它吞成 500，
        // 密码敲错一次就在 5xx 监控里记一条事故。
        $response->assertStatus(401)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('data', null);
        $this->assertStringContainsString('用户名或密码错误', (string) $response->json('message'));
    }

    public function test_login_unknown_user_returns_error(): void
    {
        $response = $this->postJson('/admin/auth/login', [
            'username' => 'no_such_user_xyz',
            'password' => 'whatever123',
        ]);

        $response->assertStatus(401);
        $this->assertStringContainsString('用户名或密码错误', (string) $response->json('message'));
    }

    public function test_login_rejected_for_disabled_account(): void
    {
        $this->makeAdmin([
            'username' => 'disabled_guy',
            'password' => Hash::make('pass123456'),
            'status' => 0,
        ]);

        $response = $this->postJson('/admin/auth/login', [
            'username' => 'disabled_guy',
            'password' => 'pass123456',
        ]);

        // 密码正确但 status != 1 → 账号禁用。仍然 401：登录失败的对外状态码只有一种，
        // 具体原因看 message，前端 interceptor 也按"有无 token"区分这两种 401。
        $response->assertStatus(401)
            ->assertJsonPath('code', 401);
        $this->assertStringContainsString('账号已被禁用', (string) $response->json('message'));
    }

    public function test_login_validates_required_fields(): void
    {
        // BaseFormRequest::failedValidation 输出 code/message/data 信封，中文文案
        $response = $this->postJson('/admin/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('data', null);
        $this->assertSame('请输入用户名', $response->json('message'));
    }

    // ---------------------------------------------------------------- 当前用户信息

    public function test_me_requires_token(): void
    {
        $response = $this->getJson('/admin/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', '未登录或token已过期');
    }

    public function test_me_rejects_garbage_token(): void
    {
        $response = $this->getJson('/admin/auth/me', [
            'Authorization' => 'Bearer not.a.real.jwt',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', '未登录或token已过期');
    }

    public function test_me_returns_profile_roles_and_permissions(): void
    {
        // 用真实 token 而不是 actingAs()：生产身份只来自 token，没有「进程内注入用户」
        // 这条路，而且常驻进程会清掉注入的身份（见 FlushRequestState 文件头的说明）。
        $response = $this->withToken($this->loginAndGetToken())->getJson('/admin/auth/me');

        $response->assertOk()->assertJsonPath('code', 200);

        $data = $response->json('data');
        $this->assertSame('admin', $data['username']);
        $this->assertSame('超级管理员', $data['real_name']);
        $this->assertSame(1, $data['status']);
        $this->assertContains('超级管理员', $data['roles']);
        $this->assertContains('auth.users.view', $data['permissions']);
        $this->assertArrayHasKey('department', $data);
        $this->assertArrayNotHasKey('password', $data, '用户信息不应泄漏密码字段');
    }

    // ---------------------------------------------------------------- 菜单

    public function test_menu_returns_nested_tree_for_super_admin(): void
    {
        $response = $this->withToken($this->loginAndGetToken())->getJson('/admin/auth/permissions/menu');

        $response->assertOk()->assertJsonPath('code', 200);

        $menu = $response->json('data');
        $this->assertIsArray($menu);
        $this->assertNotEmpty($menu);

        // 节点结构：path/name/title/meta(+component/redirect/children)
        foreach ($menu as $node) {
            $this->assertArrayHasKey('path', $node);
            $this->assertArrayHasKey('name', $node);
            $this->assertArrayHasKey('title', $node);
            $this->assertArrayHasKey('meta', $node);
        }

        $home = collect($menu)->firstWhere('name', 'home');
        $this->assertNotNull($home, '菜单树缺少 home 根节点');
        $this->assertNotEmpty($home['children'] ?? [], 'home 节点应包含子菜单');
        $childNames = array_column($home['children'], 'name');
        $this->assertContains('home.dashboard', $childNames);
        $this->assertContains('home.profile', $childNames);
    }

    // ---------------------------------------------------------------- 刷新 / 登出

    public function test_refresh_endpoint_rotates_token_and_returns_full_session_payload(): void
    {
        $oldToken = $this->loginAndGetToken();

        $response = $this->postJson('/admin/auth/refresh', [], $this->bearer($oldToken));

        $response->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('message', '刷新成功');

        $data = $response->json('data');
        $this->assertNotEmpty($data['token'], '刷新响应缺少 token');
        $this->assertNotEmpty($data['refreshToken'], '刷新响应缺少 refreshToken');
        // 单点 JWT 体系里没有独立的 refresh token，refreshToken 是同值别名（兼容老前端）
        $this->assertSame($data['token'], $data['refreshToken']);
        $this->assertNotSame($oldToken, $data['token'], '刷新后必须换发新 token');
        $this->assertSame('admin', $data['user']['username']);
        $this->assertIsArray($data['menu'], '刷新响应缺少 menu');
        $this->assertIsArray($data['permissions'], '刷新响应缺少 permissions');
        $this->assertNotEmpty($data['menu']);

        // 新 token 立即可用，旧 token 已被拉黑（jwt.blacklist_grace_period=0）
        $this->resetJwtState();
        $this->getJson('/admin/auth/me', $this->bearer($data['token']))
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.username', 'admin');

        $this->resetJwtState();
        $this->getJson('/admin/auth/me', $this->bearer($oldToken))
            ->assertStatus(401)
            ->assertJsonPath('message', '未登录或token已过期');
    }

    public function test_refresh_requires_valid_token(): void
    {
        // refresh 路由在 auth.check:admin 中间件组内，无令牌先被中间件拦截
        $this->postJson('/admin/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', '未登录或token已过期');
    }

    public function test_logout_blacklists_token(): void
    {
        $token = $this->loginAndGetToken();

        $this->postJson('/admin/auth/logout', [], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('message', '登出成功');

        // JWT 黑名单已启用（config/jwt.php blacklist_enabled=true），登出后旧 token 立即失效
        $this->resetJwtState();
        $this->getJson('/admin/auth/me', $this->bearer($token))
            ->assertStatus(401)
            ->assertJsonPath('message', '未登录或token已过期');
    }

    // ---------------------------------------------------------------- 修改密码

    public function test_change_password_flow(): void
    {
        $user = $this->makeAdmin([
            'username' => 'pw_changer',
            'password' => Hash::make('oldpass123'),
        ]);

        $response = $this->withToken($this->loginAs('pw_changer', 'oldpass123'))->postJson('/admin/auth/change-password', [
            'old_password' => 'oldpass123',
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('message', '密码修改成功');

        $fresh = User::find($user->id);
        $this->assertTrue(Hash::check('newpass456', $fresh->password), '新密码未生效');
        $this->assertFalse(Hash::check('oldpass123', $fresh->password), '旧密码应已失效');

        // 原密码错误时拒绝修改（用改过的新密码重新登录拿 token）
        $again = $this->withToken($this->loginAs('pw_changer', 'newpass456'))->postJson('/admin/auth/change-password', [
            'old_password' => 'wrong-old-password',
            'password' => 'another789',
            'password_confirmation' => 'another789',
        ]);
        // 原密码错误是校验失败，固定 422（此前被 catch(Exception) 吞成 500）
        $this->assertSame(422, $again->status(), '原密码错误应返回 422');
        $this->assertStringContainsString('原密码错误', (string) $again->json('message'));
        $this->assertFalse(Hash::check('another789', User::find($user->id)->password), '错误的原密码不应改掉密码');
    }

    // ---------------------------------------------------------------- 登录限流

    public function test_login_rate_limit_5_attempts_per_15_minutes(): void
    {
        // RateLimitMiddleware: login → max_attempts=5, decay_minutes=15，按 username+IP 计数
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/admin/auth/login', [
                'username' => 'rl_probe_user',
                'password' => 'bad-password',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/admin/auth/login', [
            'username' => 'rl_probe_user',
            'password' => 'bad-password',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('code', 429);
        $this->assertStringContainsString('请求过于频繁', (string) $response->json('message'));
        $this->assertIsInt($response->json('data.retry_after'), '限流响应应携带 retry_after');
        $this->assertGreaterThan(0, $response->json('data.retry_after'));
    }

    public function test_login_rate_limit_is_per_username(): void
    {
        // 用尽 one_user 的配额
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/admin/auth/login', [
                'username' => 'rl_user_a',
                'password' => 'bad',
            ]);
        }
        $this->postJson('/admin/auth/login', ['username' => 'rl_user_a', 'password' => 'bad'])
            ->assertStatus(429);

        // 另一个用户名不受影响（计数键 sha1(login|username)，不含 IP）。
        // 断言 401 而不是 429 就是在验证"未被限流"——密码错返回 401，被限流返回 429。
        $this->postJson('/admin/auth/login', ['username' => 'rl_user_b', 'password' => 'bad'])
            ->assertStatus(401);
    }

    public function test_login_rate_limit_is_not_bypassed_by_ip_rotation(): void
    {
        // 计数键只按账号（见 RateLimitMiddleware::resolveSignatures）：
        // 轮换出口 IP 换不来新配额。laravel-s 会把客户端自带的 X-Real-IP
        // 无条件写进 REMOTE_ADDR，IP 维度在 laravel-s 下是客户端可控的。
        for ($i = 1; $i <= 5; $i++) {
            $this->call('POST', '/admin/auth/login', [
                'username' => 'rl_ip_rotate',
                'password' => 'bad',
            ], [], [], ['REMOTE_ADDR' => '10.99.0.'.$i]);
        }

        $this->call('POST', '/admin/auth/login', [
            'username' => 'rl_ip_rotate',
            'password' => 'bad',
        ], [], [], ['REMOTE_ADDR' => '10.99.9.254'])
            ->assertStatus(429);
    }

    // ---------------------------------------------------------------- 助手

    private function loginAndGetToken(): string
    {
        return $this->loginAs('admin', 'admin888');
    }

    private function loginAs(string $username, string $password): string
    {
        $response = $this->postJson('/admin/auth/login', compact('username', 'password'));
        $response->assertOk();

        return (string) $response->json('data.token');
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
