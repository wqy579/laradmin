<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Cash\Services\CashDashboardService;

/**
 * 出纳工作台控制器（D8，cash/dashboard 前缀）
 *
 * 两个只读聚合接口，只做展示与穿透，不含任何写操作。
 */
class CashDashboardController extends Controller
{
    use ResponseTrait;

    public function __construct(private CashDashboardService $service) {}

    /** 当日资金全景 + 待办 */
    public function summary()
    {
        return $this->success($this->service->summary());
    }

    /** 近 N 日收支趋势（默认 7，上限 31，日期连续补零） */
    public function trend(Request $request)
    {
        $days = $request->integer('days', CashDashboardService::TREND_DEFAULT_DAYS);

        return $this->success($this->service->trend($days));
    }
}
