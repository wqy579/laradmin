<?php

namespace Modules\Ledger\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Ledger\Models\Period;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Models\VoucherEntry;
use Modules\Ledger\Models\VoucherTemplate;

/**
 * 记账凭证业务逻辑：编号生成、借贷平衡校验、审核/反审核/作废、明细账。
 *
 * 严格业务规则：
 *  - 一张凭证借贷必须相等才能提交审核；
 *  - 至少两条有效分录；
 *  - 每条分录必须选末级科目；
 *  - 借贷都为 0 的空行自动过滤；
 *  - 红字冲销用负数金额录入并允许；
 *  - 凭证编号在同一会计期间内连续不断号，作废保留编号不回收。
 */
class VoucherService
{
    public function __construct(private SubjectService $subjectService) {}

    /** 分页筛选列表 */
    public function list(array $filters, int $page, int $pageSize): array
    {
        $q = Voucher::query()->with(['entries', 'reversal:id,voucher_no']);

        if (! empty($filters['voucher_no'])) {
            $q->where('voucher_no', 'like', '%'.trim($filters['voucher_no']).'%');
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        // 默认隐藏红冲单本身；勾选「含红冲」才同时列出红冲单与原单
        if (empty($filters['include_red']) && Schema::hasColumn('ledger_vouchers', 'red_of_id')) {
            $q->whereNull('red_of_id');
        }
        if (! empty($filters['start_date'])) {
            $q->whereDate('voucher_date', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $q->whereDate('voucher_date', '<=', $filters['end_date']);
        }
        if (! empty($filters['period'])) {
            $q->where('period', $filters['period']);
        }
        if (! empty($filters['subject_id'])) {
            $q->whereHas('entries', function ($e) use ($filters) {
                $e->where('subject_id', $filters['subject_id']);
            });
        }
        if (! empty($filters['maker_name'])) {
            $q->where('maker_name', 'like', '%'.trim($filters['maker_name']).'%');
        }
        if (! empty($filters['auditor_name'])) {
            $q->where('auditor_name', 'like', '%'.trim($filters['auditor_name']).'%');
        }
        if (isset($filters['min_amount']) && $filters['min_amount'] !== '' && $filters['min_amount'] !== null) {
            $q->where('total_debit', '>=', (float) $filters['min_amount']);
        }
        if (isset($filters['max_amount']) && $filters['max_amount'] !== '' && $filters['max_amount'] !== null) {
            $q->where('total_debit', '<=', (float) $filters['max_amount']);
        }

        $q->orderByDesc('voucher_date')->orderByDesc('id');
        $paginator = $q->paginate($pageSize, ['*'], 'page', $page);
        $list = $paginator->getCollection()->map(fn ($v) => $this->formatVoucher($v))->all();

        return [
            'list' => $list,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function detail(int $id): array
    {
        $voucher = Voucher::with(['entries.subject', 'reversal:id,voucher_no'])->findOrFail($id);

        return $this->formatVoucher($voucher, true);
    }

    /** 取号并发冲突后的重试次数（见 create() 注释） */
    private const CREATE_RETRY_TIMES = 3;

    /**
     * 新建凭证（含分录）。
     *
     * $data 可带来源单据三元组 source_type / source_id / source_seq（QA-05），
     * 用于「一张业务单据生成了哪几张凭证」的溯源与幂等。
     *
     * 并发保护：凭证号由 nextVoucherNo() 的「查最大号 + 1」得出，不在任何锁里，
     * 业务单据批量生成（QA-05 审核/命令行批量）会并发落到同一个号上。号与落库又
     * 不可能完全重合在一个原子操作里（取号要在事务内、但 MySQL 不加锁读不到未提交行），
     * 所以这里用 voucher_no 的唯一索引兜底：撞号抛 QueryException 后重新取号重试，
     * 而不是引入 SELECT FOR UPDATE（会给整段事务加区间锁，批量生成反而更容易互相等待）。
     */
    public function create(array $data, int $makerId, string $makerName): Voucher
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->createInTransaction($data, $makerId, $makerName);
            } catch (UniqueConstraintViolationException $e) {
                // 同一单据被并发重复触发生成：不报错，把已存在的那张返回给调用方
                if ($this->isSourceDuplicate($e) && ! empty($data['source_type']) && ! empty($data['source_id'])) {
                    $existing = Voucher::findBySource(
                        (string) $data['source_type'],
                        (int) $data['source_id'],
                        (int) ($data['source_seq'] ?? 1)
                    );
                    if ($existing) {
                        return $existing->load('entries');
                    }
                }

                if (! $this->isVoucherNoDuplicate($e) || $attempt >= self::CREATE_RETRY_TIMES) {
                    throw $e;
                }
                // 错峰重试，避免两个进程反复取到同一个号
                usleep(random_int(10_000, 80_000));
            }
        }
    }

    private function createInTransaction(array $data, int $makerId, string $makerName): Voucher
    {
        return DB::transaction(function () use ($data, $makerId, $makerName) {
            $entries = $this->normalizeEntries($data['entries'] ?? []);
            $this->validateEntries($entries);

            $voucherDate = $data['voucher_date'] ?? now()->toDateString();
            $period = $this->periodOfString($voucherDate);

            // 已月结的期间禁止新增凭证
            $this->ensurePeriodOpen($period);

            $totalDebit = round(array_sum(array_column($entries, 'debit_amount')), 2);
            $totalCredit = round(array_sum(array_column($entries, 'credit_amount')), 2);
            $this->ensureBalanced($totalDebit, $totalCredit);

            $voucher = new Voucher;
            $voucher->voucher_no = $this->nextVoucherNo($period);
            $voucher->voucher_date = $voucherDate;
            $voucher->period = $period;
            $voucher->attachments_count = (int) ($data['attachments_count'] ?? 0);
            $voucher->attachments = $data['attachments'] ?? [];
            $voucher->total_debit = $totalDebit;
            $voucher->total_credit = $totalCredit;
            $voucher->status = $data['status'] ?? Voucher::STATUS_DRAFT;
            $voucher->maker_id = $makerId;
            $voucher->maker_name = $makerName;
            $voucher->remark = $data['remark'] ?? null;
            $voucher->auto_audit = (bool) ($data['auto_audit'] ?? false);
            // 来源单据三元组：业务单据生成凭证的溯源与幂等凭据（手工凭证保持 NULL/1）
            $voucher->source_type = $data['source_type'] ?? null;
            $voucher->source_id = ! empty($data['source_id']) ? (int) $data['source_id'] : null;
            $voucher->source_seq = (int) ($data['source_seq'] ?? 1);
            $voucher->save();

            // 录单时自动审核：制单人自审入账（等同审核动作，平衡校验与期间校验已在上方完成）
            if (($data['status'] ?? null) === Voucher::STATUS_AUDITED) {
                $voucher->status = Voucher::STATUS_AUDITED;
                $voucher->auditor_id = $makerId;
                $voucher->auditor_name = $makerName;
                $voucher->audited_at = now();
                $voucher->save();
            }

            $this->saveEntries($voucher, $entries);

            return $voucher->fresh('entries');
        });
    }

    /** 编辑凭证（草稿/待审核可改，已审核/已作废不可改；期间月结则一律禁改） */
    public function update(int $id, array $data): Voucher
    {
        return DB::transaction(function () use ($id, $data) {
            $voucher = Voucher::with('entries')->findOrFail($id);
            // 期间月结优先拦截：月结后任何状态都不能改
            $this->ensurePeriodOpen($voucher->period);
            if (in_array($voucher->status, [Voucher::STATUS_AUDITED, Voucher::STATUS_REVERSED])) {
                throw new \DomainException('已审核或已作废的凭证不能修改');
            }

            // 无 entries 时只更新头信息，保留原分录
            $hasEntries = array_key_exists('entries', $data) && is_array($data['entries']);
            $entries = $hasEntries ? $this->normalizeEntries($data['entries']) : [];
            if ($hasEntries) {
                $this->validateEntries($entries);
            }

            if (isset($data['voucher_date'])) {
                $period = $this->periodOfString($data['voucher_date']);
                $this->ensurePeriodOpen($period);
                $voucher->voucher_date = $data['voucher_date'];
                $voucher->period = $period;
            }

            if ($hasEntries) {
                $totalDebit = round(array_sum(array_column($entries, 'debit_amount')), 2);
                $totalCredit = round(array_sum(array_column($entries, 'credit_amount')), 2);
                $this->ensureBalanced($totalDebit, $totalCredit);
                $voucher->total_debit = $totalDebit;
                $voucher->total_credit = $totalCredit;
            }
            $voucher->attachments_count = (int) ($data['attachments_count'] ?? $voucher->attachments_count);
            if (array_key_exists('attachments', $data)) {
                $voucher->attachments = is_array($data['attachments']) ? $data['attachments'] : [];
            }
            $voucher->remark = $data['remark'] ?? $voucher->remark;

            // 提交审核时校验平衡后转 submitted
            if (($data['status'] ?? null) === Voucher::STATUS_SUBMITTED) {
                $this->ensureBalanced((float) $voucher->total_debit, (float) $voucher->total_credit);
                $voucher->status = Voucher::STATUS_SUBMITTED;
            }

            $voucher->save();

            // 有 entries 才重建分录，否则保留原分录
            if ($hasEntries) {
                VoucherEntry::where('voucher_id', $voucher->id)->delete();
                $this->saveEntries($voucher, $entries);
            }

            return $voucher->fresh('entries');
        });
    }

    /** 审核：单条或批量 */
    public function audit(array $ids, int $auditorId, string $auditorName): array
    {
        $results = ['success' => 0, 'failed' => []];
        foreach ($ids as $id) {
            try {
                $this->auditOne((int) $id, $auditorId, $auditorName);
                $results['success']++;
            } catch (\Throwable $e) {
                $results['failed'][] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function auditOne(int $id, int $auditorId, string $auditorName): Voucher
    {
        return DB::transaction(function () use ($id, $auditorId, $auditorName) {
            $voucher = Voucher::with('entries')->findOrFail($id);
            if ($voucher->status === Voucher::STATUS_AUDITED) {
                throw new \DomainException('凭证已审核，不能重复审核');
            }
            if ($voucher->status === Voucher::STATUS_REVERSED) {
                throw new \DomainException('已作废的凭证不能审核');
            }

            // 再校验一次平衡（草稿直接审核也允许）
            $this->ensureBalanced((float) $voucher->total_debit, (float) $voucher->total_credit);

            // 期间未月结
            $this->ensurePeriodOpen($voucher->period);

            $voucher->status = Voucher::STATUS_AUDITED;
            $voucher->auditor_id = $auditorId;
            $voucher->auditor_name = $auditorName;
            $voucher->audited_at = now();
            $voucher->save();

            return $voucher->fresh('entries');
        });
    }

    /** 反审核：需权限，且该凭证所在期间未月结、未被结转 */
    public function reverseAudit(int $id): Voucher
    {
        return DB::transaction(function () use ($id) {
            $voucher = Voucher::findOrFail($id);
            if ($voucher->status !== Voucher::STATUS_AUDITED) {
                throw new \DomainException('只有已审核的凭证才能反审核');
            }
            $this->ensurePeriodOpen($voucher->period);

            $voucher->status = Voucher::STATUS_SUBMITTED;
            $voucher->auditor_id = null;
            $voucher->auditor_name = null;
            $voucher->audited_at = null;
            $voucher->save();

            return $voucher->fresh();
        });
    }

    /**
     * 红冲（红字冲销）：仅已审核凭证可红冲，生成一张借贷对调的新凭证。
     *
     * - 新凭证：借贷金额对调，摘要前缀「(红冲){原凭证号}」，红冲单本身 status=audited；
     * - 原凭证：status 不变，is_red 置真（列表据此显示「已红冲」Tag）；
     * - 期间已月结则禁止红冲（与增删改/审核/反审核/作废同一拦截口径）。
     */
    public function redReverse(int $id, int $operatorId, string $operatorName): Voucher
    {
        return DB::transaction(function () use ($id, $operatorId, $operatorName) {
            $voucher = Voucher::with('entries')->findOrFail($id);

            if ($voucher->status !== Voucher::STATUS_AUDITED) {
                throw new \DomainException('只有已审核的凭证才能红冲');
            }
            if ($voucher->is_red) {
                throw new \DomainException('该凭证已被红冲，不可重复红冲');
            }
            $this->ensurePeriodOpen($voucher->period);

            // 借贷对调后的分录：subject/aux/brand 全部保留，金额借↔贷互换
            $reversedEntries = $voucher->entries->map(function ($e) use ($voucher) {
                return [
                    'subject_id' => $e->subject_id,
                    'subject_code' => $e->subject_code,
                    'subject_name' => $e->subject_name,
                    'aux_type' => $e->aux_type,
                    'aux_id' => $e->aux_id,
                    'aux_name' => $e->aux_name,
                    'brand_id' => $e->brand_id,
                    'brand_name' => $e->brand_name,
                    'summary' => '(红冲)'.$voucher->voucher_no.' '.($e->summary ?? ''),
                    'debit_amount' => round((float) $e->credit_amount, 2),
                    'credit_amount' => round((float) $e->debit_amount, 2),
                ];
            })->all();

            $totalDebit = round(array_sum(array_column($reversedEntries, 'debit_amount')), 2);
            $totalCredit = round(array_sum(array_column($reversedEntries, 'credit_amount')), 2);
            $this->ensureBalanced($totalDebit, $totalCredit);

            $newVoucher = new Voucher;
            $newVoucher->voucher_no = $this->nextVoucherNo($voucher->period);
            $newVoucher->voucher_date = $voucher->voucher_date;
            $newVoucher->period = $voucher->period;
            $newVoucher->attachments_count = (int) $voucher->attachments_count;
            $newVoucher->attachments = $voucher->attachments ?? [];
            $newVoucher->total_debit = $totalDebit;
            $newVoucher->total_credit = $totalCredit;
            $newVoucher->status = Voucher::STATUS_AUDITED;
            $newVoucher->maker_id = $operatorId;
            $newVoucher->maker_name = $operatorName;
            $newVoucher->auditor_id = $operatorId;
            $newVoucher->auditor_name = $operatorName;
            $newVoucher->audited_at = now();
            $newVoucher->remark = '红冲 '.$voucher->voucher_no;
            $newVoucher->red_of_id = $voucher->id;
            $newVoucher->is_red = false;
            $newVoucher->auto_audit = false;
            $newVoucher->save();

            $this->saveEntries($newVoucher, $reversedEntries);

            $voucher->is_red = true;
            $voucher->save();

            return $newVoucher->fresh('entries');
        });
    }

    /** 作废：保留编号不回收，显示作废红章 */
    public function reverse(int $id): Voucher
    {
        return DB::transaction(function () use ($id) {
            $voucher = Voucher::findOrFail($id);
            if ($voucher->status === Voucher::STATUS_REVERSED) {
                throw new \DomainException('凭证已作废');
            }
            $this->ensurePeriodOpen($voucher->period);

            $voucher->status = Voucher::STATUS_REVERSED;
            $voucher->save();

            return $voucher->fresh();
        });
    }

    /** 删除：仅草稿可删，已审核/已作废不可删 */
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $voucher = Voucher::findOrFail($id);
            if (! in_array($voucher->status, [Voucher::STATUS_DRAFT, Voucher::STATUS_SUBMITTED])) {
                throw new \DomainException('已审核或已作废的凭证不能删除');
            }
            $this->ensurePeriodOpen($voucher->period);
            VoucherEntry::where('voucher_id', $voucher->id)->delete();
            $voucher->delete();
        });
    }

    /**
     * 唯一冲突是否落在 voucher_no 上（并发取号撞车，可重试）。
     *
     * 驱动不同报错文本不同（MySQL 'Duplicate entry ... for key ...voucher_no'，
     * sqlite 'UNIQUE constraint failed: ledger_vouchers.voucher_no'），
     * 统一按冲突列名判断，避免把别的唯一键冲突当成取号冲突反复重试。
     */
    private function isVoucherNoDuplicate(\Throwable $e): bool
    {
        return $this->duplicateHits($e, 'voucher_no');
    }

    /** 唯一冲突是否落在来源单据三元组上（同一单据被并发生成，属幂等而非错误） */
    private function isSourceDuplicate(\Throwable $e): bool
    {
        return $this->duplicateHits($e, 'source_type')
            || $this->duplicateHits($e, 'source_id')
            || $this->duplicateHits($e, 'ledger_vouchers_source_unique');
    }

    private function duplicateHits(\Throwable $e, string $column): bool
    {
        return $e instanceof UniqueConstraintViolationException
            && str_contains(strtolower($e->getMessage()), strtolower($column));
    }

    /** 凭证编号：记-年-月-4位流水，期间内连续不断号 */
    public function nextVoucherNo(string $period): string
    {
        [$year, $month] = explode('-', $period);
        $prefix = sprintf('记-%s-%s-', $year, $month);
        $last = Voucher::where('voucher_no', 'like', $prefix.'%')
            ->orderByDesc('voucher_no')
            ->first();
        $seq = 1;
        if ($last) {
            $tail = substr($last->voucher_no, strlen($prefix));
            $seq = (int) $tail + 1;
        }

        return $prefix.sprintf('%04d', $seq);
    }

    /** 明细账（三栏式）：某科目某期间的逐笔发生 */
    public function subjectLedger(int $subjectId, string $period): array
    {
        $subject = Subject::findOrFail($subjectId);
        $entries = VoucherEntry::with('voucher')
            ->where('subject_id', $subjectId)
            ->whereHas('voucher', function ($q) use ($period) {
                $q->where('period', $period)->where('status', Voucher::STATUS_AUDITED);
            })
            ->orderBy('line_no')
            ->get();

        // 期初余额
        $opening = DB::table('ledger_opening_balances')
            ->where('subject_id', $subjectId)
            ->where('period', $period)
            ->first();
        $openingDebit = (float) ($opening?->opening_debit ?? 0) + (float) ($opening?->year_debit ?? 0);
        $openingCredit = (float) ($opening?->opening_credit ?? 0) + (float) ($opening?->year_credit ?? 0);

        $direction = $subject->balance_direction;
        $openingBalance = $direction === Subject::DIRECTION_DEBIT
            ? $openingDebit - $openingCredit
            : $openingCredit - $openingDebit;

        $rows = [];
        $running = $openingBalance;
        $periodDebit = 0.0;
        $periodCredit = 0.0;

        // 对方科目一次性批量取回：两条 whereIn 查询覆盖所有相关凭证，
        // 避免逐分录/逐凭证查库（N+1）。
        // 与原逻辑等价：借方分录 → 同凭证贷方科目名；贷方分录 → 同凭证借方科目名。
        $voucherIds = $entries->pluck('voucher_id')->unique()->all();
        $creditSubjectsByVoucher = VoucherEntry::whereIn('voucher_id', $voucherIds)
            ->where('credit_amount', '>', 0)
            ->get(['voucher_id', 'subject_name'])
            ->groupBy('voucher_id')
            ->map(fn ($rows) => $rows->pluck('subject_name')->implode('、'))
            ->all();
        $debitSubjectsByVoucher = VoucherEntry::whereIn('voucher_id', $voucherIds)
            ->where('debit_amount', '>', 0)
            ->get(['voucher_id', 'subject_name'])
            ->groupBy('voucher_id')
            ->map(fn ($rows) => $rows->pluck('subject_name')->implode('、'))
            ->all();

        $rows[] = [
            'date' => null,
            'voucher_no' => null,
            'summary' => '期初余额',
            'opposite_subject' => '',
            'debit' => 0,
            'credit' => 0,
            'direction' => $direction === Subject::DIRECTION_DEBIT ? '借' : '贷',
            'balance' => round($running, 2),
        ];

        foreach ($entries as $entry) {
            $debit = (float) $entry->debit_amount;
            $credit = (float) $entry->credit_amount;
            $periodDebit += $debit;
            $periodCredit += $credit;
            $running = $direction === Subject::DIRECTION_DEBIT
                ? $running + $debit - $credit
                : $running + $credit - $debit;

            // 对方科目：同一凭证里相反方向的分录（已批量预取，避免 N+1）
            $opposite = $debit > 0
                ? ($creditSubjectsByVoucher[$entry->voucher_id] ?? '')
                : ($debitSubjectsByVoucher[$entry->voucher_id] ?? '');

            $rows[] = [
                'date' => $entry->voucher?->voucher_date?->format('Y-m-d'),
                'voucher_no' => $entry->voucher?->voucher_no,
                'summary' => $entry->summary,
                'opposite_subject' => $opposite,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'direction' => $running >= 0 ? ($direction === Subject::DIRECTION_DEBIT ? '借' : '贷') : ($direction === Subject::DIRECTION_DEBIT ? '贷' : '借'),
                'balance' => round(abs($running), 2),
            ];
        }

        // 本期合计
        $rows[] = [
            'date' => null,
            'voucher_no' => null,
            'summary' => '本期合计',
            'opposite_subject' => '',
            'debit' => round($periodDebit, 2),
            'credit' => round($periodCredit, 2),
            'direction' => '',
            'balance' => round(abs($running), 2),
        ];

        return [
            'subject' => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'balance_direction' => $subject->balance_direction,
                'category' => $subject->category,
            ],
            'period' => $period,
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($running, 2),
            'rows' => $rows,
        ];
    }

