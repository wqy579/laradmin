<?php

namespace Modules\Agreement\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Agreement\Models\Agreement;

/**
 * 协议管理 + 陈列协议统计（F4）。
 *
 * 状态流转：active → expired（end_date 已过，查询前惰性刷新）；
 * active → terminated（人工终止，终态，兑现额不再可变）。
 * 兑现率 = 已兑现 / 协议额，仅在服务层计算，比率不落库。
 */
class AgreementService
{
    /** 惰性到期刷新：把过了到期日、仍标生效中的协议批量置 expired（终止态不参与） */
    public function refreshExpired(): int
    {
        return Agreement::query()
            ->where('status', Agreement::STATUS_ACTIVE)
            ->whereDate('end_date', '<', now()->toDateString())
            ->update([
                'status' => Agreement::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);
    }

    public function paginate(array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderByDesc('start_date')->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function query(array $filters): Builder
    {
        return Agreement::query()
            ->when(! empty($filters['keyword']), function ($q) use ($filters) {
                $kw = $filters['keyword'];
                $q->where(function ($qq) use ($kw) {
                    $qq->where('agreement_no', 'like', "%{$kw}%")
                        ->orWhere('title', 'like', "%{$kw}%")
                        ->orWhere('customer_name', 'like', "%{$kw}%");
                });
            })
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(! empty($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['start_from']), fn ($q) => $q->whereDate('end_date', '>=', $filters['start_from']))
            ->when(! empty($filters['start_to']), fn ($q) => $q->whereDate('start_date', '<=', $filters['start_to']));
    }

    public function stat(array $filters): array
    {
        $agg = $this->query($filters)->selectRaw(
            'COUNT(*) AS cnt,'
            .'COALESCE(SUM(amount),0) AS amount,'
            .'COALESCE(SUM(redeemed_amount),0) AS redeemed_amount,'
            ."COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END),0) AS active_count,"
            ."COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END),0) AS expired_count,"
            ."COALESCE(SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END),0) AS terminated_count"
        )->first();

        $amount = round((float) $agg->amount, 2);

        return [
            'count' => (int) $agg->cnt,
            'amount' => $amount,
            'redeemed_amount' => round((float) $agg->redeemed_amount, 2),
            'redemption_rate' => $amount > 0 ? round((float) $agg->redeemed_amount / $amount * 100, 2) : 0.0,
            'active_count' => (int) $agg->active_count,
            'expired_count' => (int) $agg->expired_count,
            'terminated_count' => (int) $agg->terminated_count,
        ];
    }

    /** 陈列协议统计：按协议逐条给兑现率 + 顶部聚合（stats 页数据源） */
    public function displayStats(array $filters): array
    {
        $filters['type'] = Agreement::TYPE_DISPLAY;
        unset($filters['status']);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(200, max(1, (int) ($filters['page_size'] ?? 20)));
        unset($filters['page'], $filters['page_size']);

        $pager = $this->query($filters)
            ->orderByRaw('CASE WHEN amount > 0 THEN redeemed_amount * 100.0 / amount ELSE 0 END DESC')
            ->orderByDesc('amount')
            ->paginate($pageSize, ['*'], 'page', $page);

        return [
            'list' => collect($pager->items())->map(fn (Agreement $a) => $this->transform($a))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'stat' => $this->stat($filters),
        ];
    }

    public function create(array $data, ?string $makerName = null): Agreement
    {
        $this->assertDateRange($data['start_date'], $data['end_date']);

        return DB::transaction(function () use ($data, $makerName) {
            $agreement = new Agreement($this->onlyFields($data));
            $agreement->agreement_no = $this->generateNo();
            $agreement->status = Agreement::STATUS_ACTIVE;
            $agreement->redeemed_amount = 0;
            $agreement->maker_name = $makerName;

            // 建档日已过开始日不算异常（补录场景）；过到期日直接落 expired
            if (Carbon::parse($data['end_date'])->lt(now()->toDateString())) {
                $agreement->status = Agreement::STATUS_EXPIRED;
            }

            $agreement->save();

            return $agreement;
        });
    }

    public function update(Agreement $agreement, array $data): Agreement
    {
        if ($agreement->status === Agreement::STATUS_TERMINATED) {
            throw new \DomainException('已终止协议不能修改');
        }

        $start = $data['start_date'] ?? $agreement->start_date->toDateString();
        $end = $data['end_date'] ?? $agreement->end_date->toDateString();
        $this->assertDateRange($start, $end);

        if (array_key_exists('amount', $data) && round((float) $data['amount'], 2) < (float) $agreement->redeemed_amount) {
            throw new \DomainException('协议额不能小于已兑现额');
        }

        $agreement->fill($this->onlyFields($data));
        // 生效中且新到期日已过 → 直接刷成已到期
        if ($agreement->status === Agreement::STATUS_ACTIVE
            && Carbon::parse($end)->lt(now()->toDateString())) {
            $agreement->status = Agreement::STATUS_EXPIRED;
        }
        $agreement->save();

        return $agreement;
    }

    public function terminate(Agreement $agreement, ?string $date): Agreement
    {
        if ($agreement->status === Agreement::STATUS_TERMINATED) {
            throw new \DomainException('协议已是终止状态');
        }

        $agreement->status = Agreement::STATUS_TERMINATED;
        $agreement->terminated_date = $date ?? now()->toDateString();
        $agreement->save();

        return $agreement;
    }

    /** 登记兑现（增量累计，不得超过协议额；终止/到期协议禁止兑现） */
    public function redeem(Agreement $agreement, float $delta): Agreement
    {
        if ($agreement->status === Agreement::STATUS_TERMINATED) {
            throw new \DomainException('已终止协议不能再登记兑现');
        }
        if ($delta <= 0) {
            throw new \DomainException('兑现金额必须大于 0');
        }

        $newRedeemed = round((float) $agreement->redeemed_amount + $delta, 2);
        if ($newRedeemed > (float) $agreement->amount + 0.005) {
            throw new \DomainException('兑现额累计超过协议额');
        }

        $agreement->redeemed_amount = $newRedeemed;
        $agreement->save();

        return $agreement;
    }

    public function transform(Agreement $a, bool $full = false): array
    {
        $row = [
            'id' => $a->id,
            'agreement_no' => $a->agreement_no,
            'title' => $a->title,
            'customer_id' => $a->customer_id,
            'customer_name' => $a->customer_name,
            'type' => $a->type,
            'type_label' => Agreement::typeLabel($a->type),
            'start_date' => $a->start_date?->toDateString(),
            'end_date' => $a->end_date?->toDateString(),
            'amount' => (float) $a->amount,
            'redeemed_amount' => (float) $a->redeemed_amount,
            'pending_amount' => round((float) $a->amount - (float) $a->redeemed_amount, 2),
            'redemption_rate' => $a->redemptionRate(),
            'status' => $a->status,
            'status_label' => Agreement::statusLabel($a->status),
            'status_tag_type' => Agreement::statusTagType($a->status),
        ];

        if ($full) {
            $row['terminated_date'] = $a->terminated_date?->toDateString();
            $row['maker_name'] = $a->maker_name;
            $row['remark'] = $a->remark;
        }

        return $row;
    }

    private function onlyFields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'title', 'customer_id', 'customer_name', 'type',
            'start_date', 'end_date', 'amount', 'remark',
        ]));
    }

    private function assertDateRange(string $start, string $end): void
    {
        if ($end < $start) {
            throw new \DomainException('到期日期不能早于生效日期');
        }
    }

    private function generateNo(): string
    {
        $prefix = 'XY'.date('Ymd');
        $last = Agreement::where('agreement_no', 'like', $prefix.'%')->orderByDesc('agreement_no')->value('agreement_no');
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
