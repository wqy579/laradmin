<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Models\CommissionOrder;
use Modules\Performance\Models\CommissionOrderItem;
use Modules\Performance\Support\CommissionCalculator;

/**
 * 提成计算与提成单。
 *
 * preview：试算，不落库，返回按业务员分组的结果确认表。
 * generate：确认生成提成单（每业务员一张，状态 pending）。
 * 提成单审核/驳回/标记发放/导出在 CommissionOrderController。
 */
class CommissionController extends Controller
{
    /** 试算 */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => 'required|string|date_format:Y-m',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer',
        ]);

        $calc = new CommissionCalculator();
        $result = $calc->preview($data['period'], $data['employee_ids'] ?? []);

        return $this->success($result);
    }

    /** 确认生成提成单 */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => 'required|string|date_format:Y-m',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer',
        ]);

        $calc = new CommissionCalculator();
        $result = $calc->preview($data['period'], $data['employee_ids'] ?? []);

        if (empty($result['groups'])) {
            return $this->error('无可生成提成的数据，请确认该月已有已审核订单与适用方案', 400);
        }

        $user = $request->attributes->get('auth_user');
        $makerId = $user?->id;
        $makerName = $user?->real_name;

        $created = [];
        DB::transaction(function () use ($result, $data, $makerId, $makerName, &$created) {
            foreach ($result['groups'] as $g) {
                // 同月同业务员幂等：已存在 pending/audited 不重复生成
                $exists = CommissionOrder::where('period', $data['period'])
                    ->where('employee_id', $g['employee_id'])
                    ->whereIn('status', ['pending', 'audited', 'paid'])
                    ->exists();
                if ($exists) {
                    continue;
                }

                $billNo = $this->genBillNo($data['period']);
                $order = CommissionOrder::create([
                    'bill_no' => $billNo,
                    'period' => $data['period'],
                    'employee_id' => $g['employee_id'],
                    'employee_name' => $g['employee_name'],
                    'department_name' => $g['department_name'],
                    'sale_amount' => $g['sale_amount'],
                    'payment_amount' => $g['payment_amount'],
                    'return_amount' => $g['return_amount'],
                    'gross_profit' => $g['gross_profit'],
                    'commission_base' => $g['commission_base'],
                    'commission_gross' => $g['commission_gross'],
                    'commission_deduct' => $g['commission_deduct'],
                    'commission_amount' => $g['commission_amount'],
                    'status' => 'pending',
                    'detail_snapshot' => $g['detail_lines'],
                    'maker_id' => $makerId,
                    'maker_name' => $makerName,
                ]);

                foreach ($g['detail_lines'] as $line) {
                    CommissionOrderItem::create([
                        'commission_order_id' => $order->id,
                        'source_type' => $line['source_type'] ?? 'order',
                        'source_no' => $line['source_no'] ?? null,
                        'product_id' => $line['product_id'] ?? null,
                        'product_name' => $line['source_no'] ?? null,
                        'qty' => 0,
                        'base_amount' => $line['base_amount'] ?? 0,
                        'rule_id' => $g['rule_id'],
                        'rule_name' => $g['rule_name'],
                        'calc_type' => $g['calc_type'],
                        'rate_or_unit' => $line['rate_or_unit'] ?? null,
                        'amount' => $line['amount'] ?? 0,
                    ]);
                }

                $created[] = $order->bill_no;
            }
        });

        if (empty($created)) {
            return $this->error('该月提成单已存在，不能重复生成', 400);
        }

        return $this->success(['bill_nos' => $created], '确认成功，已生成 '.count($created).' 张提成单');
    }

    private function genBillNo(string $period): string
    {
        $seq = CommissionOrder::where('period', $period)->count() + 1;

        return 'TC'.$period.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
