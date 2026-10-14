<?php

namespace Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Ledger\Services\ReportService;

/**
 * 科目余额表控制器（balance 前缀）
 */
class BalanceController extends Controller
{
    use ResponseTrait;

    public function __construct(private ReportService $service) {}

    public function index(Request $request)
    {
        $startPeriod = $request->input('start_period', date('Y').'-01');
        $endPeriod = $request->input('end_period', date('Y-m'));
        $maxLevel = (int) $request->input('max_level', 99);
        $leafOnly = (bool) $request->input('leaf_only', false);
        $includeZero = (bool) $request->input('include_zero', false);

        return $this->success($this->service->subjectBalance($startPeriod, $endPeriod, $maxLevel, $leafOnly, $includeZero));
    }

    public function export(Request $request)
    {
        $data = $this->service->subjectBalance(
            $request->input('start_period', date('Y').'-01'),
            $request->input('end_period', date('Y-m')),
            (int) $request->input('max_level', 99),
            (bool) $request->input('leaf_only', false),
            (bool) $request->input('include_zero', false)
        );
        $csv = "\u{FEFF}科目编码,科目名称,方向,期初借方,期初贷方,本期发生借方,本期发生贷方,期末借方,期末贷方\n";
        foreach ($data['rows'] as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r['code'],
                $this->csvCell($r['name']),
                $r['direction'] === 'debit' ? '借' : '贷',
                number_format((float) $r['opening_debit'], 2, '.', ''),
                number_format((float) $r['opening_credit'], 2, '.', ''),
                number_format((float) $r['occur_debit'], 2, '.', ''),
                number_format((float) $r['occur_credit'], 2, '.', ''),
                number_format((float) $r['end_debit'], 2, '.', ''),
                number_format((float) $r['end_credit'], 2, '.', '')
            );
        }
        $t = $data['total'];
        $csv .= sprintf(
            "合计,,,%s,%s,%s,%s,%s,%s\n",
            number_format((float) $t['opening_debit'], 2, '.', ''),
            number_format((float) $t['opening_credit'], 2, '.', ''),
            number_format((float) $t['occur_debit'], 2, '.', ''),
            number_format((float) $t['occur_credit'], 2, '.', ''),
            number_format((float) $t['end_debit'], 2, '.', ''),
            number_format((float) $t['end_credit'], 2, '.', '')
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="ledger_subject_balance.csv"',
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
