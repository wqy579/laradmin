<?php

namespace Tests\Feature;

use Modules\Auth\Models\User;
use Modules\Auth\Seeders\AuthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T3 认证 Feature 测试
 *
 * 覆盖：登录成功/密码错误/账号禁用、参数校验、JWT 刷新与登出黑名单、
 * 登录限流（5 次/15 分钟，RateLimitMiddleware）、菜单与权限返回结构。
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

        // 注意：当前实现把 ValidationException 包进 try/catch，返回 HTTP 500 而非 401/422。
        // 这是既有对外行为，测试先钉住它；若后续统一为 401/422，请同步调整本用例。
        $response->assertStatus(500)
            ->assertJsonPath('code', 500)
            ->assertJsonPath('data', null);
        $this->assertStringContainsString('用户名或密码错误', (string) $response->json('message'));
    }

    public function test_login_unknown_user_returns_error(): void
    {
        $response = $this->postJson('/admin/auth/login', [
            'username' => 'no_such_user_xyz',
            'password' => 'whatever123',
        ]);

        $response->assertStatus(500);
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

        // 密码正确但 status != 1 → 账号禁用（同样走了 HTTP 500 包装，见上条用例说明）
        $response->assertStatus(500);
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
        $admin = User::where('username', 'admin')->first();

        $response = $this->actingAs($admin, 'admin')->getJson('/admin/auth/me');

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
        $admin = User::where('username', 'admin')->first();

        $response = $this->actingAs($admin, 'admin')->getJson('/admin/auth/permissions/menu');

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

    public function test_refresh_endpoint_returns_401_due_to_double_refresh_defect(): void
    {
        $token = $this->loginAndGetToken();

        $response = $this->postJson('/admin/auth/refresh', [], $this->bearer($token));

        // 已知缺陷（钉住现状，隔离/类内/全量三种跑法实测一致）：POST /admin/auth/refresh 永远 401。
        // 机制：AuthService::refresh() 连续调用两次 auth('admin')->refresh()。
        //   第一次：把当前 token 拉黑（jwt.blacklist_grace_period=0，立即生效）并签发新 token；
        //   第二次：JWT 单例仍缓存着同一个旧 token，decode 命中黑名单 → TokenBlacklistedException
        //   → Auth@refresh 的 catch(Exception) 吞掉 → HTTP 401 'Token无效或已过期'。
        // 前端“刷新会话”功能因此完全不可用。修复：AuthService::refresh() 只调用一次 refresh()；
        // 修复后请把本用例改回正向断言（200 + token/refreshToken 字段 + 用新 token 完成 me）。
        $response->assertStatus(401)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Token无效或已过期')
            ->assertJsonPath('data', null);
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

        $response = $this->actingAs($user, 'admin')->postJson('/admin/auth/change-password', [
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

        // 原密码错误时拒绝修改
        $again = $this->actingAs($fresh, 'admin')->postJson('/admin/auth/change-password', [
            'old_password' => 'wrong-old-password',
            'password' => 'another789',
            'password_confirmation' => 'another789',
        ]);
        $this->assertTrue(in_array($again->status(), [400, 422, 500]), '原密码错误应被拒绝');
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
            ])->assertStatus(500);
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

        // 另一个用户名不受影响（计数键 sha1(login|username|ip)）
        $this->postJson('/admin/auth/login', ['username' => 'rl_user_b', 'password' => 'bad'])
            ->assertStatus(500);
    }

    // ---------------------------------------------------------------- 助手

    private function loginAndGetToken(): string
    {
        $response = $this->postJson('/admin/auth/login', [
            'username' => 'admin',
            'password' => 'admin888',
        ]);
        $response->assertOk();

        return (string) $response->json('data.token');
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
