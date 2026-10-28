<?php

namespace Modules\Cash\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashFlow;
use Modules\Cash\Models\CashHandover;
use Modules\Cash\Models\CashHandoverItem;
use Modules\Order\Models\SalesOrder;

/**
 * 收入上交款（D2）业务服务
 *
 * 两条硬规则在此收口：
 *  1. 明细「本次上交」合计必须等于头金额（前端另有一道拦截）；
 *  2. 确认动作幂等——已确认单据再次请求返回当前状态，不重复记流水、不重复累加订单已收。
 *
 * 订单收款回写口径与 Order/ReceiveController::updatePaymentStatus 保持一致
 * （±0.01 容差 + 已红冲单保护），但不跨域调用 Order 的 private 方法。
 */
class CashHandoverService
{
    /** 明细本次上交合计与头金额的容差（元） */
    private const AMOUNT_TOLERANCE = 0.001;

    /**
     * 新建上交款单（头 + 明细）
     *
     * @param  array  $data  已校验过的头字段
     * @param  array  $items  明细行
     */
    public function create(array $data, array $items, ?int $operatorId): CashHandover
    {
        $sum = round(array_sum(array_map(fn (array $i) => (float) $i['handover_amount'], $items)), 2);
        if (abs($sum - round((float) $data['amount'], 2)) > self::AMOUNT_TOLERANCE) {
            throw new \DomainException('明细「本次上交」合计需与表头金额一致');
        }

        return DB::transaction(function () use ($data, $items) {
            $handover = CashHandover::create($data + [
                'handover_no' => $this->generateNo(),
                'status' => CashHandover::STATUS_PENDING,
            ]);

            foreach ($items as $idx => $item) {
                CashHandoverItem::create($item + [
                    'handover_id' => $handover->id,
                    'sort' => $idx + 1,
                ]);
            }

            return $handover->load('items');
        });
    }

    /**
     * 确认上交款单：记收入流水 + 回写订单收款状态 + 置已确认
     *
     * 幂等：已确认直接返回，不重复记账。
     */
    public function confirm(int $id, ?int $operatorId): CashHandover
    {
        return DB::transaction(function () use ($id, $operatorId) {
            $handover = CashHandover::query()->lockForUpdate()->findOrFail($id);

            if ($handover->status === CashHandover::STATUS_CONFIRMED) {
                return $handover->load('items');
            }

            $handover->status = CashHandover::STATUS_CONFIRMED;
            $handover->confirmed_by = $operatorId;
            $handover->confirmed_at = now();
            $handover->save();

            $this->recordFlow($handover, $operatorId);
            $this->applyOrderPayments($handover);

            return $handover->load('items');
        });
    }

    /** 确认后按头金额生成一条 income 流水，方向与金额口径同 D1 流水表 */
    private function recordFlow(CashHandover $handover, ?int $operatorId): void
    {
        CashFlow::create([
            'account_id' => $handover->account_id,
            'flow_no' => $handover->handover_no,
            'flow_date' => $handover->handover_date->format('Y-m-d'),
            'direction' => CashFlow::DIRECTION_INCOME,
            'summary' => '收入上交款 '.$handover->handover_no
                .($handover->salesman_name ? '（'.$handover->salesman_name.'）' : ''),
            'amount' => $handover->amount,
            'related_type' => 'cash_handover',
            'related_id' => $handover->id,
            'created_by' => $operatorId,
        ]);
    }

    /** 逐明细累加订单已收款并重算收款状态（跳过已红冲单） */
    private function applyOrderPayments(CashHandover $handover): void
    {
        $orderIds = $handover->items->pluck('sales_order_id')->filter()->unique();
        if ($orderIds->isEmpty()) {
            return;
        }

        $orders = SalesOrder::query()->lockForUpdate()->findMany($orderIds)->keyBy('id');

        foreach ($handover->items as $item) {
            $order = $orders->get($item->sales_order_id);
            if ($order === null || $order->status === '已红冲') {
                continue;
            }

            $order->paid_amount = round((float) $order->paid_amount + (float) $item->handover_amount, 2);
            $this->syncPaymentStatus($order);
            $order->save();
        }
    }

    /** 收款状态口径：与 Order 模块收款核销保持一致 */
    private function syncPaymentStatus(SalesOrder $order): void
    {
        $total = (float) $order->total_amount;
        $paid = (float) $order->paid_amount;

        if ($paid >= $total - 0.01) {
            $order->payment_status = '已收款';
        } elseif ($paid > 0.01) {
            $order->payment_status = '部分收款';
        } else {
            $order->payment_status = '未收款';
        }

        if ($order->status !== '已红冲' && $order->payment_status === '已收款' && $order->status !== '已收款') {
            $order->status = '已收款';
        }
    }

    /** 单号生成：SC + Ymd + 4位流水 */
    private function generateNo(): string
    {
        $like = 'SC'.date('Ymd').'%';
        $last = CashHandover::query()->where('handover_no', 'like', $like)->orderByDesc('handover_no')->value('handover_no');
        $seq = $last ? intval(substr($last, -4)) + 1 : 1;

        return 'SC'.date('Ymd').str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** 收款账户下拉（只出启用账户） */
    public function accountOptions(): array
    {
        return CashAccount::query()
            ->where('status', CashAccount::STATUS_ENABLED)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'name', 'type'])
            ->map(fn (CashAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'type_label' => CashAccount::typeLabel($a->type),
            ])
            ->all();
    }

    /** 业务员下拉：取销售订单中出现过的业务员，避免与其它域接口耦合 */
    public function salesmanOptions(): array
    {
        return SalesOrder::query()
            ->whereNotNull('salesman_id')
            ->whereNotNull('salesman_name')
            ->groupBy('salesman_id', 'salesman_name')
            ->orderBy('salesman_id')
            ->get(['salesman_id', 'salesman_name'])
            ->map(fn ($r) => ['id' => (int) $r->salesman_id, 'name' => (string) $r->salesman_name])
            ->all();
    }
}
