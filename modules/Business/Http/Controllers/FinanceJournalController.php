<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Business\Services\FinanceJournalService;

/**
 * 财务日记账（B1）—— 现金/银行日记账查询视图
 *
 * 只读透出 ledger 现金(1001)/银行(1002)科目流水，无写入。
 */
class FinanceJournalController extends Controller
{
    use ResponseTrait;

    public function __construct(private FinanceJournalService $service) {}

    public function index(Request $request)
    {
        $filters = [
            'date_type' => $request->input('date_type', 'book'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'subject_id' => $request->input('subject_id'),
            'voucher_no' => $request->input('voucher_no'),
            'summary' => $request->input('summary'),
            'salesman' => $request->input('salesman'),
            'include_red' => $request->boolean('include_red'),
        ];

        $result = $this->service->list(
            array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            (int) $request->input('page', 1),
            (int) $request->input('page_size', 30)
        );

        $paginator = new LengthAwarePaginator(
            $result['list'],
            $result['total'],
            $result['page_size'],
            $result['page'],
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $data = [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'income_total' => $result['income_total'],
            'outcome_total' => $result['outcome_total'],
        ];

        return $this->success($data);
    }
}
