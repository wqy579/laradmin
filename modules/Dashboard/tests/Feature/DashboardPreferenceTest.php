<?php

namespace Tests\Dashboard\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * E8 仪表盘编辑布局——按用户持久化。
 *
 * 验证 dashboard_user_preference 的读写端到端：未保存时返回 null（前端回落默认布局），
 * 保存后按 user_id 读回；且不同用户互不串扰（一个用户的布局不能盖到另一个用户头上）。
 */
class DashboardPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_returns_null_when_never_saved(): void
    {
        $user = $this->actingAsAdmin();

        $res = $this->getJson('/admin/dashboard/preference/layout')->assertOk();
        $this->assertNull($res->json('data.layout'), '从未保存过布局时应返回 null，让前端回落默认布局');
        $this->assertSame(
            0,
            (int) DB::table('dashboard_user_preference')->where('user_id', $user->id)->count(),
            'GET 不应产生任何写库副作用'
        );
    }

    public function test_save_layout_then_read_back(): void
    {
        $user = $this->actingAsAdmin();

        $layout = [
            ['i' => 'stat-users', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['i' => 'stat-orders', 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
            ['i' => 'chart-line', 'x' => 0, 'y' => 2, 'w' => 6, 'h' => 4],
            ['i' => 'chart-bar', 'x' => 6, 'y' => 2, 'w' => 6, 'h' => 4],
        ];

        $this->putJson('/admin/dashboard/preference/layout', ['layout' => $layout])
            ->assertOk()
            ->assertJsonPath('data.layout', $layout);

        $row = DB::table('dashboard_user_preference')->where('user_id', $user->id)->first();
        $this->assertNotNull($row, '保存后应有该用户一行');
        $this->assertSame($layout, json_decode($row->layout, true), '库里存的布局应与提交一致');

        $readBack = $this->getJson('/admin/dashboard/preference/layout')->assertOk();
        $this->assertSame($layout, $readBack->json('data.layout'), '读回应与保存一致');
    }

    public function test_save_layout_is_scoped_per_user(): void
    {
        $userA = $this->actingAsAdmin(['username' => 'user_a_e8']);
        $userB = $this->makeAdmin(['username' => 'user_b_e8']);

        $layoutA = [['i' => 'stat-users', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2]];
        $this->putJson('/admin/dashboard/preference/layout', ['layout' => $layoutA])->assertOk();

        // 用 B 的 token 读 A 的布局——B 从未保存过，应拿到 null，而不是 A 的布局
        $this->resetJwtState();
        $this->withToken($this->loginToken($userB));
        $resB = $this->getJson('/admin/dashboard/preference/layout')->assertOk();
        $this->assertNull($resB->json('data.layout'), 'B 不应看到 A 的布局');
    }

    public function test_save_layout_validates_shape(): void
    {
        $this->actingAsAdmin();

        // 缺 w / 缺 i 都是 422，不能落库
        $this->putJson('/admin/dashboard/preference/layout', [
            'layout' => [['i' => 'stat-users', 'x' => 0, 'y' => 0, 'h' => 2]],
        ])->assertStatus(422);

        $this->putJson('/admin/dashboard/preference/layout', ['layout' => 'not-an-array'])->assertStatus(422);

        $this->assertSame(
            0,
            (int) DB::table('dashboard_user_preference')->count(),
            '非法布局不能落库'
        );
    }

    public function test_save_layout_overwrites_existing(): void
    {
        $this->actingAsAdmin();

        $first = [['i' => 'stat-users', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2]];
        $second = [['i' => 'chart-line', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 4]];

        $this->putJson('/admin/dashboard/preference/layout', ['layout' => $first])->assertOk();
        $this->putJson('/admin/dashboard/preference/layout', ['layout' => $second])->assertOk();

        $this->assertSame(1, (int) DB::table('dashboard_user_preference')->count(), '重复保存应覆写而非新增行');
        $row = DB::table('dashboard_user_preference')->first();
        $this->assertSame($second, json_decode($row->layout, true));
    }
}
