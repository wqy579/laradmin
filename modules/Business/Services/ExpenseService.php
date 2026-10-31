<?php

namespace Modules\Business\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Business\Models\Expense;

class ExpenseService
{
    /** 导出行数上限，防止全表拉爆内存 */
    public const EXPORT_LIMIT = 50000;

    public function create(array $data): Expense
    {
        return DB::transaction(function () use ($data) {
            $expense = Expense::create([
                'expense_no' => $this->generateNo(),
                'expense_type' => $data['expense_type'],
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'] ?? now(),
                'handler_id' => $data['handler_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'remark' => $data['remark'] ?? null,
                'status' => 0,
            ]);

            return $expense;
        });
    }

    public function find($id): ?Expense
    {
        return Expense::with(['handler', 'department'])->find($id);
    }

    public function update(Expense $expense, array $data): Expense
    {
        if ($expense->status == 1) {
            throw new \Exception('已审核单据不能修改');
        }

        return DB::transaction(function () use ($expense, $data) {
            $expense->update([
                'expense_type' => $data['expense_type'] ?? $expense->expense_type,
                'amount' => $data['amount'] ?? $expense->amount,
                'expense_date' => $data['expense_date'] ?? $expense->expense_date,
                'remark' => $data['remark'] ?? $expense->remark,
            ]);

            return $expense;
        });
    }

    public function approve(Expense $expense): Expense
    {
        if ($expense->status == 1) {
            throw new \Exception('单据已审核');
        }

        return DB::transaction(function () use ($expense) {
            $expense->status = 1;
            $expense->save();

            return $expense;
        });
    }

    public function destroy(Expense $expense): bool
    {
        if ($expense->status == 1) {
            throw new \Exception('已审核单据不能删除');
        }

        return $expense->delete();
    }

    public function list(array $filters = [], int $page = 1, int $pageSize = 20): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /** 导出行：与 list 同一套筛选条件，仅去掉分页换成上限保护 */
    public function exportRows(array $filters = []): Collection
    {
        return $this->filtered($filters)->orderByDesc('id')->limit(self::EXPORT_LIMIT)->get();
    }

    /**
     * 列表/导出共用筛选。
     *
     * 日期上界必须是**开区间**：expense_date 是 date cast，经 Eloquent 写入 sqlite 存成
     * 'YYYY-MM-DD 00:00:00'，`<= 'YYYY-MM-DD'` 会静默丢掉 end_date 当天那一笔。
     */
    private function filtered(array $filters): Builder
    {
        $query = Expense::with(['handler', 'department']);

        if (! empty($filters['expense_type'])) {
            $query->where('expense_type', $filters['expense_type']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('expense_date', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where('expense_date', '<', CarbonImmutable::parse($filters['end_date'])->startOfDay()->addDay());
        }

        return $query;
    }

    public function statistics(array $filters = []): array
    {
        $query = Expense::where('status', 1);

        if (! empty($filters['start_date'])) {
            $query->where('expense_date', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where('expense_date', '<', CarbonImmutable::parse($filters['end_date'])->startOfDay()->addDay());
        }
        if (! empty($filters['expense_type'])) {
            $query->where('expense_type', $filters['expense_type']);
        }

        return [
            'total_amount' => $query->sum('amount') ?? 0,
            'count' => $query->count(),
        ];
    }

    private function generateNo(): string
    {
        $date = date('Ymd');
        $prefix = 'FY'.$date;
        $last = Expense::where('expense_no', 'like', $prefix.'%')
            ->orderByDesc('expense_no')
            ->value('expense_no');

        if ($last) {
            $seq = intval(substr($last, -6)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
