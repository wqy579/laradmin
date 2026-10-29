<?php

namespace Modules\Cash\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Models\CashIntegralGrant;
use Modules\Cash\Models\CashIntegralGrantItem;
use Modules\Cash\Models\CashIntegralRule;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Modules\Member\Services\MemberService;

/**
 * 积分规则 + 积分发放业务（D 域 D5）。
 *
 * 审核是本模块唯一的资金/积分副作用点：同一事务内完成
 *   单据状态流转 → members.points 累加 → member_consume_logs 入账流水，
 * 任一步失败整体回滚（TASKBOOK §二.2 审核类接口口径）。
 *
 * 幂等：status 守卫（只允许 待审核 → 已审核）+ 明细 settled_at 已处理标记
 * 双层防护，重复审核既不重复加分也不会重复写流水。
 *
 * D6 核销预留（本批不实现，仅留字段/注释）：
 *   - member_consume_logs 的 consume_type='points_use' 分支与 use_points 写入；
 *   - point_orders / point_products 兑换联动；
 *   - 规则侧 deduct_ratio / threshold / limit 抵扣计算。
 */
class CashIntegralService
{
    /** 发放入账写会员流水时的业务类型（Member 模块常量不含此项，本模块侧约定） */
    public const CONSUME_TYPE_GRANT = 'points_grant';

    /** D6 预留：核销侧 consume_type，本批不写入 */
    public const CONSUME_TYPE_USE = 'points_use';

    // ==================== 积分规则 ====================

    /** @return LengthAwarePaginator<int, CashIntegralRule> */
    public function ruleList(array $params): LengthAwarePaginator
    {
        return $this->ruleQuery($params)->paginate($this->pageSize($params));
    }

    /** @return Collection<int, CashIntegralRule> 导出用（带上限） */
    public function ruleRows(array $params, int $limit = 10000): Collection
    {
        return $this->ruleQuery($params)->limit($limit)->get();
    }

