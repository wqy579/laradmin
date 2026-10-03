<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Customer;
use Modules\Order\Models\Receive;
use Modules\Order\Models\SalesOrder;

class StatementService
{
    public function getStatement(int $customerId, string $startDate, string $endDate): array
    {
        $customer = Customer::find($customerId);
        if (! $customer) {
            return [
                'customer_name' => '',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'opening_balance' => 0,
                'total_receivable' => 0,
                'total_received' => 0,
                'closing_balance' => 0,
                'items' => [],
            ];
        }

        // 1. 计算期初余额（startDate之前：所有有效订单总额 - 所有已收款）
        $openingBalance = $this->calculateOpeningBalance($customerId, $startDate);

        // 2. 查询本期销售订单（产生应收）
        $salesOrders = $this->getSalesOrders($customerId, $startDate, $endDate);

        // 3. 查询本期收款单（减少应收，只统计已审核）
        $receives = $this->getReceives($customerId, $startDate, $endDate);

        // 4. 查询本期红冲单（负金额）
        $redFlushes = $this->getRedFlushes($customerId, $startDate, $endDate);

        // 5. 合并并按日期排序
        $items = $this->mergeItems($salesOrders, $receives, $redFlushes);

        // 6. 过滤0金额记录
        $items = array_filter($items, function ($item) {
            $r = $item['receivable'] ?? 0;
            $rec = $item['received'] ?? 0;

            return abs($r) > 0.001 || abs($rec) > 0.001;
        });
        $items = array_values($items);

        // 7. 计算汇总
        $totalReceivable = collect($items)->sum(function ($item) {
            return $item['receivable'] ?? 0;
        });
        $totalReceived = collect($items)->sum(function ($item) {
            return $item['received'] ?? 0;
        });

        // 8. 计算期末余额
        $closingBalance = $openingBalance + $totalReceivable - $totalReceived;

        // 9. 逐行计算余额
        $items = $this->calculateRunningBalance($items, $openingBalance);

        return [
            'customer_name' => $customer->name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'opening_balance' => round($openingBalance, 2),
            'total_receivable' => round($totalReceivable, 2),
            'total_received' => round($totalReceived, 2),
            'closing_balance' => round($closingBalance, 2),
            'items' => $items,
        ];
    }

    private function calculateOpeningBalance(int $customerId, string $startDate): float
    {
        $allOrders = SalesOrder::where('customer_id', $customerId)
            ->whereNotIn('status', ['cancelled', '已红冲'])
            ->select('total_amount', 'paid_amount')
            ->where('order_date', '<', $startDate)
            ->get();

        $total = $allOrders->sum('total_amount');
        $paid = $allOrders->sum('paid_amount');

        // 减去startDate之前的已收款
        $oldReceives = Receive::where('customer_id', $customerId)
            ->where('status', 1)
            ->where('receive_date', '<', $startDate)
            ->sum('amount');

        return (float) $total - (float) $paid - (float) $oldReceives;
    }

    private function getSalesOrders(int $customerId, string $startDate, string $endDate): array
    {
        $orders = SalesOrder::where('customer_id', $customerId)
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->select('id', 'order_no', 'order_date', 'total_amount', 'status', 'created_by', 'remark')
            ->orderBy('order_date')
            ->get();

        // 批量获取创建人姓名
        $creatorIds = $orders->pluck('created_by')->filter()->unique()->toArray();
        $creators = [];
        if (! empty($creatorIds)) {
            $users = DB::table('auth_user')->whereIn('id', $creatorIds)
                ->select('id', 'real_name', 'username')->get();
            foreach ($users as $u) {
                $creators[$u->id] = $u->real_name ?: $u->username;
            }
        }

        return $orders->map(function ($order) use ($creators) {
            return [
                'date' => $order->order_date?->format('Y-m-d'),
                'doc_no' => $order->order_no,
                'doc_type' => '销售订单',
                'summary' => $this->getOrderSummary($order),
                'receivable' => (float) $order->total_amount,
                'received' => null,
                'operator' => $creators[$order->created_by] ?? '-',
                'related_id' => $order->id,
                'related_type' => 'SalesOrder',
            ];
        })->toArray();
    }

