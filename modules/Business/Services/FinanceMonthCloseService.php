<?php

namespace Modules\Business\Services;

use Modules\Ledger\Models\Period;
use Modules\Ledger\Services\PeriodService;

/**
 * 财务月结（B2）—— 月结向导 + 月结查询
 *
 * 只读复用 ledger 的 PeriodService 既有月结逻辑（期间列表 / 结账检查 / 月结 / 反结账），
 * 不在 Business 侧重写结账逻辑。
 */
class FinanceMonthCloseService
{
    public function __construct(private PeriodService $periodService) {}

    /** 会计期间列表（按年度折叠） */
    public function periods(): array
    {
        return $this->periodService->list();
    }

    /** 结账前检查 */
    public function check(string $period): array
    {
        return $this->periodService->check($period);
    }

    /** 一键月结 */
    public function close(string $period, int $userId, string $userName): Period
    {
        return $this->periodService->close($period, $userId, $userName);
    }

    /** 反结账 */
    public function reopen(string $period): Period
    {
        return $this->periodService->reopen($period);
    }
}