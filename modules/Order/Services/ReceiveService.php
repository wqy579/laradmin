<?php

namespace Modules\Order\Services;

use Modules\Order\Models\Receive;
use Illuminate\Support\Facades\DB;

class ReceiveService
{
    public function create(array $data): Receive
    {
        return DB::transaction(function () use ($data) {
            $receive = Receive::create([
                'receive_no' => $this->generateNo(),
                'receive_type' => $data['receive_type'] ?? 1,
                'customer_id' => $data['customer_id'] ?? null,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'amount' => $data['amount'],
                'receive_date' => $data['receive_date'] ?? now(),
                'payment_method' => $data['payment_method'] ?? '现金',
                'handler_id' => $data['handler_id'] ?? null,
                'remark' => $data['remark'] ?? null,
                'status' => 0,
            ]);
            return $receive;
        });
    }

    public function find($id): ?Receive
    {
        return Receive::with(['customer', 'salesOrder', 'handler'])->find($id);
    }

    public function update(Receive $receive, array $data): Receive
    {
        if ($receive->status == 1) {
            throw new \Exception('已审核单据不能修改');
        }
        return DB::transaction(function () use ($receive, $data) {
            $receive->update([
                'amount' => $data['amount'],
                'receive_date' => $data['receive_date'] ?? $receive->receive_date,
                'payment_method' => $data['payment_method'] ?? $receive->payment_method,
                'remark' => $data['remark'] ?? $receive->remark,
            ]);
            return $receive;
        });
    }

    public function approve(Receive $receive): Receive
    {
        if ($receive->status == 1) {
            throw new \Exception('单据已审核');
        }
        return DB::transaction(function () use ($receive) {
            $receive->status = 1;
            $receive->save();
            return $receive;
        });
    }

    public function destroy(Receive $receive): bool
    {
        if ($receive->status == 1) {
            throw new \Exception('已审核单据不能删除');
        }
        return $receive->delete();
    }

    public function list(array $filters = [], int $page = 1, int $pageSize = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Receive::with(['customer', 'salesOrder', 'handler']);

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('receive_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('receive_date', '<=', $filters['end_date']);
        }

        return $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function statistics(array $filters = []): array
    {
        $query = Receive::where('status', 1);

        if (!empty($filters['start_date'])) {
            $query->where('receive_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('receive_date', '<=', $filters['end_date']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        return [
            'total_amount' => $query->sum('amount') ?? 0,
            'count' => $query->count(),
        ];
    }

    private function generateNo(): string
    {
        $date = date('Ymd');
        $prefix = 'SK' . $date;
        $last = Receive::where('receive_no', 'like', $prefix . '%')
            ->orderByDesc('receive_no')
            ->value('receive_no');

        if ($last) {
            $seq = intval(substr($last, -6)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
