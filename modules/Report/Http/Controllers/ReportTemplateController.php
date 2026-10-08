<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 报表查询模版。
 *
 * 一个模版就是一份查询条件的 JSON 快照，不做任何字段校验——
 * 条件字段由各报表自己解释，模版只负责存取，这样加筛选条件时不用同步改这里。
 *
 * 可见性：公共模版所有人可见，私有模版只有创建人可见；删除同理，
 * 不允许删别人的模版（避免共享模版被误删影响其他人看数）。
 */
class ReportTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report' => 'required|string|max:50',
        ]);

        $userId = (int) auth('admin')->id();

        $list = DB::table('report_templates')
            ->where('report', $validated['report'])
            ->where(function ($q) use ($userId) {
                $q->where('is_public', 1)->orWhere('created_by', $userId);
            })
            ->orderByDesc('id')
            ->get();

        return $this->success($list);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report' => 'required|string|max:50',
            'name' => 'required|string|max:100',
            'conditions' => 'required|array',
            'is_public' => 'nullable|boolean',
        ]);

        $userId = (int) auth('admin')->id();
        $creatorName = DB::table('auth_user')->where('id', $userId)->value('real_name');

        $id = DB::table('report_templates')->insertGetId([
            'report' => $validated['report'],
            'name' => $validated['name'],
            'conditions' => json_encode($validated['conditions'], JSON_UNESCAPED_UNICODE),
            'created_by' => $userId,
            'creator_name' => $creatorName,
            'is_public' => (bool) ($validated['is_public'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->created(['id' => $id], '模版已保存');
    }

    public function destroy(int $id): JsonResponse
    {
        $userId = (int) auth('admin')->id();

        $deleted = DB::table('report_templates')
            ->where('id', $id)
            ->where('created_by', $userId)
            ->delete();

        return $deleted ? $this->success(null, '模版已删除') : $this->error('模版不存在或无权删除', 403);
    }
}
