<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Models\Receive;

/**
 * 客户对账服务
 *
 * 核心逻辑：
 * 1. 查询客户在指定时间段内的所有往来单据
 *    - 销售订单（产生应收）
 *    - 收款单（减少应收）
 *    - 费用单（如果有客户关联）
 *    - 红冲单（负金额）
 * 2. 合并所有单据，按日期排序
 * 3. 计算期初余额、本期应收、本期已收、期末余额
 * 4. 逐行计算余额（上一行余额 + 本行应收 - 本行已收）
 */
class StatementService
{
    /**
     * 获取客户对账单数据
     *
     * @param int $customerId 客户ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return array
     */
    public function getStatement(int $customerId, string $startDate, string $endDate): array
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
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

        // 1. 查询期初余额（startDate之前的所有应收-已收）
        $openingBalance = $this->calculateOpeningBalance($customerId, $startDate);

        // 2. 查询本期销售订单（产生应收）
        $salesOrders = $this->getSalesOrders($customerId, $startDate, $endDate);

        // 3. 查询本期收款单（减少应收）
        $receives = $this->getReceives($customerId, $startDate, $endDate);

        // 4. 查询本期红冲单（负金额）
        $redFlushes = $this->getRedFlushes($customerId, $startDate, $endDate);

        // 5. 合并所有单据
        $items = $this->mergeItems($salesOrders, $receives, $redFlushes);

        // 6. 计算汇总
        $totalReceivable = collect($items)->sum(function ($item) {
            return $item['receivable'] ?? 0;
        });
        $totalReceived = collect($items)->sum(function ($item) {
            return $item['received'] ?? 0;
        });

        // 7. 计算期末余额
        $closingBalance = $openingBalance + $totalReceivable - $totalReceived;

        // 8. 逐行计算余额
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

    /**
     * 计算期初余额
     */
    private function calculateOpeningBalance(int $customerId, string $startDate): float
    {
        // 期初余额 = startDate之前的所有销售订单总额 - 已收款
        $query = DB::table('sales_orders as so')
            ->where('so.customer_id', $customerId)
            ->where('so.status', '!=', 'cancelled')
            ->where('so.status', '!=', '已红冲');

        // 排除已红冲的订单（它们应该是负数）
        $allOrders = SalesOrder::where('customer_id', $customerId)
            ->whereNotIn('status', ['cancelled', '已红冲'])
            ->select('total_amount', 'paid_amount')
            ->where('order_date', '<', $startDate)
            ->get();

        $total = $allOrders->sum('total_amount');
        $paid = $allOrders->sum('paid_amount');

        return (float) $total - (float) $paid;
    }

    /**
     * 获取销售订单
     */
    private function getSalesOrders(int $customerId, string $startDate, string $endDate): array
    {
        return SalesOrder::where('customer_id', $customerId)
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->select('id', 'order_no', 'order_date', 'total_amount', 'paid_amount', 'status', 'created_by')
            ->orderBy('order_date')
            ->get()
            ->map(function ($order) {
                return [
                    'date' => $order->order_date?->format('Y-m-d'),
                    'doc_no' => $order->order_no,
                    'doc_type' => '销售订单',
                    'summary' => '',
                    'receivable' => (float) $order->total_amount,
                    'received' => null,
                    'operator' => $order->created_by,
                    'related_id' => $order->id,
                    'related_type' => 'SalesOrder',
                ];
            })
            ->toArray();
    }

    /**
     * 获取收款单
     */
    private function getReceives(int $customerId, string $startDate, string $endDate): array
    {
        return Receive::where('customer_id', $customerId)
            ->whereBetween('receive_date', [$startDate, $endDate])
            ->where('status', 1) // 只统计已审核的收款单
            ->select('id', 'receive_no', 'receive_date', 'amount', 'remark', 'handler_id')
            ->orderBy('receive_date')
            ->get()
            ->map(function ($receive) {
                return [
                    'date' => $receive->receive_date?->format('Y-m-d'),
                    'doc_no' => $receive->receive_no,
                    'doc_type' => '收款单',
                    'summary' => $receive->remark ?? '',
                    'receivable' => null,
                    'received' => (float) $receive->amount,
                    'operator' => $receive->handler_id,
                    'related_id' => $receive->id,
                    'related_type' => 'Receive',
                ];
            })
            ->toArray();
    }

    /**
     * 获取红冲单
     */
    private function getRedFlushes(int $customerId, string $startDate, string $endDate): array
    {
        // 红冲单是销售订单的负数
        return SalesOrder::where('customer_id', $customerId)
            ->whereBetween('order_date', [$startDate, $endDate])
            ->where('status', '已红冲')
            ->select('id', 'order_no', 'order_date', 'total_amount', 'red_flush_reason')
            ->orderBy('order_date')
            ->get()
            ->map(function ($order) {
                return [
                    'date' => $order->order_date?->format('Y-m-d'),
                    'doc_no' => $order->order_no,
                    'doc_type' => '红冲单',
                    'summary' => $order->red_flush_reason ?? '红冲',
                    'receivable' => (float) $order->total_amount, // 已经是负数
                    'received' => null,
                    'operator' => null,
                    'related_id' => $order->id,
                    'related_type' => 'SalesOrder',
                ];
            })
            ->toArray();
    }

    /**
     * 合并所有单据并按日期排序
     */
    private function mergeItems(array $salesOrders, array $receives, array $redFlushes): array
    {
        $items = array_merge($salesOrders, $receives, $redFlushes);
        usort($items, function ($a, $b) {
            if ($a['date'] === $b['date']) {
                return $a['related_id'] <=> $b['related_id'];
            }
            return $a['date'] <=> $b['date'];
        });

        return $items;
    }

    /**
     * 逐行计算余额
     */
    private function calculateRunningBalance(array $items, float $openingBalance): array
    {
        $runningBalance = $openingBalance;
        $result = [];

        foreach ($items as $item) {
            $receivable = $item['receivable'] ?? 0;
            $received = $item['received'] ?? 0;
            $runningBalance += $receivable - $received;

            $item['balance'] = round($runningBalance, 2);
            $result[] = $item;
        }

        return $result;
    }

    /**
     * 获取客户列表（用于下拉选择）
     */
    public function getCustomers(): array
    {
        return Customer::where('is_active', 1)
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->toArray();
    }
}