    private function getOrderSummary($order): string
    {
        // 尝试从remark获取摘要，否则显示商品数量
        if (! empty($order->remark)) {
            return $order->remark;
        }
        // 查询订单商品数量
        $itemCount = DB::table('sales_order_items')
            ->where('sales_order_id', $order->id)
            ->count();

        return $itemCount > 0 ? "商品销售({$itemCount}种)" : '商品销售';
    }

    private function getReceives(int $customerId, string $startDate, string $endDate): array
    {
        $receives = Receive::where('customer_id', $customerId)
            ->whereBetween('receive_date', [$startDate, $endDate])
            ->where('status', 1)
            ->select('id', 'receive_no', 'receive_date', 'amount', 'remark', 'handler_id')
            ->orderBy('receive_date')
            ->get();

        // 批量获取经办人姓名
        $handlerIds = $receives->pluck('handler_id')->filter()->unique()->toArray();
        $handlers = [];
        if (! empty($handlerIds)) {
            $employees = DB::table('employees')->whereIn('id', $handlerIds)
                ->select('id', 'name')->get();
            foreach ($employees as $e) {
                $handlers[$e->id] = $e->name;
            }
        }

        return $receives->map(function ($receive) use ($handlers) {
            $remark = $receive->remark ?? '';
            $summary = '';
            if (! empty($remark)) {
                // 提取客户名和订单号
                if (preg_match('/^收款：(.+)$/', $remark, $m)) {
                    $summary = trim($m[1]);
                } else {
                    $summary = trim($remark);
                }
            }

            return [
                'date' => $receive->receive_date?->format('Y-m-d'),
                'doc_no' => $receive->receive_no,
                'doc_type' => '收款单',
                'summary' => $summary,
                'receivable' => null,
                'received' => (float) $receive->amount,
                'operator' => $handlers[$receive->handler_id] ?? '-',
                'related_id' => $receive->id,
                'related_type' => 'Receive',
            ];
        })->toArray();
    }

    private function getRedFlushes(int $customerId, string $startDate, string $endDate): array
    {
        $orders = SalesOrder::where('customer_id', $customerId)
            ->whereBetween('order_date', [$startDate, $endDate])
            ->where('status', '已红冲')
            ->select('id', 'order_no', 'order_date', 'total_amount', 'red_flush_reason', 'red_flush_by')
            ->orderBy('order_date')
            ->get();

        // 批量获取红冲人姓名
        $flushByIds = $orders->pluck('red_flush_by')->filter()->unique()->toArray();
        $flushers = [];
        if (! empty($flushByIds)) {
            $users = DB::table('auth_user')->whereIn('id', $flushByIds)
                ->select('id', 'real_name', 'username')->get();
            foreach ($users as $u) {
                $flushers[$u->id] = $u->real_name ?: $u->username;
            }
        }

        return $orders->map(function ($order) use ($flushers) {
            return [
                'date' => $order->order_date?->format('Y-m-d'),
                'doc_no' => $order->order_no,
                'doc_type' => '红冲单',
                'summary' => $order->red_flush_reason ?? '订单红冲',
                'receivable' => (float) $order->total_amount, // 已经是负数
                'received' => null,
                'operator' => $flushers[$order->red_flush_by] ?? '-',
                'related_id' => $order->id,
                'related_type' => 'SalesOrder',
            ];
        })->toArray();
    }

    private function mergeItems(array $salesOrders, array $receives, array $redFlushes): array
    {
        $items = array_merge($salesOrders, $receives, $redFlushes);
        usort($items, function ($a, $b) {
            if ($a['date'] === $b['date']) {
                return ($a['related_id'] ?? 0) <=> ($b['related_id'] ?? 0);
            }

            return $a['date'] <=> $b['date'];
        });

        return $items;
    }

    private function calculateRunningBalance(array $items, float $openingBalance): array
    {
        $runningBalance = $openingBalance;
        $result = [];

        foreach ($items as &$item) {
            $receivable = $item['receivable'] ?? 0;
            $received = $item['received'] ?? 0;
            $runningBalance += $receivable - $received;
            $item['balance'] = round($runningBalance, 2);
            $result[] = $item;
        }

        return $result;
    }

    public function getCustomers(): array
    {
        return Customer::where('is_active', 1)
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->toArray();
    }
}