    /** 保存模板 */
    public function saveTemplate(string $name, array $entries, int $creatorId, string $creatorName): VoucherTemplate
    {
        $template = new VoucherTemplate;
        $template->name = $name;
        $template->template_data = $this->stripTransient($entries);
        $template->creator_id = $creatorId;
        $template->creator_name = $creatorName;
        $template->save();

        return $template;
    }

    public function templates(): array
    {
        return VoucherTemplate::orderByDesc('id')->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'template_data' => $t->template_data,
            'creator_name' => $t->creator_name,
            'created_at' => $t->created_at?->format('Y-m-d H:i:s'),
        ])->all();
    }

    // ===== 内部方法 =====

    /** 去掉分录里的临时字段，只留模板可复用的部分 */
    private function stripTransient(array $entries): array
    {
        return array_map(function ($e) {
            return [
                'subject_id' => $e['subject_id'] ?? null,
                'subject_code' => $e['subject_code'] ?? null,
                'subject_name' => $e['subject_name'] ?? null,
                'aux_type' => $e['aux_type'] ?? null,
                'brand_id' => isset($e['brand_id']) ? (int) $e['brand_id'] : null,
                'brand_name' => $e['brand_name'] ?? null,
                'summary' => $e['summary'] ?? null,
                'debit_amount' => (float) ($e['debit_amount'] ?? 0),
                'credit_amount' => (float) ($e['credit_amount'] ?? 0),
            ];
        }, $entries);
    }

    private function normalizeEntries(array $rawEntries): array
    {
        $entries = [];
        foreach ($rawEntries as $e) {
            $debit = (float) ($e['debit_amount'] ?? 0);
            $credit = (float) ($e['credit_amount'] ?? 0);
            // 借贷都为 0 的空行自动过滤
            if (abs($debit) < 0.005 && abs($credit) < 0.005) {
                continue;
            }
            $subjectId = (int) ($e['subject_id'] ?? 0);
            if (! $subjectId) {
                throw new \DomainException('每条分录必须选末级科目');
            }
            $subject = Subject::find($subjectId);
            if (! $subject || ! $subject->is_leaf) {
                throw new \DomainException('分录必须选择末级科目：'.$subject?->name);
            }
            // 借贷互斥：填了借方该行贷方置 0，反之亦然
            if (abs($debit) >= 0.005 && abs($credit) >= 0.005) {
                throw new \DomainException('同一分录借方与贷方不能同时有金额');
            }
            $entries[] = [
                'subject_id' => $subjectId,
                'subject_code' => $subject->code,
                'subject_name' => $subject->name,
                'aux_type' => $e['aux_type'] ?? null,
                'aux_id' => isset($e['aux_id']) ? (int) $e['aux_id'] : null,
                'aux_name' => $e['aux_name'] ?? null,
                'brand_id' => isset($e['brand_id']) ? (int) $e['brand_id'] : null,
                'brand_name' => $e['brand_name'] ?? null,
                'summary' => $e['summary'] ?? null,
                'debit_amount' => round($debit, 2),
                'credit_amount' => round($credit, 2),
            ];
        }

        return $entries;
    }

    private function validateEntries(array $entries): void
    {
        if (count($entries) < 2) {
            throw new \DomainException('一张凭证至少需要两条有效分录');
        }
    }

    private function ensureBalanced(float $debit, float $credit): void
    {
        if (abs(round($debit, 2) - round($credit, 2)) >= 0.005) {
            throw new \DomainException('借贷不平衡，借方合计 '.number_format($debit, 2).'，贷方合计 '.number_format($credit, 2));
        }
    }

    private function ensurePeriodOpen(string $period): void
    {
        $p = Period::where('year', (int) substr($period, 0, 4))
            ->where('month', (int) substr($period, 5, 2))
            ->first();
        if ($p && $p->status === Period::STATUS_CLOSED) {
            throw new \DomainException('期间 '.$period.' 已月结，凭证不可增删改');
        }
    }

    private function saveEntries(Voucher $voucher, array $entries): void
    {
        $lineNo = 1;
        foreach ($entries as $e) {
            $entry = new VoucherEntry;
            $entry->voucher_id = $voucher->id;
            $entry->line_no = $lineNo++;
            $entry->subject_id = $e['subject_id'];
            $entry->subject_code = $e['subject_code'];
            $entry->subject_name = $e['subject_name'];
            $entry->aux_type = $e['aux_type'];
            $entry->aux_id = $e['aux_id'];
            $entry->aux_name = $e['aux_name'];
            $entry->brand_id = $e['brand_id'];
            $entry->brand_name = $e['brand_name'];
            $entry->summary = $e['summary'];
            $entry->debit_amount = $e['debit_amount'];
            $entry->credit_amount = $e['credit_amount'];
            $entry->save();
        }
    }

    private function periodOfString(string $date): string
    {
        return substr($date, 0, 7);
    }

    private function formatVoucher(Voucher $voucher, bool $withEntries = false): array
    {
        $firstSummary = $voucher->entries->first()?->summary;
        $data = [
            'id' => $voucher->id,
            'voucher_no' => $voucher->voucher_no,
            'voucher_date' => $voucher->voucher_date?->format('Y-m-d'),
            'period' => $voucher->period,
            'attachments_count' => $voucher->attachments_count,
            'attachments' => $voucher->attachments ?? [],
            'total_debit' => (float) $voucher->total_debit,
            'total_credit' => (float) $voucher->total_credit,
            'status' => $voucher->status,
            'status_label' => Voucher::statusLabel($voucher->status),
            'maker_name' => $voucher->maker_name,
            'auditor_name' => $voucher->auditor_name,
            'audited_at' => $voucher->audited_at?->format('Y-m-d H:i:s'),
            'remark' => $voucher->remark,
            'red_of_id' => $voucher->red_of_id,
            'is_red' => (bool) $voucher->is_red,
            'auto_audit' => (bool) $voucher->auto_audit,
            'red_of_voucher_no' => $voucher->red_of_id
                ? ($voucher->relationLoaded('reversal')
                    ? $voucher->reversal?->voucher_no
                    : Voucher::whereKey($voucher->red_of_id)->value('voucher_no'))
                : null,
            'summary' => $firstSummary,
            'amount_chinese' => AmountChinese::convert((float) $voucher->total_debit),
            'created_at' => $voucher->created_at?->format('Y-m-d H:i:s'),
        ];
        if ($withEntries) {
            $data['entries'] = $voucher->entries->map(fn ($e) => [
                'id' => $e->id,
                'line_no' => $e->line_no,
                'subject_id' => $e->subject_id,
                'subject_code' => $e->subject_code,
                'subject_name' => $e->subject_name,
                'aux_type' => $e->aux_type,
                'aux_id' => $e->aux_id,
                'aux_name' => $e->aux_name,
                'brand_id' => $e->brand_id,
                'brand_name' => $e->brand_name,
                'summary' => $e->summary,
                'debit_amount' => (float) $e->debit_amount,
                'credit_amount' => (float) $e->credit_amount,
            ])->all();
        }

        return $data;
    }
}
