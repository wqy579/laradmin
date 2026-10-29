<?php

namespace Modules\Asset\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Models\AssetCard;
use Modules\Asset\Models\AssetDepreciation;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Services\VoucherService;

/**
 * 资产折旧业务（D4）
 *
 * 按月批量计提，硬规则收口在后端：
 *  1) 仅统计「在用」状态资产；同月已有折旧记录视为已锁定，拒绝重复计提；
 *  2) 本月折旧额 = 原值 × (1 − 残值率%) ÷ 预计月数，封顶剩余净值；
 *  3) 每张卡片写一条折旧记录（计提后累计折旧/净值），并同步回写 asset_card；
 *  4) 按使用部门拆分生成凭证：借 管理费用(6602) / 贷 累计折旧(1602)，每部门一张，
 *     凭证失败（科目缺失/期间已月结）不回滚折旧本身，原因经 voucher_error 回给用户。
 */
class AssetDepreciationService
{
    /** 管理费用（借）/ 累计折旧（贷） */
    private const SUBJECT_EXPENSE = '6602';

    private const SUBJECT_ACCUM_DEP = '1602';

    public function __construct(private VoucherService $voucherService) {}

    /**
     * 某月应折明细（预览）：仅「在用」卡片，返回计提额与计提后净值。
     *
     * @return array<int, array<string, mixed>>
     */
    public function preview(string $month): array
    {
        return $this->depreciableCards($month)->values()->all();
    }

