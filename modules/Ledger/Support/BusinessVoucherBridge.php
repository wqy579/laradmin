<?php

namespace Modules\Ledger\Support;

use Illuminate\Support\Facades\Log;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Services\VoucherGenerateService;

/**
 * 业务单据审核时「顺手生成凭证」的统一封装（QA-05）。
 *
 * 硬要求：**凭证生成失败绝不能回滚或阻断业务审核**。审核是业务事实，凭证只是它的
 * 财务映射；缺科目、期间已月结、金额不合规都属可后补的问题，
 * 用 `php artisan ledger:generate-vouchers` 补跑即可。
 * 所以这里吞掉所有异常只记日志（范式同 modules/Asset/Services/AssetPurchaseService.php:87-99，
 * 差别是本方法是多站点共用，故同时兜 \Throwable——审核接口不该因为总账表没建好而 500）。
 *
 * 用法：业务侧在**事务提交之后**调用
 * ```
 * $this->generateVoucherQuietly(Voucher::SOURCE_RECEIVE, (int) $receive->id);
 * ```
 * 必须放在事务外：凭证落库失败时不应把库存/单据状态一起回滚，
 * 反过来业务事务未提交时取号也会读不到自己的号。
 */
trait BusinessVoucherBridge
{
    protected function generateVoucherQuietly(string $billType, int $billId, array $context = []): ?Voucher
    {
        if ($billId <= 0) {
            return null;
        }

        try {
            return app(VoucherGenerateService::class)->generateFromBusiness($billType, $billId, $context);
        } catch (\DomainException $e) {
            // 业务性失败（期间已月结/科目缺失/金额为零）：记警告，审核照常通过
            Log::warning('业务单据自动生成凭证失败（不影响审核）', [
                'bill_type' => $billType,
                'bill_id' => $billId,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('业务单据自动生成凭证异常（不影响审核）', [
                'bill_type' => $billType,
                'bill_id' => $billId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
