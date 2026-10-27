<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Business\Services\FinanceSubjectChangeService;

/**
 * 财务科目变化明细（B3）—— 逐科目流水与滚动余额查询视图
 *
 * 只读透出 ledger 分录，无写入。
 */
class FinanceSubjectChangeController extends Controller
{
    use ResponseTrait;

    public function __construct(private FinanceSubjectChangeService $service) {}

    public function index(Request $request)
    {
        $filters = [
            'subject_id' => $request->input('subject_id'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'doc_type' => $request->input('doc_type'),
            'exclude_before_open' => $request->boolean('exclude_before_open'),
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
            'doc_types' => $result['doc_types'],
        ];

        return $this->success($data);
    }
}
