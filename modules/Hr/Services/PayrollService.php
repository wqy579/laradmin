<?php

namespace Modules\Hr\Services;

use App\Exports\GenericExport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Business\Models\Employee;
use Modules\Hr\Imports\PayrollImport;
use Modules\Hr\Models\CommissionFlow;
use Modules\Hr\Models\Payroll;
use Modules\Order\Models\Pay;
use Modules\Order\Services\PayService;
use Modules\Performance\Models\CommissionOrder;

/**
 * 工资核算与发放（F4）。
 *
 * 口径：应发净额 net = 基本工资 + 提成 + 绩效 − 扣项（calcNet 单一出口）。
 * 提成取 perf 域提成单（status=audited/paid）的实发 actual_amount 求和，
 * 只读引用，不回写 perf 表；发放动作把当期提成单逐单快照成 hr 提成流水。
 *
 * 发放 = 调 Order 域 PayService 生成付款单（pay_type=2 其他付款）
 * + 记 cash_flows 支出流水（related_type=HrPayroll，金额为负），
 * 之后状态锁定：paid 单据改/删一律拒绝，重复发放幂等返回。
 */
class PayrollService
{
    /** 可入工资 / 可快照的提成单状态（pending 未审核、rejected 已驳回均不入） */
    private const COMMISSION_ENTRY_STATUSES = ['audited', 'paid'];

    public function __construct(private PayService $payService) {}

    /**
     * 应发净额（唯一的公式出口，前端展开明细与列表合计共用）。
     *
     * 后 4 项为 QA2-C01 新增工资项目，给默认值以保持既有四参调用方（含测试）不变。
     */
    public static function calcNet(
        float $base,
        float $commission,
        float $performance,
        float $deduction,
        float $seniority = 0.0,
        float $overtime = 0.0,
        float $adjustment = 0.0,
        float $reimbursement = 0.0
    ): float {
        return round($base + $seniority + $commission + $performance + $overtime + $adjustment + $reimbursement - $deduction, 2);
    }

