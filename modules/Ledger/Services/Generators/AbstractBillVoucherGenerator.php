<?php

namespace Modules\Ledger\Services\Generators;

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Subject;

/**
 * 生成策略公共骨架：科目取数、分录工厂、幂等/覆盖率统计。
 *
 * 子类只需实现三件事：
 *  - sourceType()：来源单据类型；
 *  - buildPayloads()：把单据读成分录载荷；
 *  - auditedBills()：该类型「已审核」的裸表查询（状态语义三套不统一，各自判定）。
 */
abstract class AbstractBillVoucherGenerator implements BillVoucherGenerator
{
    /** 业务表名（裸表只读，不 import 其他模块的模型） */
    protected string $billTable = '';

    /** 单号列名，用于摘要与提示信息 */
    protected string $billNoColumn = '';

    /** 已审核状态的裸表列值：0/1 型状态表填 [1]，字符串状态表填 ['approved'] */
    protected array $auditedStatusValues = [1];

    /**
     * 派生单据的 remark 前缀黑名单（默认空）。
     *
     * 有些业务动作会「顺手」往 expenses/receives 里塞一张自动审核的派生单
     * （库存调整损耗、盘点盘亏/盘盈）。这些业务的凭证已由各自的生成器按正确口径
     * 出账，若再按「费用→现金流出 / 收款→应收」重复出一张，同一笔业务就记了两遍。
     * 派生单没有溯源列，只能按建单时写入的 remark 前缀识别。
     */
    protected array $excludedRemarkPrefixes = [];

    /**
     * 单据是否允许生成凭证的附加判定（默认无附加条件）。
     *
     * 例：销售出库要求「已发货/已完成」且金额 > 0。
     */
    protected function billEligible(object $bill): bool
    {
        return true;
    }

