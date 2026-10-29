<?php

namespace Tests\Dashboard\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        // MySQL 的 json 列会按字母序重排对象键（h,i,w,x,y），SQLite 保持插入序（i,x,y,w,h）。
        // 布局项是无序键值对象，比较前归一化键序，避免断言被存储引擎差异误伤。
        $this->assertSame(
            $this->normalizeLayout($layout),
            $this->normalizeLayout(json_decode($row->layout, true)),
            '库里存的布局应与提交一致'
        );

        $readBack = $this->getJson('/admin/dashboard/preference/layout')->assertOk();
        $this->assertSame(
            $this->normalizeLayout($layout),
            $this->normalizeLayout($readBack->json('data.layout')),
            '读回应与保存一致'
        );
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
        // 同上：MySQL json 列重排键序，比较前归一化。
        $this->assertSame(
            $this->normalizeLayout($second),
            $this->normalizeLayout(json_decode($row->layout, true))
        );
    }

    /**
     * 把布局项按键名排序后返回，消除 MySQL json 列（字母序）与 SQLite（插入序）的键序差异。
     * 布局项是 {i,x,y,w,h} 无序键值对象，语义与键序无关；键排序后逐项一致即可判定等价。
     */
    private function normalizeLayout(?array $layout): array
    {
        if ($layout === null) {
            return [];
        }

        $normalized = [];
        foreach ($layout as $item) {
            ksort($item);
            $normalized[] = $item;
        }

        return $normalized;
    }
}
