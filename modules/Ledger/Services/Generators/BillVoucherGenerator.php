<?php

namespace Modules\Ledger\Services\Generators;

/**
 * 业务单据 → 会计凭证的生成策略契约（QA-05）。
 *
 * 分工：策略类只负责「把一张业务单据读成借贷分录 + 期间 + 摘要」，
 * **一律不自己落库**——落库统一走 VoucherGenerateService → VoucherService::create()，
 * 以复用借贷平衡、末级科目、期间禁写、取号并发这些既有保护。
 *
 * 跨模块约束（tests/Unit/ArchitectureTest 白名单）：Ledger 不 import
 * Order/Stock/Business 的模型，业务单据一律用 DB::table 裸表只读，
 * 依赖方向保持「业务 → Ledger」单向（业务侧审核时调用 Ledger 服务）。
 */
interface BillVoucherGenerator
{
    /**
     * 来源单据类型，落 ledger_vouchers.source_type，也是幂等键的一部分。
     * 取值对齐 Voucher::SOURCE_*。
     */
    public function sourceType(): string;

    /**
     * 把单据装配成「待生成凭证载荷」列表，数组顺序即 source_seq（从 1 开始）。
     *
     * 一张单据可以产出一张或多张凭证（销售出库 = 收入凭证 + 成本凭证），
     * 每张都必须自身借贷平衡。返回空数组表示「本单据无需生成凭证」。
     *
     * @param  array<string, mixed>  $context  调用方上下文（制单人等）
     * @return array<int, array{voucher_date:string, remark:?string, entries:array<int, array<string,mixed>>}>
     *
     * @throws \DomainException 单据不存在、未审核，或口径不足以出凭证
     */
    public function payloads(int $billId, array $context = []): array;

    /**
     * 已审核但尚未生成凭证的单据（供批量命令与结账检查③使用）。
     *
     * @return array<int, array{id:int, bill_no:?string}>
     */
    public function pendingBills(): array;

    /**
     * 该类型已审核单据总数与其中已生成凭证的数量（结账检查③的真实统计）。
     *
     * @return array{audited:int, missing:int}
     */
    public function coverage(): array;
}
