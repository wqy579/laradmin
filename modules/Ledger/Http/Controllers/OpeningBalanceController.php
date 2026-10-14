<?php

namespace Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Ledger\Services\OpeningBalanceService;

/**
 * 期初余额控制器
 */
class OpeningBalanceController extends Controller
{
    use ResponseTrait;

    public function __construct(private OpeningBalanceService $service) {}

    public function index(Request $request)
    {
        $period = $request->input('period', date('Y-m'));

        return $this->success($this->service->list($period));
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'period' => 'required|string|size:7',
            'items' => 'required|array',
            'items.*.subject_id' => 'required|exists:ledger_subjects,id',
            'items.*.opening_debit' => 'nullable|numeric',
            'items.*.opening_credit' => 'nullable|numeric',
            'items.*.year_debit' => 'nullable|numeric',
            'items.*.year_credit' => 'nullable|numeric',
        ]);

        try {
            $this->service->save($validated['period'], $validated['items']);

            return $this->success(null, '保存成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('保存失败：'.$e->getMessage(), 500);
        }
    }

    public function balanceCheck(Request $request)
    {
        $period = $request->input('period', date('Y-m'));

        return $this->success($this->service->balanceCheck($period));
    }
}
