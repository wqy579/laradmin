<?php

namespace Modules\Hr\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Modules\Hr\Models\Payroll;
use Modules\Hr\Services\PayrollService;

/**
 * 工资项导入（QA2-C01）。
 *
 * 用途是给「生成工资单」之后补录手工项：提成列不在模板里，它是 perf 提成单的
 * 只读快照，导进来就等于伪造提成。所以只覆盖 7 个手工项并即时重算净额。
 *
 * 列序固定（与 PayrollService::importTemplateHead() 一致）：
 *   工号 | 姓名 | 基本工资 | 工龄工资 | 绩效工资 | 加班工资 | 调整工资 | 报销金额 | 扣项 | 备注
 *
 * 为什么没用 WithHeadingRow：本项目 config/excel.php 里 imports.heading_row.formatter = 'slug'，
 * 纯中文表头会被 Str::slug 清空成同一个空键（详见 OpeningStockImport 的同段说明），
 * 按列序取整型下标才拿得到值。表头行按首格内容识别后跳过。
 */
class PayrollImport implements ToCollection
{
    /** 表头首格，用于跳过表头行 */
    protected const HEADING = '工号';

    protected int $successCount = 0;

    protected int $errorCount = 0;

    protected array $errors = [];

    protected array $unmatched = [];

    public function __construct(protected string $period) {}

    public function collection(Collection $rows): void
    {
        $rows = $rows->values();

        // 第一遍只收工号，批量查一次工资单，避免逐行 whereFirst
        $pending = [];
        $employeeIds = [];
        foreach ($rows as $index => $row) {
            $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_values($row->toArray()));
            $no = $index + 1; // Excel 里用户看到的物理行号
            if ($this->isHeadingOrBlank($cells)) {
                continue;
            }
            $pending[] = ['no' => $no, 'cells' => $cells];
            $employeeIds[] = (string) ($cells[0] ?? '');
        }

        $payrolls = Payroll::query()
            ->where('period', $this->period)
            ->whereIn('employee_id', array_unique($employeeIds))
            ->get()
            ->keyBy('employee_id');

        // 已发放单据不许导入覆盖，单独查一次给出明确原因
        $lockedIds = DB::table('hr_payrolls')
            ->where('period', $this->period)
            ->where('status', Payroll::STATUS_PAID)
            ->pluck('employee_id')
            ->all();

        foreach ($pending as $item) {
            [$no, $cells] = [$item['no'], $item['cells']];
            $employeeId = (string) ($cells[0] ?? '');

            if ($employeeId === '') {
                $this->addError($no, '工号不能为空');

                continue;
            }

            if (in_array((int) $employeeId, $lockedIds, true)) {
                $this->addError($no, "工号 {$employeeId} 本期工资已发放，不能导入覆盖");

                continue;
            }

            $payroll = $payrolls[(int) $employeeId] ?? null;
            if (! $payroll) {
                $this->unmatched[] = "工号 {$employeeId}";
                $this->addError($no, "工号 {$employeeId} 在 {$this->period} 没有工资单，请先「生成工资单」");

                continue;
            }

            $values = [];
            foreach (array_keys(Payroll::ITEM_LABELS) as $i => $field) {
                $value = $this->number($cells[2 + $i] ?? null);
                if ($value === null) {
                    $this->addError($no, Payroll::ITEM_LABELS[$field].'必须是数字');

                    continue 2;
                }
                if ($value < 0 && $field !== 'adjustment_amount') {
                    $this->addError($no, Payroll::ITEM_LABELS[$field].'不能为负数');

                    continue 2;
                }
                $values[$field] = $value;
            }

            $payroll->fill($values);
            $payroll->net_amount = PayrollService::calcNet(
                $values['base_amount'],
                (float) $payroll->commission_amount,
                $values['performance_amount'],
                $values['deduction_amount'],
                $values['seniority_amount'],
                $values['overtime_amount'],
                $values['adjustment_amount'],
                $values['reimbursement_amount']
            );
            $remark = $cells[2 + count($values)] ?? null;
            if ($remark !== null && (string) $remark !== '') {
                $payroll->remark = (string) $remark;
            }
            $payroll->save();

            $this->successCount++;
        }
    }

    protected function isHeadingOrBlank(array $cells): bool
    {
        $first = $cells[0] ?? null;
        if ($first !== null && (string) $first !== '' && str_contains((string) $first, self::HEADING)) {
            return true;
        }

        return collect($cells)->every(fn ($v) => $v === null || $v === '');
    }

    /** 单元格转数字：允许千分位逗号与空格；空值按 0 处理（没填的项目就是 0） */
    protected function number($value): ?float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        $clean = str_replace([',', ' ', '，'], '', (string) $value);

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    protected function addError(int $row, string $message): void
    {
        $this->errorCount++;
        $this->errors[] = "第 {$row} 行: {$message}";
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** 没匹配到工资单的工号，前端单独列一块方便回去补生成 */
    public function getUnmatched(): array
    {
        return array_values(array_unique($this->unmatched));
    }
}