    /**
     * 执行某月批量折旧。
     *
     * 同月已计提 → 抛 ValidationException（前端以可读信息展示）。
     *
     * @return array{runs: AssetDepreciation, count: int, amount: float, voucher_ids: array<int, int>, voucher_error: ?string}
     */
    public function run(string $month, int $makerId, string $makerName): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw ValidationException::withMessages(['month' => '折旧期间格式应为 YYYY-MM']);
        }

        // 期间锁定：该月已存在任何折旧记录即视为已计提，不可重复
        if (AssetDepreciation::where('month', $month)->exists()) {
            throw ValidationException::withMessages([
                'month' => "期间 {$month} 已完成折旧计提，不可重复执行",
            ]);
        }

        $items = $this->depreciableCards($month);
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'month' => "期间 {$month} 无应折资产（仅统计「在用」状态），无法计提",
            ]);
        }

        $batchNo = $this->nextBatchNo($month);

        $rows = DB::transaction(function () use ($items, $month, $batchNo, $makerId) {
            $now = now();
            $created = [];

            foreach ($items as $item) {
                $record = AssetDepreciation::create([
                    'batch_no' => $batchNo,
                    'month' => $month,
                    'asset_card_id' => $item['id'],
                    'asset_no' => $item['asset_no'],
                    'asset_name' => $item['asset_name'],
                    'original_value' => $item['original_value'],
                    'residual_rate' => $item['residual_rate'],
                    'month_amount' => $item['month_amount'],
                    'accumulated_dep' => $item['after_depreciated'],
                    'net_value' => $item['after_net'],
                    'department_id' => $item['department_id'],
                    'department_name' => $item['department_name'],
                    'created_by' => $makerId ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // 同步回写资产卡片：累计折旧累加、净值扣减
                AssetCard::whereKey($item['id'])->update([
                    'accumulated_dep' => $item['after_depreciated'],
                    'net_value' => $item['after_net'],
                    'updated_at' => $now,
                ]);

                $created[] = $record;
            }

            return $created;
        });

        // 按使用部门分组出凭证（事务外，失败不回溯折旧）
        $voucherIds = [];
        $voucherError = null;
        foreach ($this->groupByDepartment($rows) as $dept) {
            try {
                $voucher = $this->voucherService->create([
                    'voucher_date' => $month.'-'.min((int) date('d'), 28),
                    'status' => 'audited',
                    'auto_audit' => true,
                    'remark' => '固定资产折旧（'.$month.'）'.$dept['label'],
                    'entries' => $this->depreciationEntries($dept['amount'], $month, $dept['label']),
                ], $makerId, $makerName);

                $voucherIds[] = $voucher->id;
                AssetDepreciation::where('batch_no', $batchNo)
                    ->where('department_id', $dept['department_id'])
                    ->update(['voucher_id' => $voucher->id]);
            } catch (DomainException $e) {
                // 科目缺失 / 期间已月结：折旧保留，原因回给用户补录
                $voucherError = $e->getMessage();
            }
        }

        $first = collect($rows)->first();

        return [
            'batch_no' => $batchNo,
            'id' => $first->id,
            'month' => $month,
            'count' => count($rows),
            'amount' => round((float) collect($rows)->sum('month_amount'), 2),
            'voucher_ids' => $voucherIds,
            'voucher_error' => $voucherError,
        ];
    }

    /** 某月折旧记录（明细） */
    public function records(string $month, int $page, int $pageSize): array
    {
        $query = AssetDepreciation::query()
            ->where('month', $month)
            ->orderBy('id');

        $pager = $query->paginate($pageSize, ['*'], 'page', $page);

        return [
            'list' => $pager->items(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ];
    }

    /** 折旧批次流水（按月聚合） */
    public function runs(int $page, int $pageSize): array
    {
        $query = AssetDepreciation::query()
            ->selectRaw('month, count(*) as count, sum(month_amount) as amount, max(batch_no) as batch_no, max(id) as id')
            ->groupBy('month')
            ->orderByDesc('month');

        $pager = $query->paginate($pageSize, ['*'], 'page', $page);

        return [
            'list' => $pager->items(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ];
    }

    /**
     * 应折明细计算：本月折旧额 = 原值 × (1 − 残值率%) ÷ 预计月数，封顶剩余净值。
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function depreciableCards(string $month): Collection
    {
        $cards = AssetCard::query()
            ->where('status', AssetCard::STATUS_INUSE)
            ->orderBy('asset_no')
            ->get();

        return $cards->map(function (AssetCard $c) {
            $original = (float) $c->original_value;
            $net = (float) $c->net_value;
            $residualRate = (float) $c->residual_rate;
            $useMonths = max(1, (int) $c->use_months);

            // 原值 × (1 − 残值率%) ÷ 预计月数，封顶剩余净值（提完为止）
            $base = $original * (1 - $residualRate / 100);
            $monthAmount = max(0.0, round(min($net, $base / $useMonths), 2));
            $afterDep = round((float) $c->accumulated_dep + $monthAmount, 2);
            $afterNet = max(0.0, round($original - $afterDep, 2));

            return [
                'id' => $c->id,
                'asset_no' => $c->asset_no,
                'asset_name' => $c->asset_name,
                'spec' => $c->spec,
                'original_value' => $original,
                'residual_rate' => $residualRate,
                'month_amount' => $monthAmount,
                'depreciated' => (float) $c->accumulated_dep,
                'net' => $net,
                'after_depreciated' => $afterDep,
                'after_net' => $afterNet,
                'department_id' => $c->department_id,
                'department_name' => $c->department_name ?: '未分配部门',
            ];
        });
    }

    /**
     * 按使用部门聚合折旧额（用于部门凭证拆分）。
     *
     * @param  array<int, AssetDepreciation>  $rows
     * @return array<int, array{department_id: ?int, label: string, amount: float}>
     */
    private function groupByDepartment(array $rows): array
    {
        return collect($rows)
            ->groupBy(fn ($r) => $r->department_id ?? 0)
            ->map(function (Collection $group, $deptId) {
                return [
                    'department_id' => $deptId ? (int) $deptId : null,
                    'label' => $group->first()->department_name ?: '未分配部门',
                    'amount' => round((float) $group->sum('month_amount'), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 折旧分录：借 管理费用(6602) / 贷 累计折旧(1602)。
     *
     * @return array<int, array<string, mixed>>
     */
    private function depreciationEntries(float $amount, string $month, string $department): array
    {
        $summary = '计提'.$month.'固定资产折旧（'.$department.'）';

        return [
            $this->entry(self::SUBJECT_EXPENSE, $summary, $amount, 'debit'),
            $this->entry(self::SUBJECT_ACCUM_DEP, $summary, $amount, 'credit'),
        ];
    }

    private function entry(string $code, string $summary, float $amount, string $direction): array
    {
        $subject = Subject::where('code', $code)->where('is_leaf', true)->first();
        if (! $subject) {
            throw new DomainException('未找到末级科目：'.$code.'，请先执行科目初始化');
        }

        return [
            'subject_id' => $subject->id,
            'summary' => $summary,
            'debit_amount' => $direction === 'debit' ? $amount : 0,
            'credit_amount' => $direction === 'credit' ? $amount : 0,
        ];
    }

    /** 批次号 ZJ-yyyymm-####，按月续号 */
    private function nextBatchNo(string $month): string
    {
        $period = str_replace('-', '', $month);
        $seq = AssetDepreciation::where('batch_no', 'like', 'ZJ-'.$period.'-%')->count() + 1;

        return sprintf('ZJ-%s-%04d', $period, $seq);
    }
}
