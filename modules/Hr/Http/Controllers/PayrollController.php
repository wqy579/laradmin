<?php

namespace Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Business\Models\Employee;
use Modules\Hr\Models\Payroll;
use Modules\Hr\Services\PayrollService;
use Modules\Report\Support\ReportFilter;

/**
 * 工资核算控制器（F4，hr/payroll 前缀）。
 *
 * 列表带合计行（stat），工资条走 show（360px 抽屉数据源）；
 * 发放后单据锁定，改/删由 Service 抛 DomainException → 422。
 */
class PayrollController extends Controller
{
    use ResponseTrait;

    public function __construct(private PayrollService $service) {}

    /** 下拉：业务员（取 employees，users.id 口径与 perf 一致） */
    public function options(): JsonResponse
    {
        $employees = Employee::query()
            ->whereNotNull('user_id')
            ->where('is_active', 1)
            ->orderBy('id')
            ->get(['id', 'user_id', 'name', 'base_salary'])
            ->map(fn (Employee $e) => [
                'id' => (int) $e->user_id,
                'name' => (string) $e->name,
                'base_salary' => (float) $e->base_salary,
            ])
            ->all();

        return $this->success(['employees' => $employees]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));

        $pager = $this->service->paginate($filters, $page, $pageSize);

        return $this->success([
            'list' => collect($pager->items())->map(fn (Payroll $p) => $this->transform($p))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'stat' => $this->service->stat($filters),
        ]);
    }

    /** 工资条（360px 抽屉）：应发公式明细 + 提成单明细 + 付款信息 */
    public function show(int $id): JsonResponse
    {
        $detail = $this->service->detail(Payroll::query()->findOrFail($id));

        return $this->success([
            'payroll' => $this->transform($detail['payroll'], true),
            'formula' => $detail['formula'],
            'commission_orders' => $detail['commission_orders']->map(fn ($o) => [
                'bill_no' => $o->bill_no,
                'period' => $o->period,
                'status' => $o->status,
                'commission_amount' => (float) $o->commission_amount,
                'deduction' => (float) $o->deduction,
                'actual_amount' => (float) $o->actual_amount,
            ])->values(),
            'pay' => $detail['pay'] ? [
                'pay_no' => $detail['pay']->pay_no,
                'amount' => (float) $detail['pay']->amount,
                'pay_date' => (string) $detail['pay']->pay_date,
                'payment_method' => $detail['pay']->payment_method,
                'status' => (int) $detail['pay']->status,
            ] : null,
        ]);
    }

    /** 按期间从 perf 提成单生成草稿工资单 */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'employee_ids' => ['array'],
            'employee_ids.*' => ['integer'],
        ]);

        $result = $this->service->generate($data['period'], $data['employee_ids'] ?? []);

        return $this->success($result, "已生成 {$result['created']} 张工资单，跳过 {$result['skipped']} 张已存在");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $payroll = Payroll::query()->findOrFail($id);

        $data = $request->validate([
            'base_amount' => ['sometimes', 'numeric', 'min:0'],
            'performance_amount' => ['sometimes', 'numeric', 'min:0'],
            'deduction_amount' => ['sometimes', 'numeric', 'min:0'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        try {
            $payroll = $this->service->updateDraft($payroll, $data);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->transform($payroll), '已保存');
    }

    public function destroy(int $id): JsonResponse
    {
        $payroll = Payroll::query()->findOrFail($id);

        try {
            $this->service->destroyDraft($payroll);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, '已删除');
    }

    /** 发放：生成付款单 + 资金流水，状态锁定（幂等） */
    public function pay(Request $request, int $id): JsonResponse
    {
        $payroll = Payroll::query()->findOrFail($id);

        $data = $request->validate([
            'paid_date' => ['sometimes', 'date'],
            'payment_method' => ['sometimes', 'string', 'max:50'],
        ]);

        if ((float) $payroll->net_amount < 0) {
            return $this->error('应发净额为负，不能发放，请先核对扣项', 422);
        }

        $payroll = $this->service->pay($payroll, $data, (int) ($request->user()->id ?? 0));
        $wasPaid = $payroll->wasChanged('status');

        return $this->success($this->transform($payroll), $wasPaid ? '发放成功，已生成付款单' : '该工资单已发放');
    }

    /** 工资表导出（与列表同筛选口径） */
    public function export(Request $request)
    {
        $rows = [];
        foreach ($this->service->listQuery($this->filters($request))->orderByDesc('period')->orderBy('employee_id')->limit(50000)->get() as $p) {
            $rows[] = [
                $p->payroll_no, $p->period, $p->employee_name, $p->department_name ?? '',
                number_format((float) $p->base_amount, 2, '.', ''),
                number_format((float) $p->commission_amount, 2, '.', ''),
                number_format((float) $p->performance_amount, 2, '.', ''),
                number_format((float) $p->deduction_amount, 2, '.', ''),
                number_format((float) $p->net_amount, 2, '.', ''),
                Payroll::statusLabel($p->status),
                $p->pay_no ?? '', $p->paid_date ? (string) $p->paid_date : '',
            ];
        }

        $headers = ['工资单号', '期间', '业务员', '部门', '基本工资', '提成', '绩效', '扣项', '应发净额', '状态', '付款单号', '发放日期'];
        $csv = ReportFilter::csv('工资表', $headers, $rows);

        return ReportFilter::csvResponse($csv, 'payrolls_'.date('Ymd').'.csv');
    }

    private function filters(Request $request): array
    {
        return array_filter([
            'period' => $request->input('period'),
            'period_start' => $request->input('period_start'),
            'period_end' => $request->input('period_end'),
            'employee_id' => $request->input('employee_id'),
            'status' => $request->input('status'),
            'keyword' => $request->input('keyword'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function transform(Payroll $p, bool $withFlows = false): array
    {
        $row = [
            'id' => $p->id,
            'payroll_no' => $p->payroll_no,
            'period' => $p->period,
            'employee_id' => $p->employee_id,
            'employee_name' => $p->employee_name,
            'department_name' => $p->department_name,
            'base_amount' => (float) $p->base_amount,
            'commission_amount' => (float) $p->commission_amount,
            'performance_amount' => (float) $p->performance_amount,
            'deduction_amount' => (float) $p->deduction_amount,
            'net_amount' => (float) $p->net_amount,
            'status' => $p->status,
            'status_label' => Payroll::statusLabel($p->status),
            'pay_no' => $p->pay_no,
            'cash_flow_no' => $p->cash_flow_no,
            'paid_date' => $p->paid_date ? (string) $p->paid_date : null,
            'payment_method' => $p->payment_method,
            'remark' => $p->remark,
        ];

        if ($withFlows) {
            $row['commission_flows'] = $p->commissionFlows->map(fn ($f) => [
                'flow_no' => $f->flow_no,
                'bill_no' => $f->bill_no,
                'period' => $f->period,
                'commission_amount' => (float) $f->commission_amount,
                'actual_amount' => (float) $f->actual_amount,
            ])->values();
        }

        return $row;
    }
}
