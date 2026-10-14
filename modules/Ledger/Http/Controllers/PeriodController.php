<?php

namespace Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Ledger\Services\PeriodService;

/**
 * 财务月结控制器
 */
class PeriodController extends Controller
{
    use ResponseTrait;

    public function __construct(private PeriodService $service) {}

    public function index()
    {
        return $this->success($this->service->list());
    }

    public function check(string $period)
    {
        try {
            return $this->success($this->service->check($period));
        } catch (\Throwable $e) {
            return $this->error('结账检查失败：'.$e->getMessage(), 500);
        }
    }

    public function carryForward(Request $request, string $period)
    {
        $user = $request->input('auth_user');
        try {
            $voucher = $this->service->carryForward($period, $user?->id ?? 0, $user?->real_name ?? $user?->username ?? '系统');

            return $this->success(['voucher_no' => $voucher->voucher_no], '结转损益凭证已生成');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('结转失败：'.$e->getMessage(), 500);
        }
    }

    public function close(Request $request, string $period)
    {
        $user = $request->input('auth_user');
        try {
            $p = $this->service->close($period, $user?->id ?? 0, $user?->real_name ?? $user?->username ?? '系统');

            return $this->success(null, '月结成功');
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
