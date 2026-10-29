<?php

namespace Modules\Cash\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Models\CashIntegralGrant;
use Modules\Cash\Models\CashIntegralGrantItem;
use Modules\Cash\Models\CashIntegralRule;
use Modules\Cash\Models\CashIntegralVerify;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Modules\Member\Services\MemberService;

/**
 * 积分规则 + 积分发放（D5）+ 积分核销 + 积分查询（D6）业务。
 *
 * 审核是发放/核销两单共同的唯一积分副作用点：同一事务内完成
 *   单据状态流转 → members.points 增减 → member_consume_logs 台账流水，
 * 任一步失败整体回滚（TASKBOOK §二.2 审核类接口口径）。
 *
 * 幂等：status 守卫（只允许 待审核 → 已审核）+ settled_at 已处理标记
 * 双层防护，重复审核既不重复变动积分也不会重复写流水。
 *
 * 仍未实现（超出 D6 范围）：
 *   - point_orders / point_products 兑换联动；
 *   - 规则侧 deduct_ratio / threshold / limit 抵扣计算。
 */
class CashIntegralService
{
    /** 发放入账写会员流水时的业务类型（Member 模块常量不含此项，本模块侧约定） */
    public const CONSUME_TYPE_GRANT = 'points_grant';

    /** 核销出账写会员流水时的业务类型（D6 使用） */
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

    // ==================== 积分核销单（D6）====================

    /** @return LengthAwarePaginator<int, CashIntegralVerify> */
    public function verifyList(array $params): LengthAwarePaginator
    {
        return $this->verifyQuery($params)->paginate($this->pageSize($params));
    }

    /** @return Collection<int, CashIntegralVerify> 导出用（带上限） */
    public function verifyRows(array $params, int $limit = 10000): Collection
    {
        return $this->verifyQuery($params)->limit($limit)->get();
    }