    /** remark 命中派生前缀 → 跳过（既不出凭证，也不算「漏生成」） */
    final protected function remarkExcluded(object $bill): bool
    {
        $remark = trim((string) ($bill->remark ?? ''));
        if ($remark === '') {
            return false;
        }

        foreach ($this->excludedRemarkPrefixes as $prefix) {
            if (str_starts_with($remark, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{id: int, bill_no: string|null}>
     */
    protected function auditedBills(): array
    {
        // 取整行而非只取 id：billEligible 要看金额等字段（如出库单金额 0 不出凭证）
        $rows = DB::table($this->billTable)
            ->whereIn('status', $this->auditedStatusValues)
            ->orderBy('id')
            ->get();

        $noCol = $this->billNoColumn;

        return $rows
            ->filter(fn (object $r) => ! $this->remarkExcluded($r))
            ->filter(fn (object $r) => $this->billEligible($r))
            ->map(fn (object $r) => ['id' => (int) $r->id, 'bill_no' => $noCol ? ($r->{$noCol} ?? null) : null])
            ->values()
            ->all();
    }

    final public function payloads(int $billId, array $context = []): array
    {
        $bill = DB::table($this->billTable)->where('id', $billId)->first();
        if (! $bill) {
            throw new \DomainException('未找到来源单据：'.$this->sourceType().'#'.$billId);
        }
        if (! in_array((string) $bill->status, array_map('strval', $this->auditedStatusValues), true)) {
            throw new \DomainException('单据未审核，不能生成凭证');
        }
        if ($this->remarkExcluded($bill)) {
            throw new \DomainException('该单据由其他业务动作派生，凭证已按源业务口径生成，不重复出账');
        }
        if (! $this->billEligible($bill)) {
            throw new \DomainException('单据状态或金额不满足生成凭证的条件');
        }

        return $this->buildPayloads($bill, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array{voucher_date:string, remark:?string, entries:array<int, array<string,mixed>>}>
     */
    abstract protected function buildPayloads(object $bill, array $context): array;

    final public function sourceType(): string
    {
        return $this->billType();
    }

    /** 子类提供的单据类型标识（与 Voucher::SOURCE_* 一致） */
    abstract protected function billType(): string;

    final public function pendingBills(): array
    {
        // 只要 seq=1 那张已生成就算这张单据出过凭证：销售出库的成本凭证（seq=2）
        // 允许因成本全为 0 而合法缺席，不能把它算成「漏生成」。
        $firstSeqGenerated = DB::table('ledger_vouchers')
            ->where('source_type', $this->billType())
            ->where('source_seq', 1)
            ->pluck('source_id')
            ->map(fn ($v) => (int) $v)
            ->flip();

        return array_values(array_filter(
            $this->auditedBills(),
            fn (array $b) => ! isset($firstSeqGenerated[$b['id']])
        ));
    }

    final public function coverage(): array
    {
        $bills = $this->auditedBills();
        $ids = array_column($bills, 'id');

        $generated = empty($ids)
            ? []
            : DB::table('ledger_vouchers')
                ->where('source_type', $this->billType())
                ->whereIn('source_id', $ids)
                ->where('source_seq', 1)
                ->pluck('source_id')
                ->map(fn ($v) => (int) $v)
                ->flip()
                ->all();

        return [
            'audited' => count($bills),
            'missing' => count(array_filter($ids, fn (int $id) => ! isset($generated[$id]))),
        ];
    }

    /**
     * 按标准科目编码取末级科目 id，缺失即抛错（不静默生成错账）。
     *
     * 科目编码来自 LedgerSubjectSeeder 的预置科目表。
     */
    final protected function subjectId(string $code): int
    {
        $subject = Subject::query()->where('code', $code)->where('is_leaf', true)->first();
        if (! $subject) {
            throw new \DomainException('未找到末级科目：'.$code.'，请先执行科目初始化');
        }

        return (int) $subject->id;
    }

    /**
     * 分录工厂。$amount 为 0 的行由 VoucherService::normalizeEntries 自动过滤，
     * 但生成侧仍应先判 >0，避免出现「整张凭证 0 金额」的无效单据。
     *
     * @param  string  $direction  debit / credit
     */
    final protected function entry(
        string $code,
        string $summary,
        float $amount,
        string $direction,
        ?string $auxType = null,
        ?int $auxId = null,
        ?string $auxName = null,
    ): array {
        $amount = round(abs($amount), 2);

        return [
            'subject_id' => $this->subjectId($code),
            'summary' => $summary,
            'debit_amount' => $direction === 'debit' ? $amount : 0,
            'credit_amount' => $direction === 'credit' ? $amount : 0,
            'aux_type' => $auxType,
            'aux_id' => $auxId,
            'aux_name' => $auxName,
        ];
    }

    /** 借现金还是借银行存款：支付方式含「现金」走 1001，其余（银行转账等）走 1002 */
    final protected function cashSubjectCode(?string $paymentMethod): string
    {
        return str_contains((string) $paymentMethod, '现金') ? '1001' : '1002';
    }

    /**
     * 凭证日期：取单据自身业务日期（出库/收款/付款/费用/调整日期）。
     *
     * 不拿今天兜底——单据日期决定进哪个会计期间，用今天会把上个月的单据塞进本月，
     * 报表期间口径就错了。缺日期/解析失败一律抛 DomainException，由业务侧 catch 成警告。
     * 期间本身由 VoucherService 从 voucher_date 推导（periodOfString）。
     */
    final protected function billDate(mixed $date, string $label): string
    {
        $raw = $date === null ? '' : trim((string) $date);
        if ($raw === '') {
            throw new \DomainException('单据缺少'.$label.'，无法确定会计期间');
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            throw new \DomainException('单据'.$label.'无法解析：'.$raw);
        }

        return date('Y-m-d', $ts);
    }

    /** 客户/供应商名称快照（裸表只读，取不到就不挂名字，不影响出凭证） */
    final protected function partyName(string $table, ?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return DB::table($table)->where('id', $id)->value('name');
    }
}
