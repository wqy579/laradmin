<?php

namespace Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Ledger\Services\ReportService;

/**
 * 账簿与报表控制器：科目余额表、资产负债表、利润表、日记账/明细账。
 */
class ReportController extends Controller
{
    use ResponseTrait;

    public function __construct(private ReportService $service) {}

    public function subjectBalance(Request $request)
    {
        return $this->success($this->service->subjectBalance(
            $request->input('start_period', date('Y').'-01'),
            $request->input('end_period', date('Y-m')),
            (int) $request->input('max_level', 99),
            (bool) $request->input('leaf_only', false),
            (bool) $request->input('include_zero', false)
        ));
    }

    public function balanceSheet(Request $request)
    {
        $period = $request->input('period', date('Y-m'));

        return $this->success($this->service->balanceSheet($period));
    }

    public function incomeStatement(Request $request)
    {
        $period = $request->input('period', date('Y-m'));

        return $this->success($this->service->incomeStatement($period));
    }

    public function journal(Request $request)
    {
        $validated = $request->validate([
            'subject_id' => 'required|exists:ledger_subjects,id',
            'period' => 'required|string|size:7',
        ]);

        return $this->success($this->service->journal($validated['subject_id'], $validated['period']));
    }

    public function export(Request $request)
    {
        $type = $request->input('type', 'balance_sheet');
        $period = $request->input('period', date('Y-m'));
        $csv = "\u{FEFF}";
        if ($type === 'income_statement') {
            $data = $this->service->incomeStatement($period);
            $csv .= "项目,本月数,本年累计数\n";
            foreach ($data['rows'] as $r) {
                $csv .= sprintf(
                    "%s,%s,%s\n",
                    $this->csvCell($r['name']),
                    number_format((float) $r['month'], 2, '.', ''),
                    number_format((float) $r['year'], 2, '.', '')
                );
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="ledger_income_statement.csv"',
            ]);
        }

        // 默认资产负债表
        $data = $this->service->balanceSheet($period);
        $csv .= "资产,期末余额,年初余额,负债和所有者权益,期末余额,年初余额\n";
        $left = $data['left'];
        $right = $data['right'];
        $maxRows = max(count($left), count($right));
        for ($i = 0; $i < $maxRows; $i++) {
            $l = $left[$i] ?? null;
            $r = $right[$i] ?? null;
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s\n",
                $this->csvCell($l['name'] ?? ''),
                isset($l) ? number_format((float) $l['end'], 2, '.', '') : '',
                isset($l) ? number_format((float) $l['year'], 2, '.', '') : '',
                $this->csvCell($r['name'] ?? ''),
                isset($r) ? number_format((float) $r['end'], 2, '.', '') : '',
                isset($r) ? number_format((float) $r['year'], 2, '.', '') : ''
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="ledger_balance_sheet.csv"',
        ]);
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