    private function verifyQuery(array $params): Builder
    {
        $query = CashIntegralVerify::query()->with(['member', 'operator'])->orderByDesc('id');

        if (! empty($params['verify_no'])) {
            $query->where('verify_no', 'like', '%'.$params['verify_no'].'%');
        }
        if (! empty($params['member_id'])) {
            $query->where('member_id', (int) $params['member_id']);
        }
        if (! empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('verify_no', 'like', '%'.$keyword.'%')
                    ->orWhereHas('member', fn ($m) => $m->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('member_no', 'like', '%'.$keyword.'%'));
            });
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }
        if (! empty($params['date_start'])) {
            $query->where('verify_date', '>=', $params['date_start']);
        }
        if (! empty($params['date_end'])) {
            $query->where('verify_date', '<=', $params['date_end']);
        }

        return $query;
    }

    public function verifyDetail(int $id): CashIntegralVerify
    {
        return CashIntegralVerify::query()->with(['member', 'operator'])->findOrFail($id);
    }

    public function verifySave(array $data, ?CashIntegralVerify $verify, int $operatorId): CashIntegralVerify
    {
        $payload = [
            'verify_date' => $data['verify_date'],
            'member_id' => (int) $data['member_id'],
            'points' => (int) $data['points'],
            'verify_type' => $data['verify_type'] ?? CashIntegralVerify::TYPE_CONSUME,
            'related_order_no' => $data['related_order_no'] ?? null,
            'remark' => $data['remark'] ?? null,
        ];

        if ($verify) {
            $verify->fill($payload)->save();

            return $verify;
        }

        return CashIntegralVerify::create($payload + [
            'verify_no' => MemberService::generateNo('HX', 'cash_integral_verify', 'verify_no'),
            'status' => CashIntegralVerify::STATUS_DRAFT,
            'operator_id' => $operatorId,
            'created_by' => $operatorId,
        ]);
    }

    public function verifySubmit(CashIntegralVerify $verify): CashIntegralVerify
    {
        if ((int) $verify->status !== CashIntegralVerify::STATUS_DRAFT) {
            throw new \DomainException('只有草稿状态的核销单可以提交');
        }

        $verify->status = CashIntegralVerify::STATUS_PENDING;
        $verify->save();

        return $verify;
    }

    public function verifyVoid(CashIntegralVerify $verify): CashIntegralVerify
    {
        if (! in_array((int) $verify->status, [CashIntegralVerify::STATUS_DRAFT, CashIntegralVerify::STATUS_PENDING], true)) {
            throw new \DomainException('已审核或已作废的核销单不能作废');
        }

        $verify->status = CashIntegralVerify::STATUS_VOID;
        $verify->save();

        return $verify;
    }

    /**
     * 审核核销单：状态流转 + 会员积分扣减 + 会员消费流水，单事务。
     *
     * 幂等两层（与 grantAudit 同骨架）：
     *  1) 行锁 + status 必须为待审核（重复审核第二次直接抛错）；
     *  2) settled_at 非空即跳过扣减（状态被外部改回时仍不会二次扣分/二次写流水）。
     *
     * 积分不足在扣减前判定并抛错，整单回滚，不留半条流水。
     */
    public function verifyAudit(int $id, int $operatorId): CashIntegralVerify
    {
        return DB::transaction(function () use ($id, $operatorId) {
            $verify = CashIntegralVerify::query()->lockForUpdate()->findOrFail($id);

            if ((int) $verify->status !== CashIntegralVerify::STATUS_PENDING) {
                throw new \DomainException('只有待审核的核销单可以审核，当前状态：'
                    .CashIntegralVerify::statusLabel((int) $verify->status));
            }

            $points = (int) $verify->points;

            if ($verify->settled_at !== null) {
                $verify->status = CashIntegralVerify::STATUS_AUDITED;
                $verify->audited_by = $operatorId;
                $verify->audited_at = now();
                $verify->save();

                return $verify;
            }

            $member = Member::query()->lockForUpdate()->findOrFail($verify->member_id);
            $pointsBefore = (int) $member->points;

            if ($pointsBefore < $points) {
                throw new \DomainException('会员可用积分不足，当前 '.$pointsBefore.'，本次核销 '.$points);
            }

            // 原子扣减：不读改写覆盖，避免与并发发放互相吞掉变更
            Member::query()->whereKey($member->id)->decrement('points', $points);
            $pointsAfter = $pointsBefore - $points;

            $this->writeVerifyLog($verify, $member, $pointsAfter, $operatorId);

            $verify->points_before = $pointsBefore;
            $verify->points_after = $pointsAfter;
            $verify->settled_at = now();
            $verify->status = CashIntegralVerify::STATUS_AUDITED;
            $verify->audited_by = $operatorId;
            $verify->audited_at = now();
            $verify->save();

            return $verify;
        });
    }

    /**
     * 核销出账流水（direction=out）——同 append-only 台账，只追加不修改。
     */
    private function writeVerifyLog(CashIntegralVerify $verify, Member $member, int $pointsAfter, int $operatorId): void
    {
        MemberConsumeLog::create([
            'log_no' => MemberService::generateNo('consume', 'member_consume_logs', 'log_no'),
            'member_id' => $member->id,
            'member_no' => $member->member_no,
            'member_name' => $member->name,
            'related_order_no' => $verify->related_order_no ?: $verify->verify_no,
            'consume_type' => self::CONSUME_TYPE_USE,
            'direction' => MemberConsumeLog::DIRECTION_OUT,
            'consume_amount' => 0,
            'use_balance' => 0,
            'use_points' => (int) $verify->points,
            'earn_points' => 0,
            'balance_after' => $member->balance,
            'points_after' => $pointsAfter,
            'total_consume_after' => $member->total_consume,
            'operator_id' => $operatorId,
            'operator_name' => optional($verify->operator)->real_name,
            'remark' => $verify->remark ?: ('积分核销 '.$verify->verify_no),
        ]);
    }

    /**
     * 核销弹窗用：某会员的当前可用积分 + 累计发放/累计核销。
     *
     * @return array{member_id:int,member_no:?string,member_name:?string,points_current:int,points_grant:int,points_verify:int}
     */
    public function verifyMemberSummary(int $memberId): array
    {
        $member = Member::query()->findOrFail($memberId);

        $row = MemberConsumeLog::query()
            ->where('member_id', $member->id)
            ->where($this->pointsChangedScope())
            ->selectRaw('COALESCE(SUM(earn_points), 0) as points_grant, COALESCE(SUM(use_points), 0) as points_verify')
            ->first();

        return [
            'member_id' => (int) $member->id,
            'member_no' => $member->member_no,
            'member_name' => $member->name,
            'points_current' => (int) $member->points,
            'points_grant' => (int) ($row->points_grant ?? 0),
            'points_verify' => (int) ($row->points_verify ?? 0),
        ];
    }

    // ==================== 积分查询（D6，只读）====================
    //
    // 积分流水的唯一数据源是 member_consume_logs 中产生过积分变动的行
    // （发放 D5 写 earn_points、核销 D6 写 use_points）。
    // **绝不能再 union cash_integral_grant_item**，否则发放会被重复计数。

    /**
     * 会员积分汇总（期初 / 本期发放 / 本期核销 / 期末 / 实时）。
     *
     * 口径：期初 = start_date 之前所有积分变动的有符号累加；
     *      期末 = 期初 + 本期发放 − 本期核销（在同一行 SQL 内算出，保证恒等式成立）。
     * 单条 leftJoinSub 聚合 + 一次分页，不 N+1、不把整表拉进 PHP 再 sum。
     *
     * @return LengthAwarePaginator<int, Member>
     */
    public function ledgerMembers(array $params): LengthAwarePaginator
    {
        return $this->ledgerMembersQuery($params)->paginate($this->pageSize($params));
    }

    /** @return array<int, Member> 汇总导出用（带上限，不走 pageSize 的 200 上限） */
    public function ledgerMemberRows(array $params, int $limit = 10000): array
    {
        return $this->ledgerMembersQuery($params)->limit($limit)->get()->all();
    }

    private function ledgerMembersQuery(array $params): Builder
    {
        [$from, $to] = $this->ledgerWindow($params);

        $agg = MemberConsumeLog::query()
            ->selectRaw('member_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at < ? THEN earn_points - use_points ELSE 0 END), 0) as agg_open', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN earn_points ELSE 0 END), 0) as agg_grant', [$from, $to])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN use_points ELSE 0 END), 0) as agg_verify', [$from, $to])
            ->where('created_at', '<=', $to)
            ->where($this->pointsChangedScope())
            ->groupBy('member_id');

        $query = Member::query()
            ->select([
                'members.id',
                'members.member_no',
                'members.name as member_name',
                'members.points as points_current',
            ])
            ->selectRaw('COALESCE(agg.agg_open, 0) as points_open')
            ->selectRaw('COALESCE(agg.agg_grant, 0) as points_grant')
            ->selectRaw('COALESCE(agg.agg_verify, 0) as points_verify')
            ->selectRaw('COALESCE(agg.agg_open, 0) + COALESCE(agg.agg_grant, 0) - COALESCE(agg.agg_verify, 0) as points_close')
            ->leftJoinSub($agg, 'agg', 'agg.member_id', '=', 'members.id')
            ->whereNotNull('agg.member_id')
            ->orderBy('members.id');

        if (! empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('members.name', 'like', '%'.$keyword.'%')
                    ->orWhere('members.member_no', 'like', '%'.$keyword.'%');
            });
        }
        if (! empty($params['member_id'])) {
            $query->where('members.id', (int) $params['member_id']);
        }

        return $query;
    }

    /** @return LengthAwarePaginator<int, MemberConsumeLog> 单会员积分流水（points 有符号） */
    public function ledgerFlows(array $params): LengthAwarePaginator
    {
        return $this->ledgerFlowsQuery($params)->paginate($this->pageSize($params));
    }

    private function ledgerFlowsQuery(array $params): Builder
    {
        [$from, $to] = $this->ledgerWindow($params);

        $query = MemberConsumeLog::query()
            ->select([
                'id', 'log_no', 'member_id', 'member_name', 'consume_type', 'direction',
                'earn_points', 'use_points', 'points_after', 'related_order_no', 'remark', 'created_at',
            ])
            ->selectRaw('(earn_points - use_points) as points')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->where($this->pointsChangedScope())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($params['member_id'])) {
            $query->where('member_id', (int) $params['member_id']);
        }

        return $query;
    }

    /**
     * 积分变动行的判定，必须整体加括号：
     * 裸 orWhere 会与本方法外的 where 条件产生 AND/OR 优先级串味（漏掉 member_id 约束）。
     *
     * @return callable|\Closure
     */
    private function pointsChangedScope(): \Closure
    {
        return fn ($q) => $q->where('earn_points', '>', 0)->orWhere('use_points', '>', 0);
    }

    /**
     * 日期窗口归一化为闭区间秒。created_at 是 timestamp，
     * 故起始补 00:00:00、结束补 23:59:59，未传时取全域。
     *
     * @return array{0:string,1:string}
     */
    private function ledgerWindow(array $params): array
    {
        $start = $params['start_date'] ?? ($params['date_start'] ?? '');
        $end = $params['end_date'] ?? ($params['date_end'] ?? '');

        $from = $start !== '' ? substr($start, 0, 10).' 00:00:00' : '1970-01-01 00:00:00';
        $to = $end !== '' ? substr($end, 0, 10).' 23:59:59' : '2999-12-31 23:59:59';

        return [$from, $to];
    }
}