    private function ruleQuery(array $params): Builder
    {
        $query = CashIntegralRule::query()->orderByDesc('id');

        if (! empty($params['rule_name'])) {
            $query->where('rule_name', 'like', '%'.$params['rule_name'].'%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }
        if (! empty($params['effective_date_start'])) {
            $query->where('effective_date', '>=', $params['effective_date_start']);
        }
        if (! empty($params['effective_date_end'])) {
            $query->where('effective_date', '<=', $params['effective_date_end']);
        }

        return $query;
    }

    public function ruleSave(array $data, ?CashIntegralRule $rule = null, ?int $operatorId = null): CashIntegralRule
    {
        $payload = [
            'rule_name' => $data['rule_name'],
            'ratio' => $data['ratio'],
            'effective_date' => $data['effective_date'],
            'status' => (int) ($data['status'] ?? CashIntegralRule::STATUS_ENABLED),
            'remark' => $data['remark'] ?? null,
        ];

        if ($rule) {
            $rule->fill($payload)->save();

            return $rule;
        }

        return CashIntegralRule::create($payload + ['created_by' => $operatorId]);
    }

    public function ruleToggle(CashIntegralRule $rule): CashIntegralRule
    {
        $rule->status = $rule->status === CashIntegralRule::STATUS_ENABLED
            ? CashIntegralRule::STATUS_DISABLED
            : CashIntegralRule::STATUS_ENABLED;
        $rule->save();

        return $rule;
    }

    // ==================== 积分发放单 ====================

    /** @return LengthAwarePaginator<int, CashIntegralGrant> */
    public function grantList(array $params): LengthAwarePaginator
    {
        return $this->grantQuery($params)->paginate($this->pageSize($params));
    }

    /** @return Collection<int, CashIntegralGrant> 导出用（带上限） */
    public function grantRows(array $params, int $limit = 10000): Collection
    {
        return $this->grantQuery($params)->limit($limit)->get();
    }

    private function grantQuery(array $params): Builder
    {
        $query = CashIntegralGrant::query()->with('operator')->orderByDesc('id');

        if (! empty($params['grant_no'])) {
            $query->where('grant_no', 'like', '%'.$params['grant_no'].'%');
        }
        if (! empty($params['member_id'])) {
            $query->whereHas('items', fn ($q) => $q->where('member_id', (int) $params['member_id']));
        }
        if (! empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('grant_no', 'like', '%'.$keyword.'%')
                    ->orWhereHas('items.member', fn ($m) => $m->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('member_no', 'like', '%'.$keyword.'%'));
            });
        }
        if (! empty($params['grant_type'])) {
            $query->where('grant_type', $params['grant_type']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }
        if (! empty($params['date_start'])) {
            $query->where('grant_date', '>=', $params['date_start']);
        }
        if (! empty($params['date_end'])) {
            $query->where('grant_date', '<=', $params['date_end']);
        }

        return $query;
    }

    public function grantDetail(int $id): CashIntegralGrant
    {
        return CashIntegralGrant::query()->with(['items.member', 'operator'])->findOrFail($id);
    }

    /**
     * 新建/编辑发放单（编辑仅草稿可改，控制器已做状态守卫）。
     *
     * @param  array{grant_date:string,grant_type:string,remark?:?string,items:array<int, array{member_id:int,points:int,remark?:?string}>}  $data
     */
    public function grantSave(array $data, ?CashIntegralGrant $grant, int $operatorId): CashIntegralGrant
    {
        $items = $data['items'];

        return DB::transaction(function () use ($data, $grant, $operatorId, $items) {
            $total = (int) array_sum(array_column($items, 'points'));

            if ($grant) {
                $grant->fill([
                    'grant_date' => $data['grant_date'],
                    'grant_type' => $data['grant_type'],
                    'total_points' => $total,
                    'remark' => $data['remark'] ?? null,
                ])->save();
                CashIntegralGrantItem::query()->where('grant_id', $grant->id)->delete();
            } else {
                $grant = CashIntegralGrant::create([
                    'grant_no' => MemberService::generateNo('JF', 'cash_integral_grant', 'grant_no'),
                    'grant_date' => $data['grant_date'],
                    'grant_type' => $data['grant_type'],
                    'total_points' => $total,
                    'status' => CashIntegralGrant::STATUS_DRAFT,
                    'remark' => $data['remark'] ?? null,
                    'operator_id' => $operatorId,
                    'created_by' => $operatorId,
                ]);
            }

            foreach ($items as $item) {
                CashIntegralGrantItem::create([
                    'grant_id' => $grant->id,
                    'member_id' => (int) $item['member_id'],
                    'points' => (int) $item['points'],
                    'remark' => $item['remark'] ?? null,
                ]);
            }

            return $grant;
        });
    }

    public function grantSubmit(CashIntegralGrant $grant): CashIntegralGrant
    {
        if ((int) $grant->status !== CashIntegralGrant::STATUS_DRAFT) {
            throw new \DomainException('只有草稿状态的发放单可以提交');
        }
        if ($grant->items()->count() === 0) {
            throw new \DomainException('发放明细为空，不能提交');
        }

        $grant->status = CashIntegralGrant::STATUS_PENDING;
        $grant->save();

        return $grant;
    }

    public function grantVoid(CashIntegralGrant $grant): CashIntegralGrant
    {
        if (! in_array((int) $grant->status, [CashIntegralGrant::STATUS_DRAFT, CashIntegralGrant::STATUS_PENDING], true)) {
            throw new \DomainException('已审核或已作废的发放单不能作废');
        }

        $grant->status = CashIntegralGrant::STATUS_VOID;
        $grant->save();

        return $grant;
    }

    /**
     * 审核发放单：状态流转 + 会员积分入账 + 会员消费流水，单事务。
     *
     * 幂等三层：
     *  1) 行锁 + status 必须为待审核（重复审核第二次直接抛错）；
     *  2) 明细 settled_at 非空即跳过（防止状态与流水不一致时重复加分）；
     *  3) 单号唯一 + 事务回滚保证不出现半张单。
     */
    public function grantAudit(int $id, int $operatorId): CashIntegralGrant
    {
        return DB::transaction(function () use ($id, $operatorId) {
            $grant = CashIntegralGrant::query()->lockForUpdate()->findOrFail($id);

            if ((int) $grant->status !== CashIntegralGrant::STATUS_PENDING) {
                throw new \DomainException('只有待审核的发放单可以审核，当前状态：'
                    .CashIntegralGrant::statusLabel((int) $grant->status));
            }

            $items = CashIntegralGrantItem::query()
                ->where('grant_id', $grant->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new \DomainException('发放明细为空，不能审核');
            }

            foreach ($items as $item) {
                if ($item->settled_at !== null) {
                    continue;
                }

                $member = Member::query()->lockForUpdate()->findOrFail($item->member_id);
                $pointsAfter = (int) $member->points + (int) $item->points;
                $member->points = $pointsAfter;
                $member->save();

                $this->writeConsumeLog($grant, $item, $member, $pointsAfter, $operatorId);

                $item->settled_at = now();
                $item->save();
            }

            $grant->status = CashIntegralGrant::STATUS_AUDITED;
            $grant->audited_by = $operatorId;
            $grant->audited_at = now();
            $grant->save();

            return $grant;
        });
    }

    /**
     * 会员消费流水入账（direction=in，只增不减）。
     *
     * D6 预留：核销场景写同表 direction='out' + consume_type=self::CONSUME_TYPE_USE
     * + use_points，本批不实现。
     */
    private function writeConsumeLog(
        CashIntegralGrant $grant,
        CashIntegralGrantItem $item,
        Member $member,
        int $pointsAfter,
        int $operatorId
    ): void {
        MemberConsumeLog::create([
            'log_no' => MemberService::generateNo('consume', 'member_consume_logs', 'log_no'),
            'member_id' => $member->id,
            'member_no' => $member->member_no,
            'member_name' => $member->name,
            'related_order_no' => $grant->grant_no,
            'consume_type' => self::CONSUME_TYPE_GRANT,
            'direction' => MemberConsumeLog::DIRECTION_IN,
            'consume_amount' => 0,
            'use_balance' => 0,
            'use_points' => 0,
            'earn_points' => (int) $item->points,
            'balance_after' => $member->balance,
            'points_after' => $pointsAfter,
            'total_consume_after' => $member->total_consume,
            'operator_id' => $operatorId,
            'operator_name' => optional($grant->operator)->real_name,
            'remark' => $item->remark ?: ('积分发放 '.$grant->grant_no),
        ]);
    }

    private function pageSize(array $params, int $default = 30): int
    {
        return min(200, max(1, (int) ($params['page_size'] ?? $default)));
    }
}
