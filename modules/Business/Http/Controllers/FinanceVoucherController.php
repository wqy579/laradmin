<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Business\Services\FinanceVoucherService;

/**
 * 财务凭证查询视图（B1）—— 只读
 *
 * 凭证录入/审核统一走 ledger 视角，本视图仅透出 ledger 凭证分组展示（含红冲）。
 *
 * QA-06：凭证唯一数据源为 Ledger（/admin/ledger/vouchers）；本接口为历史兼容只读视图，
 * 前端 /finance/voucher 页已直连 ledger 接口，此处仅供外部/历史调用方过渡。
 */
class FinanceVoucherController extends Controller
{
    use ResponseTrait;

    public function __construct(private FinanceVoucherService $service) {}

    public function index(Request $request)
    {
        $filters = [
            'no' => $request->input('no'),
            'status' => $request->input('status'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'subject' => $request->input('subject'),
            'summary' => $request->input('summary'),
            'employee' => $request->input('employee'),
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

        return $this->paginated($paginator);
    }
}
