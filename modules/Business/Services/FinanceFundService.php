<?php

namespace Modules\Business\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Services\VoucherService;

/**
 * B4 资金单族（6 类）统一服务：欠条确认 / 借款还款 / 资金增减 / 应收应付调整 /
 * 客户余额调整 / 供应商余额调整。
 *
 * 每张单据落 fin_fund_orders（单据即历史留痕），并按单据性质写 cash_flows 资金流水。
 * 调整类（应收应付 / 客户余额 / 供应商余额）在审核通过时经 VoucherService::create()
 * 记账凭证同步应收应付；余额不足时阻断（余额不足阻断）。
 */
class FinanceFundService
{
    public const TYPE_IOU = 'iou';

    public const TYPE_BORROW_REPAY = 'borrow_repay';

    public const TYPE_FUND_IO = 'fund_io';

    public const TYPE_ARAP_ADJUST = 'arap_adjust';

    public const TYPE_CUSTOMER_BALANCE = 'customer_balance';

    public const TYPE_SUPPLIER_BALANCE = 'supplier_balance';

    public const DOC_TYPE_MAP = [
        self::TYPE_IOU => '欠条确认',
        self::TYPE_BORROW_REPAY => '借款还款',
        self::TYPE_FUND_IO => '资金增减',
        self::TYPE_ARAP_ADJUST => '应收应付调整',
        self::TYPE_CUSTOMER_BALANCE => '客户余额调整',
        self::TYPE_SUPPLIER_BALANCE => '供应商余额调整',
    ];

    /** 调整类单据（需审核通过后同步应收应付） */
    public const ADJUST_TYPES = [
        self::TYPE_ARAP_ADJUST,
        self::TYPE_CUSTOMER_BALANCE,
        self::TYPE_SUPPLIER_BALANCE,
    ];

    /** 需写资金流水的单据类型（欠条/借款确认、资金增减生效即写） */
    private const CASH_FLOW_TYPES = [
        self::TYPE_BORROW_REPAY,
        self::TYPE_FUND_IO,
        self::TYPE_ARAP_ADJUST,
        self::TYPE_CUSTOMER_BALANCE,
        self::TYPE_SUPPLIER_BALANCE,
    ];

    public function __construct(
        private VoucherService $voucherService,
    ) {}

    // ---------------------------------------------------------------------
    // 列表 / 详情
    // ---------------------------------------------------------------------

