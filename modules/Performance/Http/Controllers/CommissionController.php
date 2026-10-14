<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Models\CommissionOrder;
use Modules\Performance\Models\CommissionOrderItem;
use Modules\Performance\Support\CommissionCalculator;
use Modules\Report\Support\ReportFilter;

/**
 * 提成计算与提成单。
 *
 * preview：试算，不落库，返回按业务员分组的结果确认表。
 * export：与 preview 同参，导出试算/生成用的业务员汇总 CSV。
 * generate：确认生成提成单（每业务员一张，状态 pending）。
 * 提成单审核/驳回/标记发放/导出在 CommissionOrderController。
 *
 * 金额口径（任务书 §八）：commission_amount=应发，deduction=扣减（坏账/抹零），
 * actual_amount=实发=应发-扣减。
 */
class CommissionController extends Controller
{
    /** 试算 */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validatePeriod($request);

        $calc = new CommissionCalculator;
        $result = $calc->preview($data['period'], $data['employee_ids'] ?? []);

        return $this->success($result);
    }

    /** 试算结果导出（与界面筛选一致的服务端 CSV） */
    public function export(Request $request)
    {
        $data = $this->validatePeriod($request);

        $calc = new CommissionCalculator;
        $result = $calc->preview($data['period'], $data['employee_ids'] ?? []);

        $headers = ['业务员', '部门', '销售额', '回款额', '退货额', '毛利', '计提基数', '应发提成', '扣减', '实发提成'];
        $rows = [];
        foreach ($result['groups'] ?? [] as $g) {
            $rows[] = [
                $g['employee_name'] ?? '',
                $g['department_name'] ?? '',
                number_format((float) ($g['sales_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($g['received_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($g['return_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($g['profit_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($g['base_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($g['commission_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($g['deduction'] ?? 0), 2, '.', ''),
                number_format((float) ($g['actual_amount'] ?? 0), 2, '.', ''),
            ];
        }

        $csv = ReportFilter::csv('提成计算明细 '.$data['period'], $headers, $rows);

        return ReportFilter::csvResponse($csv, 'commission_preview_'.$data['period'].'.csv');
    }

    /** 确认生成提成单 */
    public function generate(Request $request): JsonResponse
    {
        $data = $this->validatePeriod($request);

        $calc = new CommissionCalculator;
        $result = $calc->preview($data['period'], $data['employee_ids'] ?? []);

        if (empty($result['groups'])) {
            return $this->error('无可生成提成的数据，请确认该月已有已审核订单与适用方案', 400);
        }

        $user = auth('admin')->user();
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
                    'department_id' => $g['department_id'] ?? null,
                    'department_name' => $g['department_name'] ?? null,
                    'sales_amount' => $g['sales_amount'] ?? 0,
                    'received_amount' => $g['received_amount'] ?? 0,
                    'return_amount' => $g['return_amount'] ?? 0,
                    'profit_amount' => $g['profit_amount'] ?? 0,
                    'rule_id' => $g['rule_id'] ?? null,
                    'rate' => $g['rate'] ?? null,
                    'base_amount' => $g['base_amount'] ?? 0,
                    'commission_amount' => $g['commission_amount'] ?? 0, // 应发
                    'deduction' => $g['deduction'] ?? 0,
                    'actual_amount' => $g['actual_amount'] ?? 0, // 实发
                    'status' => 'pending',
                    'detail_snapshot' => $g['detail_lines'] ?? [],
                    'maker_id' => $makerId,
                    'maker_name' => $makerName,
                ]);

                foreach ($g['detail_lines'] ?? [] as $line) {
                    CommissionOrderItem::create([
                        'order_id' => $order->id,
                        'source_type' => $line['source_type'] ?? 'order',
                        'source_id' => $line['source_id'] ?? null,
                        'source_no' => $line['source_no'] ?? null,
                        'product_id' => $line['product_id'] ?? null,
                        'product_name' => $line['product_name'] ?? ($line['source_no'] ?? null),
                        'quantity' => $line['quantity'] ?? 0,
                        'sales_amount' => $line['sales_amount'] ?? 0,
                        'cost_amount' => $line['cost_amount'] ?? 0,
                        'profit_amount' => $line['profit_amount'] ?? 0,
                        'base_amount' => $line['base_amount'] ?? 0,
                        'contribution' => $line['contribution'] ?? 0,
                        'rule_id' => $line['rule_id'] ?? ($g['rule_id'] ?? null),
                        'rule_name' => $g['rule_name'] ?? null,
                        'calc_type' => $g['calc_type'] ?? null,
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

    private function validatePeriod(Request $request): array
    {
        return $request->validate([
            'period' => 'required|string|date_format:Y-m',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer',
        ]);
    }

    /** 单号：TC + YYYY-MM + 4 位序号，避开已占用号 */
    private function genBillNo(string $period): string
    {
        $prefix = 'TC'.$period;
        $seq = CommissionOrder::where('bill_no', 'like', $prefix.'%')->count() + 1;

        do {
            $billNo = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (CommissionOrder::where('bill_no', $billNo)->exists());

        return $billNo;
    }
}
