<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Business\Services\FinanceBalanceService;

/**
 * 财务余额查询（B3）—— 客户 / 品牌 / 辅助单位 余额查询视图
 *
 * 只读透出 ledger 分录按维度聚合余额，无写入。
 *
 * QA-06：余额唯一数据源为 Ledger；本接口为历史兼容只读视图（客户/品牌/辅助单位维度聚合
 * ledger 端点暂缺，故 /finance/balance 页仍调用本接口）。
 */
class FinanceBalanceController extends Controller
{
    use ResponseTrait;

    public function __construct(private FinanceBalanceService $service) {}

    public function index(Request $request)
    {
        $dimension = $request->input('dimension', 'customer');
        if (! in_array($dimension, ['customer', 'brand', 'aux'], true)) {
            $dimension = 'customer';
        }

        $filters = [
            'keyword' => $request->input('keyword'),
            'subject' => $request->input('subject'),
            'unitType' => $request->input('unitType'),
            'brand' => $request->input('brand'),
        ];

        $list = $this->service->list($dimension, array_filter($filters, fn ($v) => $v !== null && $v !== ''));

        return $this->success(['list' => $list]);
    }
}
