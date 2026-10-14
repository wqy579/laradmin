<?php

namespace Modules\Ledger\Services;

use Illuminate\Support\Facades\Log;
use Modules\Ledger\Models\Voucher;

/**
 * 凭证生成服务：业务单据自动生成会计凭证的统一入口。
 *
 * 三机协同约定（任务书「六、三机协同与交付要求」）：
 *  - 机器 A 先建好本占位服务并在文档中给出调用签名；
 *  - 机器 B 在资金/往来/资产单据审核时以 try 方式调用 generateFromBusiness()；
 *  - 机器 A 未合并前 B 的调用必须能正常工作不报错（本类吞掉异常并记录日志）；
 *  - 待三机合并后，再安排联调把 B 的单据逐步接上「自动生成会计凭证」，
 *    这是三机都完成后的第四阶段任务，本期不做。
 *
 * 调用签名：generateFromBusiness(string $billType, int $billId, array $context = [])
 *   - $billType：业务单据类型，如 'receive'(收款) / 'pay'(付款) / 'expense'(费用单) /
 *     'deposit'(存取款) / 'loan'(借款) / 'asset_depreciation'(固定资产折旧) 等；
 *   - $billId：业务单据主键 id；
 *   - $context：业务单据携带的上下文（金额、科目提示、辅助核算对象等），由调用方装配。
 *
 * 返回：生成的凭证 Voucher，或未生成时返回 null（本期永远返回 null）。
 */
class VoucherGenerateService
{
    /**
     * 业务单据生成会计凭证（本期空实现 + 事件监听器占位）。
     *
     * 本方法体先留 TODO，不影响业务模块。三机合并后的第四阶段再逐步接通
     * 收款/付款/费用/存取款/借款/资产折旧等单据的自动凭证逻辑。
     */
    public function generateFromBusiness(string $billType, int $billId, array $context = []): ?Voucher
    {
        // TODO[第四阶段]: 按 $billType 分发到各自的凭证生成策略，
        //   把业务单据的借贷金额映射到会计科目，落成一张 ledger_vouchers。
        //
        // 已预留的事件位：业务模块可在审核时触发 VoucherGenerating 事件，
        // 这里监听并装配凭证；本期只记录调用日志，便于联调时定位哪些单据触发了。
        try {
            Log::info('VoucherGenerateService.generateFromBusiness called (stub)', [
                'bill_type' => $billType,
                'bill_id' => $billId,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响业务调用方
        }

        return null;
    }

    /**
     * 事件监听器占位：业务单据审核事件触发时回调。
     *
     * 机器 B 的单据审核逻辑里以 try 方式调用 generateFromBusiness 即可，
     * 无需自行注册事件监听；本方法保留给第四阶段联调时挂事件用。
     */
    public function onBusinessVoucherRequested(string $billType, int $billId, array $context = []): void
    {
        // TODO[第四阶段]: 注册为 BusinessVoucherRequested 事件的监听器
        $this->generateFromBusiness($billType, $billId, $context);
    }
}
