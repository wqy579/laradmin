<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Services\CashFlowService;

/**
 * 现金账户收支查询控制器（D-29，cash/accounts 前缀）
 */
class CashAccountController extends Controller
{
    use ResponseTrait;

    private const EXPORT_LIMIT = 100000;

    public function __construct(private CashFlowService $service) {}

    /** 账户下拉选项 */
    public function options()
    {
        $list = CashAccount::query()
            ->where('status', CashAccount::STATUS_ENABLED)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'type', 'opening_balance'])
            ->map(fn (CashAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'type_label' => CashAccount::typeLabel($a->type),
                'opening_balance' => (float) $a->opening_balance,
            ]);

        return $this->success(['list' => $list]);
    }

    /** 收支流水分页（含逐行累计余额） */
    public function flows(Request $request, int $id)
    {
        [$start, $end, $direction, $page, $pageSize] = $this->params($request);

        return $this->success($this->service->query($id, $start, $end, $direction, $page, $pageSize));
    }

    /** 导出 CSV（同筛选口径全量） */
    public function export(Request $request, int $id)
    {
        [$start, $end, $direction] = $this->params($request);
        $data = $this->service->query($id, $start, $end, $direction, 1, self::EXPORT_LIMIT);

        $csv = "\u{FEFF}日期,单据号,摘要,收入,支出,余额\n";
        foreach ($data['list'] as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s\n",
                $r['date'],
                $r['no'],
                $this->csvCell($r['summary']),
                number_format($r['income'], 2, '.', ''),
                number_format($r['expense'], 2, '.', ''),
                number_format($r['balance'], 2, '.', '')
            );
        }
        $csv .= sprintf(
            "合计（%d 条）,,,%s,%s,\n",
            $data['total'],
            number_format($data['income_total'], 2, '.', ''),
            number_format($data['expense_total'], 2, '.', '')
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cash_account_flows.csv"',
        ]);
    }

    /** @return array{0:string,1:string,2:string,3:int,4:int} */
    private function params(Request $request): array
    {
        return [
            trim((string) $request->input('start_date', '')),
            trim((string) $request->input('end_date', '')),
            trim((string) $request->input('direction', '')),
            max(1, (int) $request->input('page', 1)),
            min(200, max(1, (int) $request->input('page_size', 20))),
        ];
    }

    private function csvCell(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
