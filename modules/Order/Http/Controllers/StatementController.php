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
}
