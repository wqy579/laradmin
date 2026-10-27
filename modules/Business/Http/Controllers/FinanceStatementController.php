<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Business\Services\FinanceStatementService;

/**
 * 财务对账单（B3）—— 客户应收 / 供应商应付 对账单查询视图
 *
 * 只读透出 ledger 往来科目流水，无写入。
 */
class FinanceStatementController extends Controller
{
    use ResponseTrait;

    public function __construct(private FinanceStatementService $service) {}

    public function index(Request $request)
    {
        $kind = $request->input('kind', 'customer') === 'supplier' ? 'supplier' : 'customer';
        $unitId = $request->integer('unit_id') ?: null;

        $data = $this->service->dataset($kind, $unitId);

        return $this->success($data);
    }
}
