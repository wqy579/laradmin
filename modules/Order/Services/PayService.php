<?php

namespace Modules\Order\Services;

use Modules\Order\Models\Pay;
use Illuminate\Support\Facades\DB;

class PayService
{
    public function create(array $data): Pay
    {
        return DB::transaction(function () use ($data) {
            $pay = Pay::create([
                'pay_no' => $this->generateNo(),
                'pay_type' => $data['pay_type'] ?? 1,
                'supplier_id' => $data['supplier_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'amount' => $data['amount'],
                'pay_date' => $data['pay_date'] ?? now(),
                'payment_method' => $data['payment_method'] ?? '现金',
                'handler_id' => $data['handler_id'] ?? null,
                'remark' => $data['remark'] ?? null,
                'status' => 0,
            ]);
            return $pay;
        });
    }

    public function find($id): ?Pay
    {
        return Pay::with(['supplier', 'purchaseOrder', 'handler'])->find($id);
    }

    public function update(Pay $pay, array $data): Pay
    {
        if ($pay->status == 1) {
            throw new \Exception('已审核单据不能修改');
        }
        return DB::transaction(function () use ($pay, $data) {
            $pay->update([
                'amount' => $data['amount'],
                'pay_date' => $data['pay_date'] ?? $pay->pay_date,
                'payment_method' => $data['payment_method'] ?? $pay->payment_method,
                'remark' => $data['remark'] ?? $pay->remark,
            ]);
            return $pay;
        });
    }

    public function approve(Pay $pay): Pay
    {
        if ($pay->status == 1) {
            throw new \Exception('单据已审核');
        }
        return DB::transaction(function () use ($pay) {
            $pay->status = 1;
            $pay->save();
            return $pay;
        });
    }

    public function destroy(Pay $pay): bool
    {
        if ($pay->status == 1) {
            throw new \Exception('已审核单据不能删除');
        }
        return $pay->delete();
    }

    public function list(array $filters = [], int $page = 1, int $pageSize = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Pay::with(['supplier', 'purchaseOrder', 'handler']);

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('pay_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('pay_date', '<=', $filters['end_date']);
        }

        return $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function statistics(array $filters = []): array
    {
        $query = Pay::where('status', 1);

        if (!empty($filters['start_date'])) {
            $query->where('pay_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('pay_date', '<=', $filters['end_date']);
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        return [
            'total_amount' => $query->sum('amount') ?? 0,
            'count' => $query->count(),
        ];
    }

    private function generateNo(): string
    {
        $date = date('Ymd');
        $prefix = 'FK' . $date;
        $last = Pay::where('pay_no', 'like', $prefix . '%')
            ->orderByDesc('pay_no')
            ->value('pay_no');

        if ($last) {
            $seq = intval(substr($last, -6)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
