<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Order\Models\Receive;

class OtherIncomeController extends Controller
{
    use ResponseTrait;

    /** 导出行数上限，防止全表拉爆内存 */
    public const EXPORT_LIMIT = 50000;

    public function index(Request $request)
    {
        $list = $this->filtered($request)
            ->orderByDesc('id')
            ->paginate((int) $request->input('page_size', 20), ['*'], 'page', (int) $request->input('page', 1));

        return $this->paginated($list);
    }

    public function statistics(Request $request)
    {
        $query = Receive::where('status', 1)->where('receive_type', 2);
        if ($request->filled('start_date')) {
            $query->where('receive_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('receive_date', '<', CarbonImmutable::parse($request->end_date)->startOfDay()->addDay());
        }

        return $this->success([
            'total_amount' => round((float) $query->sum('amount'), 2),
            'count' => $query->count(),
        ]);
    }

    /** 导出 CSV（与列表同一套筛选条件，上限保护见 EXPORT_LIMIT） */
    public function export(Request $request)
    {
        $rows = $this->filtered($request)
            ->orderByDesc('id')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        $csv = '﻿'; // BOM：Excel 正确识别 UTF-8
        $csv .= "其它收入单\n\n";
        $csv .= "单号,往来单位,金额,收入日期,付款方式,经手人,状态,备注\n";

        foreach ($rows as $row) {
            $csv .= sprintf(
                "%s,%s,%.2f,%s,%s,%s,%s,%s\n",
                $this->csvCell($row->receive_no),
                $this->csvCell($row->customer?->name ?? ''),
                (float) $row->amount,
                $this->csvCell($row->receive_date?->format('Y-m-d') ?? ''),
                $this->csvCell($row->payment_method ?? ''),
                $this->csvCell($row->handler?->name ?? ''),
                $row->status == 1 ? '已审核' : '草稿',
                $this->csvCell($row->remark ?? ''),
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="other-income.csv"',
        ]);
    }

    /**
     * 其它收入单列表/导出共用筛选：其他收款（receive_type = 2）。
     *
     * 日期上界必须是**开区间**：receive_date 是 date cast，经 Eloquent 写入 sqlite 存成
     * 'YYYY-MM-DD 00:00:00'，`<= 'YYYY-MM-DD'` 会静默丢掉 end_date 当天那一笔。
     */
    private function filtered(Request $request): Builder
    {
        $query = Receive::with('customer', 'handler')->where('receive_type', 2);

        if (isset($request->status)) {
            $query->where('status', $request->status);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('start_date')) {
            $query->where('receive_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('receive_date', '<', CarbonImmutable::parse($request->end_date)->startOfDay()->addDay());
        }
        if ($request->filled('keyword')) {
            $query->where('receive_no', 'like', '%'.$request->keyword.'%');
        }

        return $query;
    }

    private function csvCell($value): string
    {
        $value = (string) ($value ?? '');
        if (preg_match('/[",\r\n]/', $value)) {
            $value = '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
