<?php

namespace Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Period;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Services\VoucherService;

/**
 * 总账驾驶舱（A8 首页 dashboard）
 *
 * 4 张统计卡（凭证数 / 待审核 / 借贷差额 / 月结状态）+ 最近凭证列表 + 穿透。
 * 统计口径取自 ledger_ 表（与任务书 A8 规格一致）：
 *  - 凭证数：ledger_vouchers 全部记录数
 *  - 待审核：status = submitted 计数
 *  - 借贷差额：SUM(total_debit) - SUM(total_credit)
 *  - 月结状态：当前期（本年本月）ledger_periods 的 status
 */
class DashboardController extends Controller
{
    use ResponseTrait;

    public function __construct(private VoucherService $voucherService) {}

    /**
     * 4 张统计卡聚合数据。
     */
    public function overview()
    {
        $now = now();

        $voucherCount = DB::table('ledger_vouchers')->count();
        $pendingCount = DB::table('ledger_vouchers')
            ->where('status', Voucher::STATUS_SUBMITTED)
            ->count();
        $debitSum = DB::table('ledger_vouchers')->sum('total_debit');
        $creditSum = DB::table('ledger_vouchers')->sum('total_credit');
        $diff = round($debitSum - $creditSum, 2);

        // 当前期（本年本月）月结状态；无记录期视为未结账
        $currentPeriod = DB::table('ledger_periods')
            ->where('year', $now->year)
            ->where('month', $now->month)
            ->first();
        $isClosed = $currentPeriod !== null && $currentPeriod->status === Period::STATUS_CLOSED;

        return $this->success([
            'stats' => [
                'voucher_count' => (int) $voucherCount,
                'pending_count' => (int) $pendingCount,
                'debit_credit_diff' => $diff,
                'period' => [
                    'period' => $now->format('Y-m'),
                    'status' => $isClosed ? '已结账' : '未结账',
                    'closed' => $isClosed,
                    'voucher_count' => (int) ($currentPeriod?->voucher_count ?? 0),
                    'total_debit' => (float) ($currentPeriod?->total_debit ?? 0),
                    'total_credit' => (float) ($currentPeriod?->total_credit ?? 0),
                ],
            ],
        ]);
    }

    /**
     * 最近凭证列表（复用 VoucherService::list，按凭证日期倒序）。
     */
    public function recentVouchers(Request $request)
    {
        $limit = (int) $request->integer('limit', 20);
        $limit = min(max($limit, 1), 50);

        return $this->success($this->voucherService->list([], 1, $limit));
    }
}
