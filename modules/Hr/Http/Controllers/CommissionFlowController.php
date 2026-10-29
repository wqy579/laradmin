<?php

namespace Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Business\Models\Employee;
use Modules\Hr\Models\CommissionFlow;
use Modules\Performance\Models\CommissionOrder;
use Modules\Report\Support\ReportFilter;

/**
 * 提成流水查询（F4）：按 业务员 / 期间 / 单据 查询。
 *
 * 数据来自工资发放时对 perf 提成单的快照（hr_commission_flows），
 * 金额列逐列复制 perf 口径（应发 commission_amount / 实发 actual_amount），
 * 只读，不提供编辑入口。
 */
class CommissionFlowController extends Controller
{
    use ResponseTrait;

    public function options(): JsonResponse
    {
        $employees = Employee::query()
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get(['user_id', 'name'])
            ->map(fn (Employee $e) => ['id' => (int) $e->user_id, 'name' => (string) $e->name])
            ->all();

        $periods = CommissionFlow::query()
            ->distinct()
            ->orderByDesc('period')
            ->limit(36)
            ->pluck('period')
            ->all();

        return $this->success(['employees' => $employees, 'periods' => $periods]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));

        $pager = $this->query($filters)
            ->orderByDesc('period')->orderBy('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'list' => collect($pager->items())->map(fn (CommissionFlow $f) => $this->transform($f))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'stat' => $this->stat($filters),
        ]);
    }

    /**
     * 与 perf 域对账：按 期间 + 业务员 返回 perf 提成单聚合与本地流水聚合，
     * 两者实发合计必须一致（口径同源，发放后 perf 单据不再变化）。
     */
    public function reconcile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $perf = CommissionOrder::query()
            ->where('period', $data['period'])
            ->whereIn('status', ['audited', 'paid'])
            ->when(! empty($data['employee_id']), fn ($q) => $q->where('employee_id', $data['employee_id']))
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(commission_amount),0) AS commission_amount, COALESCE(SUM(actual_amount),0) AS actual_amount')
            ->first();

        $local = CommissionFlow::query()
            ->where('period', $data['period'])
            ->when(! empty($data['employee_id']), fn ($q) => $q->where('employee_id', $data['employee_id']))
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(commission_amount),0) AS commission_amount, COALESCE(SUM(actual_amount),0) AS actual_amount')
            ->first();

        $perfActual = round((float) $perf->actual_amount, 2);
        $localActual = round((float) $local->actual_amount, 2);

        return $this->success([
            'perf' => [
                'count' => (int) $perf->cnt,
                'commission_amount' => round((float) $perf->commission_amount, 2),
                'actual_amount' => $perfActual,
            ],
            'local' => [
                'count' => (int) $local->cnt,
                'commission_amount' => round((float) $local->commission_amount, 2),
                'actual_amount' => $localActual,
            ],
            'matched' => abs($perfActual - $localActual) < 0.005,
            'note' => $perfActual === $localActual ? '实发合计与提成模块一致' : '存在未发放的提成单，流水尚未快照',
        ]);
    }

    public function export(Request $request)
    {
        $rows = [];
        foreach ($this->query($this->filters($request))->orderByDesc('period')->orderBy('id')->limit(50000)->get() as $f) {
            $rows[] = [
                $f->flow_no, $f->period, $f->employee_name, $f->department_name ?? '',
                $f->bill_no, $f->source_no ?? '',
                number_format((float) $f->sales_amount, 2, '.', ''),
                number_format((float) $f->base_amount, 2, '.', ''),
                number_format((float) $f->commission_amount, 2, '.', ''),
                number_format((float) $f->deduction, 2, '.', ''),
                number_format((float) $f->actual_amount, 2, '.', ''),
                $f->payroll_no ?? '', $f->paid_date ? (string) $f->paid_date : '',
            ];
        }

        $headers = ['流水号', '期间', '业务员', '部门', '提成单号', '源单据号', '销售额', '计提基数', '应发提成', '扣减', '实发提成', '工资单号', '发放日期'];
        $csv = ReportFilter::csv('提成流水', $headers, $rows);

        return ReportFilter::csvResponse($csv, 'commission_flows_'.date('Ymd').'.csv');
    }

    private function filters(Request $request): array
    {
        return array_filter([
            'period' => $request->input('period'),
            'period_start' => $request->input('period_start'),
            'period_end' => $request->input('period_end'),
            'employee_id' => $request->input('employee_id'),
            'source_no' => $request->input('source_no'),
            'keyword' => $request->input('keyword'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function query(array $filters): Builder
    {
        return CommissionFlow::query()
            ->when(! empty($filters['period']), fn ($q) => $q->where('period', $filters['period']))
            ->when(! empty($filters['period_start']), fn ($q) => $q->where('period', '>=', $filters['period_start']))
            ->when(! empty($filters['period_end']), fn ($q) => $q->where('period', '<=', $filters['period_end']))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', $filters['employee_id']))
            ->when(! empty($filters['source_no']), fn ($q) => $q->where('source_no', 'like', '%'.$filters['source_no'].'%'))
            ->when(! empty($filters['keyword']), function ($q) use ($filters) {
                $kw = $filters['keyword'];
                $q->where(function ($qq) use ($kw) {
                    $qq->where('flow_no', 'like', "%{$kw}%")
                        ->orWhere('bill_no', 'like', "%{$kw}%")
                        ->orWhere('employee_name', 'like', "%{$kw}%")
                        ->orWhere('source_no', 'like', "%{$kw}%");
                });
            });
    }

    private function stat(array $filters): array
    {
        $agg = $this->query($filters)->selectRaw(
            'COUNT(*) AS cnt,'
            .'COALESCE(SUM(sales_amount),0) AS sales_amount,'
            .'COALESCE(SUM(base_amount),0) AS base_amount,'
            .'COALESCE(SUM(commission_amount),0) AS commission_amount,'
            .'COALESCE(SUM(deduction),0) AS deduction,'
            .'COALESCE(SUM(actual_amount),0) AS actual_amount'
        )->first();

        return [
            'count' => (int) $agg->cnt,
            'sales_amount' => round((float) $agg->sales_amount, 2),
            'base_amount' => round((float) $agg->base_amount, 2),
            'commission_amount' => round((float) $agg->commission_amount, 2),
            'deduction' => round((float) $agg->deduction, 2),
            'actual_amount' => round((float) $agg->actual_amount, 2),
        ];
    }

    private function transform(CommissionFlow $f): array
    {
        return [
            'id' => $f->id,
            'flow_no' => $f->flow_no,
            'bill_no' => $f->bill_no,
            'period' => $f->period,
            'employee_id' => $f->employee_id,
            'employee_name' => $f->employee_name,
            'department_name' => $f->department_name,
            'source_no' => $f->source_no,
            'sales_amount' => (float) $f->sales_amount,
            'received_amount' => (float) $f->received_amount,
            'base_amount' => (float) $f->base_amount,
            'commission_amount' => (float) $f->commission_amount,
            'deduction' => (float) $f->deduction,
            'actual_amount' => (float) $f->actual_amount,
            'payroll_no' => $f->payroll_no,
            'paid_date' => $f->paid_date ? (string) $f->paid_date : null,
        ];
    }
}
