<?php

namespace Modules\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class CashFlowService
{
    /**
     * 记录资金流水
     *
     * @param array $params [
     *   'flow_type' => 'receive|pay|expense|red_flush',
     *   'amount' => float,
     *   'flow_date' => 'Y-m-d',
     *   'payment_method' => string,
     *   'related_id' => int,
     *   'related_type' => string,
     *   'customer_id' => int|null,
     *   'supplier_id' => int|null,
     *   'remark' => string|null,
     * ]
     */
    public function record(array $params): string
    {
        $flowType = $params['flow_type'];
        $amount = (float) ($params['amount'] ?? 0);

        // 统一正负号约定：收入正，支出负
        // 只要是 pay 或 expense，强制转为负数
        if (in_array($flowType, ['pay', 'expense']) && $amount > 0) {
            $amount = -$amount;
        } elseif (in_array($flowType, ['receive', 'red_flush']) && $amount < 0) {
            $amount = abs($amount);
        }

        $relatedId = $params['related_id'] ?? null;
        $relatedType = $params['related_type'] ?? null;

        // 幂等校验：同一业务单据 + 类型，不产生重复流水
        if ($relatedId && $relatedType) {
            $exists = DB::table('cash_flows')
                ->where('related_id', $relatedId)
                ->where('related_type', $relatedType)
                ->where('flow_type', $flowType)
                ->exists();
            if ($exists) {
                return 'EXISTING';
            }
        }

        // flow_no 唯一约束兜底：极小概率碰撞时重试（最多 5 次）
        $flowNo = null;
        for ($i = 0; $i < 5; $i++) {
            $flowNo = $this->generateFlowNo();
            if (! DB::table('cash_flows')->where('flow_no', $flowNo)->exists()) {
                break;
            }
        }

        DB::table('cash_flows')->insert([
            'flow_no' => $flowNo,
            'flow_type' => $flowType,
            'customer_id' => $params['customer_id'] ?? null,
            'supplier_id' => $params['supplier_id'] ?? null,
            'related_id' => $relatedId,
            'related_type' => $relatedType,
            'flow_date' => $params['flow_date'] ?? now()->toDateString(),
            'amount' => $amount,
            'payment_method' => $params['payment_method'] ?? '现金',
            'remark' => $params['remark'] ?? null,
            'created_by' => Auth::guard('admin')->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $flowNo;
    }

    public function recordReceive(array $params): string
    {
        return $this->record(array_merge(['flow_type' => 'receive'], $params));
    }

    public function recordPay(array $params): string
    {
        return $this->record(array_merge(['flow_type' => 'pay'], $params));
    }

    public function recordExpense(array $params): string
    {
        return $this->record(array_merge(['flow_type' => 'expense'], $params));
    }

    /**
     * 日记账查询：滚动余额计算
     *
     * @param array $filters [
     *   'start_date' => 'Y-m-d',
     *   'end_date' => 'Y-m-d',
     *   'flow_type' => string,
     *   'payment_method' => string,
     * ]
     */
    public function getJournal(array $filters = []): array
    {
        $query = DB::table('cash_flows');

        if (!empty($filters['flow_type'])) {
            $query->where('flow_type', $filters['flow_type']);
        }
        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        // 计算期初余额（start_date 之前的所有流水总和）
        $openingBalance = 0.0;
        if (!empty($filters['start_date'])) {
            $openingBalance = (float) DB::table('cash_flows')
                ->where('flow_date', '<', $filters['start_date'])
                ->when(!empty($filters['flow_type']), fn($q) => $q->where('flow_type', $filters['flow_type']))
                ->when(!empty($filters['payment_method']), fn($q) => $q->where('payment_method', $filters['payment_method']))
                ->sum('amount');
        }

        // 获取当前区间数据
        if (!empty($filters['start_date'])) {
            $query->where('flow_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('flow_date', '<=', $filters['end_date']);
        }

        $rows = $query->orderBy('flow_date')->orderBy('id')->get();

        $balance = $openingBalance;
        $result = [];
        foreach ($rows as $row) {
            $balance += (float)$row->amount;
            $result[] = [
                'flow_no' => $row->flow_no,
                'flow_date' => $row->flow_date,
                'type' => $row->flow_type,
                'amount' => (float)$row->amount,
                'balance' => round($balance, 2),
                'payment_method' => $row->payment_method,
                'remark' => $row->remark,
            ];
        }

        return [
            'opening_balance' => round($openingBalance, 2),
            'rows' => $result,
            'closing_balance' => round($balance, 2),
        ];
    }

    private function generateFlowNo(): string
    {
        return 'CF' . date('YmdHis') . strtoupper(Str::random(4));
    }
}
