<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Business\Services\FinanceMonthCloseService;

/**
 * 财务月结（B2）—— 月结向导 + 月结查询
 *
 * 复用 ledger PeriodService 既有结账逻辑，本控制器仅做薄透出。
 */
class FinanceMonthCloseController extends Controller
{
    use ResponseTrait;

    public function __construct(private FinanceMonthCloseService $service) {}

    public function index()
    {
        return $this->success($this->service->periods());
    }

    public function check(string $period)
    {
        try {
            return $this->success($this->service->check($period));
        } catch (\Throwable $e) {
            return $this->error('结账检查失败：'.$e->getMessage(), 500);
        }
    }

    public function close(Request $request, string $period)
    {
        $user = $request->input('auth_user');
        try {
            $p = $this->service->close($period, $user?->id ?? 0, $user?->real_name ?? $user?->username ?? '系统');

            return $this->success([
                'period' => $p->periodString(),
                'status' => $p->status,
                'closed_by_name' => $p->closed_by_name,
                'closed_at' => $p->closed_at?->format('Y-m-d H:i:s'),
            ], '月结成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('月结失败：'.$e->getMessage(), 500);
        }
    }

    public function reopen(string $period)
    {
        try {
            $this->service->reopen($period);

            return $this->success(null, '反结账成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('反结账失败：'.$e->getMessage(), 500);
        }
    }
}
