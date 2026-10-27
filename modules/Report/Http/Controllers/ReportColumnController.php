<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 报表列配置持久化（E1 基座）。
 *
 * 前端表格列显隐 / 排序变化后 POST 到这里存成该用户该报表的一份 JSON 快照；
 * 打开报表时 GET 回放，实现「这次调好的列布局，下次打开还在」。
 *
 * 与查询模版（report_templates）不同：模版是可命名的多份共享条件，列配置是
 * 每人每报表唯一的一份布局偏好，无需命名、无需共享，冲突直接覆盖。
 */
class ReportColumnController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report' => 'required|string|max:50',
        ]);

        $userId = (int) auth('admin')->id();

        $row = DB::table('report_columns')
            ->where('report', $validated['report'])
            ->where('user_id', $userId)
            ->first();

        return $this->success($row ? json_decode($row->columns, true) : []);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report' => 'required|string|max:50',
            'columns' => 'required|array',
        ]);

        $userId = (int) auth('admin')->id();
        $report = $validated['report'];

        // 规范化：只保留 prop/label/visible 三个键，避免把后端下发列定义里无关字段存一堆冗余
        $columns = array_map(function ($c) {
            return [
                'prop' => (string) ($c['prop'] ?? ''),
                'label' => (string) ($c['label'] ?? ''),
                'visible' => (bool) ($c['visible'] ?? true),
            ];
        }, array_values($validated['columns']));

        $exists = DB::table('report_columns')
            ->where('report', $report)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            DB::table('report_columns')
                ->where('report', $report)
                ->where('user_id', $userId)
                ->update([
                    'columns' => json_encode($columns, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('report_columns')->insert([
                'report' => $report,
                'user_id' => $userId,
                'columns' => json_encode($columns, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->success($columns, '列配置已保存');
    }
}