    /**
     * 按期间从 perf 提成单生成草稿工资单（每人一张，已存在的跳过）。
     * 基本工资取 employees.base_salary（经 user_id 关联，同 perf employee_id 口径），缺省 0。
     *
     * @return array{created: int, skipped: int, items: array<int, array>}
     */
    public function generate(string $period, array $employeeIds = []): array
    {
        $commissionRows = $this->periodCommissions($period, $employeeIds);

        $employeeIds = $commissionRows->pluck('employee_id')->unique()->all();
        if ($employeeIds === []) {
            return ['created' => 0, 'skipped' => 0, 'items' => []];
        }

        $baseSalaries = Employee::query()
            ->whereIn('user_id', $employeeIds)
            ->pluck('base_salary', 'user_id');

        $created = 0;
        $skipped = 0;
        $items = [];

        DB::transaction(function () use ($period, $commissionRows, $baseSalaries, &$created, &$skipped, &$items) {
            foreach ($commissionRows->groupBy('employee_id') as $employeeId => $rows) {
                $first = $rows->first();
                if (Payroll::where('period', $period)->where('employee_id', $employeeId)->exists()) {
                    $skipped++;

                    continue;
                }

                $base = round((float) ($baseSalaries[$employeeId] ?? 0), 2);
                $commission = round((float) $rows->sum('actual_amount'), 2);
                $net = self::calcNet($base, $commission, 0.0, 0.0);

                $payroll = Payroll::create([
                    'payroll_no' => $this->generateNo(),
                    'period' => $period,
                    'employee_id' => (int) $employeeId,
                    'employee_name' => (string) $first->employee_name,
                    'department_id' => $first->department_id,
                    'department_name' => $first->department_name,
                    'base_amount' => $base,
                    'commission_amount' => $commission,
                    'performance_amount' => 0,
                    'deduction_amount' => 0,
                    'net_amount' => $net,
                    'status' => Payroll::STATUS_DRAFT,
                ]);

                $created++;
                $items[] = $payroll->only([
                    'id', 'payroll_no', 'employee_id', 'employee_name',
                    'base_amount', 'commission_amount', 'net_amount',
                ]);
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'items' => $items];
    }

    /** 期间内可入工资的提成单：audited/paid（pending 未审核不得入工资） */
    private function periodCommissions(string $period, array $employeeIds = [])
    {
        return CommissionOrder::query()
            ->where('period', $period)
            ->whereIn('status', self::COMMISSION_ENTRY_STATUSES)
            ->when($employeeIds !== [], fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->orderBy('employee_id')
            ->get(['employee_id', 'employee_name', 'department_id', 'department_name', 'actual_amount']);
    }

    /** 编辑草稿工资单：除提成为 perf 快照不可手改外，各工资项目均可调 */
    public function updateDraft(Payroll $payroll, array $data): Payroll
    {
        if ($payroll->isPaid()) {
            throw new \DomainException('已发放工资单不能修改');
        }

        // 负数在控制器按边界校验拦下（调整工资除外），这里只做取整与重算
        $items = [];
        foreach (array_keys(Payroll::ITEM_LABELS) as $field) {
            $items[$field] = round((float) ($data[$field] ?? $payroll->$field), 2);
        }

        $payroll->fill($items);
        $payroll->net_amount = self::calcNet(
            $items['base_amount'],
            (float) $payroll->commission_amount,
            $items['performance_amount'],
            $items['deduction_amount'],
            $items['seniority_amount'],
            $items['overtime_amount'],
            $items['adjustment_amount'],
            $items['reimbursement_amount']
        );
        $payroll->remark = $data['remark'] ?? $payroll->remark;
        $payroll->save();

        return $payroll;
    }

    public function destroyDraft(Payroll $payroll): bool
    {
        if ($payroll->isPaid()) {
            throw new \DomainException('已发放工资单不能删除');
        }

        return (bool) $payroll->delete();
    }

    /**
     * 发放：付款单 + 资金流水 + 提成流水快照 + 状态锁定。
     * 幂等：已发放直接返回当前单据，不重复生成付款单。
     */
    public function pay(Payroll $payroll, array $data, int $operatorId): Payroll
    {
        if ($payroll->isPaid()) {
            return $payroll;
        }

        return DB::transaction(function () use ($payroll, $data, $operatorId) {
            $payroll = Payroll::query()->lockForUpdate()->findOrFail($payroll->id);
            if ($payroll->isPaid()) {
                return $payroll;
            }

            $method = $data['payment_method'] ?? '银行转账';
            $payDate = $data['paid_date'] ?? now()->toDateString();

            $pay = $this->payService->create([
                'pay_type' => 2,
                'amount' => (float) $payroll->net_amount,
                'pay_date' => $payDate,
                'payment_method' => $method,
                'handler_id' => $operatorId,
                'remark' => '工资发放 '.$payroll->payroll_no.' '.$payroll->employee_name.' '.$payroll->period,
            ]);

            $flowNo = 'CF'.date('YmdHis').strtoupper(Str::random(4));
            DB::table('cash_flows')->insert([
                'flow_no' => $flowNo,
                'flow_type' => 'pay',
                'related_id' => $payroll->id,
                'related_type' => 'HrPayroll',
                'flow_date' => $payDate,
                'amount' => -1 * (float) $payroll->net_amount,
                'payment_method' => $method,
                'remark' => '工资发放 '.$payroll->payroll_no,
                'created_by' => $operatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->snapshotCommissionFlows($payroll, $payDate);

            $payroll->fill([
                'status' => Payroll::STATUS_PAID,
                'pay_id' => $pay->id,
                'pay_no' => $pay->pay_no,
                'cash_flow_no' => $flowNo,
                'paid_date' => $payDate,
                'payment_method' => $method,
                'paid_at' => now(),
                'paid_by' => $operatorId,
            ]);
            $payroll->save();

            return $payroll;
        });
    }

    /** 当期该业务员的 perf 提成单逐单快照为提成流水（unique on commission_order_id 幂等） */
    private function snapshotCommissionFlows(Payroll $payroll, string $payDate): void
    {
        $orders = CommissionOrder::query()
            ->where('period', $payroll->period)
            ->where('employee_id', $payroll->employee_id)
            ->whereIn('status', self::COMMISSION_ENTRY_STATUSES)
            ->with('items:id,order_id,source_no')
            ->get();

        foreach ($orders as $order) {
            $sourceNo = $order->items->pluck('source_no')->filter()->first();

            CommissionFlow::updateOrCreate(
                ['commission_order_id' => $order->id],
                [
                    'flow_no' => CommissionFlow::where('commission_order_id', $order->id)->value('flow_no')
                        ?? 'TF'.date('Ymd').str_pad((string) (CommissionFlow::count() + 1), 6, '0', STR_PAD_LEFT),
                    'bill_no' => $order->bill_no,
                    'period' => $order->period,
                    'employee_id' => $order->employee_id,
                    'employee_name' => $order->employee_name,
                    'department_id' => $order->department_id,
                    'department_name' => $order->department_name,
                    'source_no' => $sourceNo,
                    'sales_amount' => $order->sales_amount,
                    'received_amount' => $order->received_amount,
                    'base_amount' => $order->base_amount,
                    'commission_amount' => $order->commission_amount,
                    'deduction' => $order->deduction,
                    'actual_amount' => $order->actual_amount,
                    'payroll_id' => $payroll->id,
                    'payroll_no' => $payroll->payroll_no,
                    'paid_date' => $payDate,
                ]
            );
        }
    }

    public function listQuery(array $filters): Builder
    {
        return Payroll::query()
            ->when(! empty($filters['period']), fn ($q) => $q->where('period', $filters['period']))
            ->when(! empty($filters['period_start']), fn ($q) => $q->where('period', '>=', $filters['period_start']))
            ->when(! empty($filters['period_end']), fn ($q) => $q->where('period', '<=', $filters['period_end']))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', $filters['employee_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['keyword']), function ($q) use ($filters) {
                $kw = $filters['keyword'];
                $q->where(function ($qq) use ($kw) {
                    $qq->where('payroll_no', 'like', "%{$kw}%")
                        ->orWhere('employee_name', 'like', "%{$kw}%");
                });
            });
    }

    public function paginate(array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        return $this->listQuery($filters)
            ->orderByDesc('period')->orderBy('employee_id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /** 列表页合计行：与筛选口径一致（空库出 0，不出 null/NaN） */
    public function stat(array $filters): array
    {
        return array_merge(
            ['count' => $this->listQuery($filters)->count()],
            $this->aggregate($this->listQuery($filters))
        );
    }

    /** 一组工资项的求和（列表合计与工资报表共用，避免两处口径漂移） */
    private function aggregate(Builder $query): array
    {
        $columns = Payroll::AMOUNT_FIELDS;
        $select = implode(',', array_map(
            fn ($c) => "COALESCE(SUM({$c}),0) AS {$c}",
            $columns
        ));
        $agg = $query->selectRaw($select)->first();

        $out = [];
        foreach ($columns as $c) {
            $out[$c] = round((float) $agg->$c, 2);
        }

        return $out;
    }

    /**
     * 工资报表：按 部门 × 期间 汇总（工资报表 Tab 的数据源）。
     *
     * period_start/period_end 给区间，不给就全量；分组维度固定为部门+期间，
     * 前端按需要再透视成「部门为行、月份为列」或「月份为行」。
     */
    public function report(array $filters): array
    {
        $columns = Payroll::AMOUNT_FIELDS;
        $select = 'department_name, period, COUNT(*) AS cnt,'
            .implode(',', array_map(fn ($c) => "COALESCE(SUM({$c}),0) AS {$c}", $columns));

        $rows = $this->listQuery($filters)
            ->selectRaw($select)
            ->groupBy('department_name', 'period')
            ->orderBy('period')
            ->orderBy('department_name')
            ->get();

        return [
            'list' => $rows->map(function ($r) use ($columns) {
                return [
                    'department_name' => $r->department_name ?: '未分配部门',
                    'period' => $r->period,
                    'count' => (int) $r->cnt,
                    ...array_combine($columns, array_map(fn ($c) => round((float) $r->$c, 2), $columns)),
                ];
            })->values()->all(),
            'total' => $this->stat($filters),
        ];
    }

    /** 工资条明细：构成明细 + 当期提成单列表 + 付款信息 */
    public function detail(Payroll $payroll): array
    {
        $payroll->load(['commissionFlows' => fn ($q) => $q->orderBy('period')->orderBy('id')]);

        $commissionOrders = CommissionOrder::query()
            ->where('period', $payroll->period)
            ->where('employee_id', $payroll->employee_id)
            ->orderBy('bill_no')
            ->get(['id', 'bill_no', 'period', 'status', 'commission_amount', 'deduction', 'actual_amount']);

        return [
            'payroll' => $payroll,
            'formula' => [
                'base_amount' => (float) $payroll->base_amount,
                'seniority_amount' => (float) $payroll->seniority_amount,
                'commission_amount' => (float) $payroll->commission_amount,
                'performance_amount' => (float) $payroll->performance_amount,
                'overtime_amount' => (float) $payroll->overtime_amount,
                'adjustment_amount' => (float) $payroll->adjustment_amount,
                'reimbursement_amount' => (float) $payroll->reimbursement_amount,
                'deduction_amount' => (float) $payroll->deduction_amount,
                'net_amount' => (float) $payroll->net_amount,
            ],
            'commission_orders' => $commissionOrders,
            'pay' => $payroll->pay_id
                ? Pay::query()->find($payroll->pay_id, ['id', 'pay_no', 'amount', 'pay_date', 'payment_method', 'status'])
                : null,
        ];
    }

    /**
     * 导入工资项（Excel）。返回 success_count / error_count / errors / unmatched。
     *
     * 部分成功：坏行只记原因、不牵连好行（与开账导入同一套语义）。
     * 只覆盖手工录入项，提成列不在模板里——它是 perf 的只读快照。
     */
    public function import(string $period, UploadedFile $file): array
    {
        $import = new PayrollImport($period);
        Excel::import($import, $file->getRealPath());

        return [
            'period' => $period,
            'success_count' => $import->getSuccessCount(),
            'error_count' => $import->getErrorCount(),
            'errors' => $import->getErrors(),
            'unmatched' => $import->getUnmatched(),
        ];
    }

    /** 导入模板表头（列序即 PayrollImport 的取值下标，改一处必须改另一处） */
    public static function importTemplateHead(): array
    {
        return array_merge(
            ['工号', '姓名'],
            array_values(Payroll::ITEM_LABELS),
            ['备注']
        );
    }

    /** 生成导入模板文件，返回文件名（放在 public 磁盘 exports/ 下） */
    public function downloadTemplate(): string
    {
        $head = self::importTemplateHead();
        $filename = 'payroll_import_template_'.date('YmdHis').'.xlsx';
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        Excel::store(new GenericExport([$head]), 'exports/'.$filename, 'public');

        return $filename;
    }

    public function templatePath(string $filename): string
    {
        return storage_path('app/public/exports/'.$filename);
    }

    private function generateNo(): string
    {
        $prefix = 'GZ'.date('Ymd');
        $last = Payroll::where('payroll_no', 'like', $prefix.'%')->orderByDesc('payroll_no')->value('payroll_no');
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
