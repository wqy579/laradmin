<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Performance\Models\CommissionOrder;
use Modules\Report\Support\ReportFilter;

/**
 * 提成单报表：列表 / 详情 / 审核 / 驳回 / 标记发放 / 导出工资条。
 */
class CommissionOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CommissionOrder::query()->orderByDesc('id');

        if ($request->filled('period_start')) {
            $query->where('period', '>=', $request->input('period_start'));
        }
        if ($request->filled('period_end')) {
            $query->where('period', '<=', $request->input('period_end'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }
        if ($request->filled('keyword')) {
            $kw = '%'.$request->input('keyword').'%';
            $query->where(function ($q) use ($kw) {
                $q->where('bill_no', 'like', $kw)->orWhere('employee_name', 'like', $kw);
            });
        }

        $page = $query->paginate(min(100, max(1, (int) $request->input('page_size', 30))));

        return $this->paginated($page);
    }

    public function show(int $id): JsonResponse
    {
        $order = CommissionOrder::with('items')->findOrFail($id);

        return $this->success($order);
    }

    public function audit(Request $request, int $id): JsonResponse
    {
        $order = CommissionOrder::findOrFail($id);
        if ($order->status !== 'pending') {
            return $this->error('只有待审核状态可审核', 400);
        }
        $user = $request->attributes->get('auth_user');
        $order->update([
            'status' => 'audited',
            'auditor_id' => $user?->id,
            'auditor_name' => $user?->real_name,
            'audited_at' => now(),
        ]);

        return $this->success(null, '审核成功');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $order = CommissionOrder::findOrFail($id);
        if (! in_array($order->status, ['pending', 'audited'])) {
            return $this->error('当前状态不可驳回', 400);
        }
        $order->update(['status' => 'rejected']);

        return $this->success(null, '已驳回');
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $order = CommissionOrder::findOrFail($id);
        if ($order->status !== 'audited') {
            return $this->error('只有已审核状态可标记发放', 400);
        }
        $order->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        // 预留：标记发放后可选择生成一笔费用单（接现有费用模块"销售费用-工资提成"）
        // 本期不强制联动，留 TODO。

        return $this->success(null, '已标记发放');
    }

    /** 导出工资条 CSV */
    public function export(Request $request, int $id)
    {
        $order = CommissionOrder::with('items')->findOrFail($id);

        $headers = ['项目', '金额(¥)'];
        $rows = [
            ['提成单号', $order->bill_no],
            ['所属月份', $order->period],
            ['业务员', $order->employee_name],
            ['部门', $order->department_name ?: '-'],
            ['销售额', number_format((float) $order->sale_amount, 2)],
            ['回款额', number_format((float) $order->payment_amount, 2)],
            ['退货金额', number_format((float) $order->return_amount, 2)],
            ['毛利', number_format((float) $order->gross_profit, 2)],
            ['应发提成', number_format((float) $order->commission_gross, 2)],
            ['扣减', number_format((float) $order->commission_deduct, 2)],
            ['实发提成', number_format((float) $order->commission_amount, 2)],
        ];
        foreach ($order->items as $item) {
            $rows[] = ['明细：'.$item->source_no, number_format((float) $item->amount, 2)];
        }
        $rows[] = ['制单人', $order->maker_name ?: '-'];
        $rows[] = ['审核人', $order->auditor_name ?: '-'];

        $csv = ReportFilter::csv('提成工资条 '.$order->bill_no, $headers, $rows);

        return ReportFilter::csvResponse($csv, 'commission_'.$order->bill_no.'.csv');
    }
}