    public function list(string $type, array $filters, int $page, int $pageSize): array
    {
        $query = DB::table('fin_fund_orders')->where('doc_type', $type);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['partner_id'])) {
            $query->where('partner_id', (int) $filters['partner_id']);
        }
        if (! empty($filters['keyword'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('doc_no', 'like', '%'.$filters['keyword'].'%')
                    ->orWhere('partner_name', 'like', '%'.$filters['keyword'].'%');
            });
        }
        if (! empty($filters['start_date'])) {
            $query->where('doc_date', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where('doc_date', '<=', $filters['end_date']);
        }
        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        $total = $query->count();
        $list = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $rows = $list->map(fn ($r) => $this->decorate($type, $r))->all();

        return [
            'list' => $rows,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => (int) ceil($total / max($pageSize, 1)),
        ];
    }

    public function detail(string $type, int $id): array
    {
        $row = DB::table('fin_fund_orders')->where('id', $id)->where('doc_type', $type)->first();
        if (! $row) {
            throw new DomainException('单据不存在');
        }

        return $this->decorate($type, $row, true);
    }

    // ---------------------------------------------------------------------
    // 创建
    // ---------------------------------------------------------------------

    public function create(string $type, array $data, int $makerId): array
    {
        $docNo = $this->nextDocNo($type);
        $amount = round((float) ($data['amount'] ?? 0), 2);

        return DB::transaction(function () use ($type, $data, $makerId, $docNo, $amount) {
            // 借款还款：借款方向金额为正，还款方向金额为负（用负额表示登记还款）
            $status = 'pending';
            if (in_array($type, [self::TYPE_BORROW_REPAY, self::TYPE_FUND_IO], true)) {
                $status = 'confirmed';
            }

            $row = [
                'doc_type' => $type,
                'doc_no' => $docNo,
                'partner_type' => $data['partner_type'] ?? $data['partnerType'] ?? null,
                'partner_id' => $data['partner_id'] ?? $data['partnerId'] ?? null,
                'partner_name' => $data['partner_name'] ?? $data['partnerName'] ?? null,
                'amount' => $amount,
                'original_amount' => $type === self::TYPE_BORROW_REPAY ? abs($amount) : null,
                'doc_date' => $data['doc_date'] ?? $data['date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? $data['dueDate'] ?? null,
                'status' => $status,
                'direction' => $data['direction'] ?? null,
                'account' => $data['account'] ?? null,
                'payment_method' => $data['payment_method'] ?? $data['paymentMethod'] ?? '现金',
                'reason' => $data['reason'] ?? null,
                'abstract' => $data['abstract'] ?? $data['remark'] ?? null,
                'signature_url' => $data['signature_url'] ?? $data['signatureUrl'] ?? null,
                'items' => isset($data['items']) ? json_encode($data['items']) : null,
                'created_by' => $makerId,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // 原因必填：调整类单据建单即校验
            if (in_array($type, self::ADJUST_TYPES, true) && empty($row['reason'])) {
                throw new DomainException('调整类单据必须填写调整原因');
            }

            // 调整单三段余额快照：期初/当前 = 建单时往来余额，调整后 = 期初 + 本次调整净额
            if (in_array($type, self::ADJUST_TYPES, true)) {
                $opening = $this->currentBalance((string) $row['partner_type'], (int) $row['partner_id']);
                $row['opening_balance'] = $opening;
                $row['current_balance'] = $opening;
                $row['adjusted_balance'] = round($opening + $this->adjustDelta($type, (object) $row), 2);
            }

            $id = DB::table('fin_fund_orders')->insertGetId($row);
            $row['id'] = $id;

            // 借款还款：登记还款方向，先做余额校验与拆借/还款流水
            if ($type === self::TYPE_BORROW_REPAY) {
                $this->handleBorrowRepayFlow((object) $row, $id);
            }

            // 生效即写资金流水（欠条确认/资金增减/调整类审核在确认/审核动作中写）
            if ($type === self::TYPE_FUND_IO) {
                $this->writeCashFlow((object) $row, $id);
            }

            // 回读整行：$row 只是插入载荷，不含 reject_reason/voucher_id/cash_flow_no
            // 以及非调整类单据的三段余额列
            return $this->decorate($type, DB::table('fin_fund_orders')->where('id', $id)->first(), true);
        });
    }

    // ---------------------------------------------------------------------
    // 确认 / 审核 / 驳回 / 还款
    // ---------------------------------------------------------------------

    /** 欠条确认 / 借款确认：确认后写资金流水 */
    public function confirm(string $type, int $id): array
    {
        return DB::transaction(function () use ($type, $id) {
            $row = DB::table('fin_fund_orders')->where('id', $id)->where('doc_type', $type)->lockForUpdate()->first();
            if (! $row) {
                throw new DomainException('单据不存在');
            }
            if ($row->status !== 'pending') {
                throw new DomainException('仅待确认单据可确认');
            }

            $this->writeCashFlow($row, (int) $row->id);
            DB::table('fin_fund_orders')->where('id', $id)->update(['status' => 'confirmed', 'updated_at' => now()]);

            return $this->decorate($type, DB::table('fin_fund_orders')->where('id', $id)->first(), true);
        });
    }

    /** 调整类审核通过：校验余额不足后同步应收应付 */
    public function approve(string $type, int $id, int $makerId): array
    {
        return DB::transaction(function () use ($type, $id, $makerId) {
            $row = DB::table('fin_fund_orders')->where('id', $id)->where('doc_type', $type)->lockForUpdate()->first();
            if (! $row) {
                throw new DomainException('单据不存在');
            }
            if ($row->status !== 'pending') {
                throw new DomainException('仅待审核单据可审核通过');
            }
            if (! in_array($type, self::ADJUST_TYPES, true)) {
                throw new DomainException('该单据类型无需审核');
            }

            $this->assertBalanceEnough($type, $row);
            $this->syncArApVoucher($type, $row, $makerId);
            $this->writeCashFlow($row, (int) $row->id);
            DB::table('fin_fund_orders')->where('id', $id)->update(['status' => 'approved', 'updated_at' => now()]);

            return $this->decorate($type, DB::table('fin_fund_orders')->where('id', $id)->first(), true);
        });
    }

    public function reject(string $type, int $id, string $reason): array
    {
        return DB::transaction(function () use ($type, $id, $reason) {
            $row = DB::table('fin_fund_orders')->where('id', $id)->where('doc_type', $type)->first();
            if (! $row) {
                throw new DomainException('单据不存在');
            }
            if ($row->status !== 'pending') {
                throw new DomainException('仅待审核单据可驳回');
            }
            if (empty($reason)) {
                throw new DomainException('请填写驳回原因');
            }

            DB::table('fin_fund_orders')->where('id', $id)->update([
                'status' => 'rejected',
                'reject_reason' => $reason,
                'updated_at' => now(),
            ]);

            return $this->decorate($type, DB::table('fin_fund_orders')->where('id', $id)->first(), true);
        });
    }

    /** 借款还款：登记还款（amount 为正值时按还款处理） */
    public function repay(string $type, int $id, array $data, int $makerId): array
    {
        if ($type !== self::TYPE_BORROW_REPAY) {
            throw new DomainException('仅借款还款单支持登记还款');
        }

        $repayAmount = round((float) ($data['amount'] ?? 0), 2);

        return DB::transaction(function () use ($id, $repayAmount, $data, $makerId) {
            $row = DB::table('fin_fund_orders')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->doc_type !== self::TYPE_BORROW_REPAY) {
                throw new DomainException('单据不存在');
            }
            if ($row->status === 'rejected' || $row->status === 'settled') {
                throw new DomainException('当前状态不可登记还款');
            }

            $paid = (float) $row->amount;
            if ($repayAmount <= 0) {
                throw new DomainException('还款金额必须大于 0');
            }
            if ($repayAmount > $paid) {
                throw new DomainException('还款金额不能超过未还余额');
            }

            // 更新未还余额（amount 存剩余未还），据此判定 未结清/部分偿还/已结清
            $remain = round($paid - $repayAmount, 2);
            $original = (float) ($row->original_amount ?: $paid);
            if ($remain <= 0) {
                $status = 'settled';
            } elseif ($remain < $original) {
                $status = 'partial';
            } else {
                $status = 'unsettled';
            }

            DB::table('fin_fund_orders')->where('id', $id)->update([
                'amount' => $remain,
                'status' => $status,
                'updated_at' => now(),
            ]);

            // 还款写资金流水（收方）
            $this->writeCashFlow((object) [
                'id' => $id,
                'doc_type' => self::TYPE_BORROW_REPAY,
                'doc_no' => $row->doc_no,
                'partner_type' => $row->partner_type,
                'partner_id' => $row->partner_id,
                'partner_name' => $row->partner_name,
                'amount' => -$repayAmount,
                'doc_date' => $data['doc_date'] ?? $data['date'] ?? now()->toDateString(),
                'payment_method' => $data['payment_method'] ?? $data['paymentMethod'] ?? '现金',
                'abstract' => $data['abstract'] ?? '借款还款',
                'created_by' => $makerId,
            ], $id);

            return $this->decorate(self::TYPE_BORROW_REPAY, DB::table('fin_fund_orders')->where('id', $id)->first(), true);
        });
    }

    // ---------------------------------------------------------------------
    // 余额校验 / 同步应收应付
    // ---------------------------------------------------------------------

    /** 余额不足阻断：调整类单据减少往来余额时，不得超过当前余额 */
    private function assertBalanceEnough(string $type, object $row): void
    {
        $amount = (float) $row->amount;
        // 只对减少方向（amount<0 或调整后余额低于当前）做余额校验
        if ($amount >= 0 && $this->isReductionByItems($row) === false) {
            return;
        }

        $reduction = $this->reductionAmount($type, $row);
        if ($reduction <= 0) {
            return;
        }

        $current = $this->currentBalance((string) $row->partner_type, (int) $row->partner_id);
        if ($current < $reduction) {
            throw new DomainException("往来余额不足：当前余额 {$current}，本次调减 {$reduction}");
        }
    }

    /**
     * 往来方当前余额：按已审（非红冲）凭证分录聚合，口径与余额查询一致
     * （借方科目取 借-贷，贷方科目取 贷-借）。
     */
    private function currentBalance(string $auxType, int $auxId): float
    {
        if ($auxId <= 0) {
            return 0.0;
        }

        $rows = DB::table('ledger_voucher_entries')
            ->join('ledger_vouchers', 'ledger_voucher_entries.voucher_id', '=', 'ledger_vouchers.id')
            ->where('ledger_vouchers.status', 'audited')
            ->whereNull('ledger_vouchers.red_of_id')
            ->where('ledger_voucher_entries.aux_type', $auxType)
            ->where('ledger_voucher_entries.aux_id', $auxId)
            ->get(['ledger_voucher_entries.subject_name', 'ledger_voucher_entries.debit_amount', 'ledger_voucher_entries.credit_amount']);

        $total = 0.0;
        foreach ($rows as $r) {
            $direction = $this->isCreditSubject((string) $r->subject_name) ? -1 : 1;
            $total += $direction * ((float) $r->debit_amount - (float) $r->credit_amount);
        }

        return round($total, 2);
    }

    /** 常见贷方余额科目：应付/预收/负债/权益/收入 */
    private function isCreditSubject(string $subjectName): bool
    {
        foreach (['应付', '预收', '应交', '主营业务收入'] as $needle) {
            if ($needle !== '' && str_contains($subjectName, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 调整单对往来余额的净影响（正=增，负=减）：
     * 借方科目借为正贷为负，贷方科目（应付/预收等）相反。
     */
    private function adjustDelta(string $type, object $row): float
    {
        if ($type !== self::TYPE_ARAP_ADJUST) {
            return (float) $row->amount;
        }

        $items = json_decode($row->items ?: '[]', true) ?: [];
        $delta = 0.0;
        foreach ($items as $item) {
            $amount = abs((float) ($item['amount'] ?? 0));
            $isDebit = ($item['direction'] ?? '') === '借';
            $sign = $this->isCreditSubject((string) ($item['subject'] ?? '')) ? -1 : 1;
            $delta += $sign * ($isDebit ? $amount : -$amount);
        }

        return round($delta, 2);
    }

    /** 调减金额：客户/供应商余额调整取负 amount 的绝对值；应收应付调整按明细借/贷方向判定 */
    private function reductionAmount(string $type, object $row): float
    {
        $amount = (float) $row->amount;
        if ($amount < 0) {
            return abs($amount);
        }

        // 应收应付调整：明细方向为贷（减少应收）或借（减少应付）时计入调减
        $items = json_decode($row->items ?: '[]', true) ?: [];
        $reduction = 0.0;
        foreach ($items as $item) {
            $subject = (string) ($item['subject'] ?? '');
            $direction = (string) ($item['direction'] ?? '');
            $val = (float) ($item['amount'] ?? 0);
            $isAr = str_contains($subject, '应收');
            $isAp = str_contains($subject, '应付');
            if (($isAr && $direction === '贷') || ($isAp && $direction === '借')) {
                $reduction += $val;
            }
        }

        return $reduction;
    }

    private function isReductionByItems(object $row): bool
    {
        $items = json_decode($row->items ?: '[]', true) ?: [];
        foreach ($items as $item) {
            $subject = (string) ($item['subject'] ?? '');
            $direction = (string) ($item['direction'] ?? '');
            if (str_contains($subject, '应收') && $direction === '贷') {
                return true;
            }
            if (str_contains($subject, '应付') && $direction === '借') {
                return true;
            }
        }

        return false;
    }

    /** 调账同步应收应付：经 VoucherService 记账凭证（借/贷平衡） */
    private function syncArApVoucher(string $type, object $row, int $makerId): void
    {
        $entries = [];
        if ($type === self::TYPE_ARAP_ADJUST) {
            $items = json_decode($row->items ?: '[]', true) ?: [];
            foreach ($items as $item) {
                $entry = $this->ledgerEntry($item, $row);
                if ($entry) {
                    $entries[] = $entry;
                }
            }
        } else {
            // 客户/供应商余额调整：默认借记/贷记应收应付 + 费用/收入冲销
            $subjectCode = $type === self::TYPE_SUPPLIER_BALANCE ? '2202' : '1122';
            $amount = abs((float) $row->amount);
            $isIncrease = (float) $row->amount >= 0;
            $counterCode = $this->counterSubject($type);
            if ($isIncrease) {
                // 增加余额：借记往来科目
                $entries[] = $this->entryFromCode($subjectCode, $row, $amount, 'debit');
                $entries[] = $this->entryFromCode($counterCode, $row, $amount, 'credit');
            } else {
                // 调减余额：贷记往来科目
                $entries[] = $this->entryFromCode($subjectCode, $row, $amount, 'credit');
                $entries[] = $this->entryFromCode($counterCode, $row, $amount, 'debit');
            }
        }

        if (empty($entries)) {
            throw new DomainException('调整单缺少有效分录');
        }

        $voucher = $this->voucherService->create([
            'voucher_date' => $row->doc_date,
            'status' => 'audited',
            'auto_audit' => true,
            'remark' => '资金单族调账：'.$row->doc_no,
            'entries' => $entries,
        ], $makerId, '资金单族调账');

        DB::table('fin_fund_orders')->where('id', $row->id)->update([
            'voucher_id' => $voucher->id,
            'updated_at' => now(),
        ]);
    }

    private function ledgerEntry(array $item, object $row): ?array
    {
        $code = $item['subject_code'] ?? null;
        $name = $item['subject'] ?? null;
        if (! $code) {
            return null;
        }
        $subject = Subject::where('code', $code)->where('is_leaf', true)->first();
        if (! $subject) {
            $subject = Subject::where('name', $name)->where('is_leaf', true)->first();
        }
        if (! $subject) {
            throw new DomainException('未找到末级科目：'.($name ?: $code));
        }

        $direction = ($item['direction'] ?? '') === '借' ? 'debit' : 'credit';
        $amount = abs((float) ($item['amount'] ?? 0));

        return [
            'subject_id' => $subject->id,
            'summary' => $item['abstract'] ?? ('资金单族调账：'.$row->doc_no),
            'debit_amount' => $direction === 'debit' ? $amount : 0,
            'credit_amount' => $direction === 'credit' ? $amount : 0,
            'aux_type' => $row->partner_type,
            'aux_id' => $row->partner_id,
            'aux_name' => $row->partner_name,
        ];
    }

    private function entryFromCode(string $code, object $row, float $amount, string $direction): array
    {
        $subject = Subject::where('code', $code)->where('is_leaf', true)->first();
        if (! $subject) {
            throw new DomainException('未找到末级科目：'.$code);
        }

        return [
            'subject_id' => $subject->id,
            'summary' => '资金单族调账：'.$row->doc_no,
            'debit_amount' => $direction === 'debit' ? $amount : 0,
            'credit_amount' => $direction === 'credit' ? $amount : 0,
            'aux_type' => $row->partner_type,
            'aux_id' => $row->partner_id,
            'aux_name' => $row->partner_name,
        ];
    }

    /** 冲销科目：调减应收用坏账准备/管理费用，调减应付用营业外收入；增加则相反 */
    private function counterSubject(string $type): string
    {
        // 简化：一律以 管理费用 作对方科目（可后续按科目字典扩展）
        return '6602';
    }

    // ---------------------------------------------------------------------
    // 资金流水
    // ---------------------------------------------------------------------

    private function handleBorrowRepayFlow(object $row, int $id): void
    {
        // 借款方向（amount>0）建单即生效并写资金流水；还款方向走 repay()
        if ((float) $row->amount > 0) {
            $this->writeCashFlow($row, $id);
        }
    }

    private function writeCashFlow(object $row, int $id): void
    {
        $amount = round((float) $row->amount, 2);

        // 欠条（iou）不写资金流水，仅留痕
        if (! in_array($row->doc_type, self::CASH_FLOW_TYPES, true)) {
            return;
        }

        $flowNo = 'CF'.date('YmdHis').strtoupper(Str::random(4));
        $flowType = $amount >= 0 ? 'receive' : 'pay';
        $customerId = $row->partner_type === 'customer' ? $row->partner_id : null;
        $supplierId = $row->partner_type === 'supplier' ? $row->partner_id : null;

        DB::table('cash_flows')->insert([
            'flow_no' => $flowNo,
            'flow_type' => $flowType,
            'customer_id' => $customerId,
            'supplier_id' => $supplierId,
            'related_id' => $id,
            'related_type' => 'FinFundOrder',
            'flow_date' => $row->doc_date,
            'amount' => $amount,
            'payment_method' => $row->payment_method ?? '现金',
            'remark' => $row->abstract ?: (self::DOC_TYPE_MAP[$row->doc_type] ?? '资金单'),
            'created_by' => $row->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fin_fund_orders')->where('id', $id)->update([
            'cash_flow_no' => $flowNo,
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // 编号 / 装饰
    // ---------------------------------------------------------------------

    private function nextDocNo(string $type): string
    {
        $prefix = $this->docPrefix($type);
        $date = date('Ymd');

        return $prefix.$date.$this->randNo($type);
    }

    private function docPrefix(string $type): string
    {
        return match ($type) {
            self::TYPE_IOU => 'IOU',
            self::TYPE_BORROW_REPAY => 'BR',
            self::TYPE_FUND_IO => 'FI',
            self::TYPE_ARAP_ADJUST => 'AA',
            self::TYPE_CUSTOMER_BALANCE => 'CBA',
            self::TYPE_SUPPLIER_BALANCE => 'SBA',
            default => 'FD',
        };
    }

    private function randNo(string $type): string
    {
        $n = 1;
        while ($n < 10000) {
            // 简单碰撞规避：追加随机
            $candidate = $this->docPrefix($type).date('Ymd').strtoupper(Str::random(6));
            if (! DB::table('fin_fund_orders')->where('doc_no', $candidate)->exists()) {
                return substr($candidate, strlen($this->docPrefix($type)));
            }
            $n++;
        }

        throw new DomainException('单据编号生成失败，请重试');
    }

    private function decorate(string $type, object $row, bool $detail = false): array
    {
        $items = $row->items ? json_decode($row->items, true) : null;

        return [
            'id' => (int) $row->id,
            'doc_type' => $row->doc_type,
            'doc_type_label' => self::DOC_TYPE_MAP[$row->doc_type] ?? $row->doc_type,
            'no' => $row->doc_no,
            'doc_no' => $row->doc_no,
            'partner_type' => $row->partner_type,
            'partner_id' => $row->partner_id ? (int) $row->partner_id : null,
            'partner' => $row->partner_name,
            'partner_name' => $row->partner_name,
            'amount' => (float) $row->amount,
            'date' => $row->doc_date,
            'doc_date' => $row->doc_date,
            'due_date' => $row->due_date,
            'status' => $row->status,
            'direction' => $row->direction,
            'account' => $row->account,
            'payment_method' => $row->payment_method,
            'opening_balance' => $row->opening_balance !== null ? (float) $row->opening_balance : null,
            'current_balance' => $row->current_balance !== null ? (float) $row->current_balance : null,
            'adjusted_balance' => $row->adjusted_balance !== null ? (float) $row->adjusted_balance : null,
            'reason' => $row->reason,
            'abstract' => $row->abstract,
            'reject_reason' => $row->reject_reason,
            'signature_url' => $row->signature_url,
            'items' => $items,
            'voucher_id' => $row->voucher_id ? (int) $row->voucher_id : null,
            'cash_flow_no' => $row->cash_flow_no,
            'created_by' => $row->created_by ? (int) $row->created_by : null,
            'created_at' => $row->created_at,
            // 借款还款计算展示字段
            'paid' => $this->paidOf($type, $row),
            'unpaid' => $this->unpaidOf($type, $row),
            'is_overdue' => $this->isOverdue($type, $row),
        ];
    }

    private function paidOf(string $type, object $row): float
    {
        if ($type !== self::TYPE_BORROW_REPAY) {
            return 0.0;
        }

        // amount 存剩余未还；原始借款额从历史流水合计
        $paid = DB::table('cash_flows')
            ->where('related_id', $row->id)
            ->where('related_type', 'FinFundOrder')
            ->where('amount', '<', 0)
            ->sum('amount');

        return round(abs((float) $paid), 2);
    }

    private function unpaidOf(string $type, object $row): float
    {
        if ($type !== self::TYPE_BORROW_REPAY) {
            return 0.0;
        }

        return round((float) $row->amount, 2);
    }

    private function isOverdue(string $type, object $row): bool
    {
        if ($type !== self::TYPE_BORROW_REPAY || ! $row->due_date) {
            return false;
        }

        return (float) $row->amount > 0
            && $row->status !== 'settled'
            && $row->due_date < now()->toDateString();
    }
}
