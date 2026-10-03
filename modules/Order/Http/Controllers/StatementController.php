<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Order\Services\StatementService;

class StatementController extends Controller
{
    use ResponseTrait;

    protected StatementService $service;

    public function __construct(StatementService $service)
    {
        $this->service = $service;
    }

    /**
     * 获取客户列表（用于下拉选择）
     */
    public function customers()
    {
        $customers = $this->service->getCustomers();

        return $this->success($customers);
    }

    /**
     * 获取客户对账单数据
     *
     * GET /admin/business/customer-statement?customer_id=1&start_date=2026-10-01&end_date=2026-10-31
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $statement = $this->service->getStatement(
            (int) $validated['customer_id'],
            $validated['start_date'],
            $validated['end_date']
        );

        return $this->success($statement);
    }

    /**
     * 导出Excel (CSV格式)
     *
     * GET /admin/business/customer-statement/export?customer_id=1&start_date=...&end_date=...
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $statement = $this->service->getStatement(
            (int) $validated['customer_id'],
            $validated['start_date'],
            $validated['end_date']
        );

        // 构建CSV内容
        $csv = '﻿'; // BOM for Excel UTF-8
        $csv .= "客户对账单\n";
        $csv .= "客户：{$statement['customer_name']}\n";
        $csv .= "对账期间：{$statement['start_date']} 至 {$statement['end_date']}\n\n";
        $csv .= "期初余额,{$statement['opening_balance']}\n";
        $csv .= "本期应收,{$statement['total_receivable']}\n";
        $csv .= "本期已收,{$statement['total_received']}\n";
        $csv .= "期末余额,{$statement['closing_balance']}\n\n";
        $csv .= "序号,单据日期,单据编号,单据类型,摘要,应收金额,已收金额,余额,经办人\n";

        foreach ($statement['items'] as $idx => $item) {
            $csv .= sprintf(
                "%d,%s,%s,%s,\"%s\",%s,%s,%s,%s\n",
                $idx + 1,
                $item['date'] ?? '',
                $item['doc_no'] ?? '',
                $item['doc_type'] ?? '',
                str_replace('"', '""', $item['summary'] ?? ''),
                $item['receivable'] ?? '',
                $item['received'] ?? '',
                $item['balance'] ?? 0,
                $item['operator'] ?? ''
            );
        }

        $csv .= sprintf("合计,,,,,%s,%s,%s,\n",
            $statement['total_receivable'],
            $statement['total_received'],
            $statement['closing_balance']
        );

        // 返回CSV文件
        $filename = sprintf('对账单_%s_%s_%s.csv',
            $statement['customer_name'],
            $statement['start_date'],
            $statement['end_date']
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * 打印对账单（返回完整数据供前端渲染打印模板）
     */
    public function print(Request $request)
    {
        // 与 index 相同的逻辑，只是路由不同
        return $this->index($request);
    }
}
