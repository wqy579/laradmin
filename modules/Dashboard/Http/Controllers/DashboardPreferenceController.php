<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 仪表盘用户偏好（E8 编辑布局持久化）。
 *
 * 独立于 DashboardController：后者是「纯聚合接口，无写操作」的只读看板，
 * 而这里是写操作（布局存库）。两者同属 dashboard 前缀，职责分离。
 *
 * 布局按 auth_user 维度存储，一个用户一行（dashboard_user_preference.user_id 唯一）。
 * 不存通用系统偏好——只存仪表盘 GridLayout 的 JSON 数组。
 */
class DashboardPreferenceController extends Controller
{
    use ResponseTrait;

    /**
     * 读取当前用户仪表盘布局。
     *
     * 返回 { layout: [...] }；从未保存过时 layout 为 null，前端回落 DEFAULT_LAYOUT，
     * 而不是在这里硬编码默认布局——默认布局属前端 widgets.js 的领域，后端不重复维护。
     */
    public function layout(Request $request): JsonResponse
    {
        $userId = auth('admin')->id();
        if (! $userId) {
            return $this->error('未登录', 401);
        }

        $row = DB::table('dashboard_user_preference')->where('user_id', $userId)->first();

        return $this->success([
            'layout' => $row ? json_decode((string) $row->layout, true) : null,
        ]);
    }

    /**
     * 保存当前用户仪表盘布局（整表覆写，与前端编辑完成时的全量布局语义一致）。
     *
     * 入参 { layout: [...] }，layout 必须是数组且每项含 i / x / y / w / h，
     * 否则 422（前端误传会在这里被拦下，而不是写坏布局）。
     */
    public function saveLayout(Request $request): JsonResponse
    {
        $userId = auth('admin')->id();
        if (! $userId) {
            return $this->error('未登录', 401);
        }

        $validated = $request->validate([
            'layout' => ['required', 'array'],
            'layout.*.i' => ['required', 'string'],
            'layout.*.x' => ['required', 'integer', 'min:0'],
            'layout.*.y' => ['required', 'integer', 'min:0'],
            'layout.*.w' => ['required', 'integer', 'min:1'],
            'layout.*.h' => ['required', 'integer', 'min:1'],
        ]);

        $layoutJson = json_encode(array_values($validated['layout']), JSON_UNESCAPED_UNICODE);
        $exists = DB::table('dashboard_user_preference')->where('user_id', $userId)->exists();

        if ($exists) {
            DB::table('dashboard_user_preference')->where('user_id', $userId)->update([
                'layout' => $layoutJson,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('dashboard_user_preference')->insert([
                'user_id' => $userId,
                'layout' => $layoutJson,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->success(['layout' => $validated['layout']], '布局已保存');
    }
}
