<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Performance\Models\SalesTarget;

/**
 * 销售目标（任务书 §九：GET/POST /perf/sales-targets）。
 * 支撑分析页业务员达成率：按 (employee_id, period) 唯一，POST 幂等 upsert。
 */
class SalesTargetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SalesTarget::query()->orderByDesc('period')->orderByDesc('id');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->input('employee_id'));
        }
        if ($request->filled('period')) {
            $query->where('period', $request->input('period'));
        }

        $page = $query->paginate(min(100, max(1, (int) $request->input('page_size', 30))));

        return $this->paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|min:1',
            'period' => 'required|string|date_format:Y-m',
            'target_amount' => 'required|numeric|min:0',
        ]);

        $target = SalesTarget::updateOrCreate(
            ['employee_id' => (int) $data['employee_id'], 'period' => $data['period']],
            [
                'target_type' => 'employee',
                'target_id' => (int) $data['employee_id'],
                'target_amount' => (float) $data['target_amount'],
            ]
        );

        return $this->success($target, '保存成功');
    }
}
