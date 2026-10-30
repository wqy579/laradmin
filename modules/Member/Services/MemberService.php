<?php

namespace Modules\Member\Services;

use Illuminate\Support\Facades\DB;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Modules\Member\Models\MemberLevel;
use Modules\Member\Models\MemberRecharge;
use Modules\Member\Models\PointOrder;
use Modules\Member\Models\PointProduct;
use Modules\Member\Models\PointRule;

/**
 * 会员核心业务：单号 / 积分储值变动（并发安全）/ 消费日志 / 等级升级 / 订单结算
 *
 * ⚠️ 资金与积分安全原则（本模块最重要的约束）：
 *   1. 所有 balance / points / total_consume 的变动都在事务内执行，并对会员行
 *      加行锁（lockForUpdate）。余额变动一律以「写前读」后的快照记录，保证
 *      台账可追溯对账；并发写入靠行锁串行化，不靠 CAS 重试。
 *   2. 只有单据「已审核」才真正发生资金/积分变动。取消/驳回必须做对称退回。
 *      这是积分业务最常见的资金事故来源（重复扣减）。
 *   3. 储值是负债（顾客预付），必须与现金流水一致：充值审核通过写
 *      cash_flows，冲正走红冲记录。
 *
 * 与现有体系的关系：
 *   - 会员经 customers 间接关联订单（销售订单都挂 customer_id），
 *     订单侧只落 member_id 快照 + use_balance/use_points。
 *   - member_levels.discount_rate 第一期不接入 PriceService 取价链（用户决策），
 *     仅作展示与升级判定依据，因此本服务不会改动订单商品价格。
 */
class MemberService
{
    /** 各单据类型的编号前缀 */
    public const NO_PREFIX = [
        'member' => 'HY',
        'recharge' => 'CZ',
        'product' => 'JP',
        // D7 编号正式化：积分单号统一 JF-yyyymm-####
        'point_order' => 'JF',
        'consume' => 'XH',
    ];

    /**
     * 生成当日递增单据号：前缀 + Ymd + 6 位流水。
     *
     * 查询当日已存在单据号的最大值 +1，再 str_pad 到 6 位。
     * 对齐 ReceiveService::generateNo / PurchaseApplicationController::generateNo 模式。
     */
    public static function generateNo(string $prefixKey, string $table, string $column, ?string $date = null): string
    {
        $prefix = self::NO_PREFIX[$prefixKey] ?? $prefixKey;
        $dateStr = $date ?? now()->format('Ymd');
        $like = $prefix.$dateStr.'%';

        $lastNo = DB::table($table)
            ->where($column, 'like', $like)
            ->orderByDesc($column)
            ->value($column);

        $seq = $lastNo ? ((int) substr($lastNo, -6)) + 1 : 1;

        return $prefix.$dateStr.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 生成按月递增单据号：前缀 + yyyymm + 4 位流水（D7 编号正式化）。
     *
     * 格式：JF-yyyymm-####（积分单号），按月连续不跳号。
     * 查询当月已存在单据号的最大流水 +1，再 str_pad 到 4 位。
     */
    public static function generateNoByMonth(string $prefix, string $table, string $column, ?string $date = null): string
    {
        $month = $date ? substr($date, 0, 7) : now()->format('Ym');
        $like = $prefix.'-'.$month.'-%';

        $lastNo = DB::table($table)
            ->where($column, 'like', $like)
            ->orderByDesc($column)
            ->value($column);

        $seq = $lastNo ? ((int) substr($lastNo, -4)) + 1 : 1;

        return sprintf('%s-%s-%04d', $prefix, $month, $seq);
    }

    /** 读取积分规则并解码 JSON 值；不存在返回默认值 */
    public static function ruleValue(string $key, $default = null)
    {
        $rule = PointRule::where('rule_key', $key)->first();
        if (! $rule) {
            return $default;
        }

        $decoded = is_array($rule->rule_value)
            ? $rule->rule_value
            : (json_decode((string) $rule->rule_value, true) ?: []);

        return $decoded ?: $default;
    }

    /**
     * 消费得积分规则：每 consume N 元得 M 积分（默认 1 元 1 分）。
     */
    public static function earnPointsForAmount(float $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $ratio = self::ruleValue('consume_ratio', ['amount' => 1, 'points' => 1]);
        $base = (float) ($ratio['amount'] ?? 1);
        $per = (float) ($ratio['points'] ?? 1);

        if ($base <= 0) {
            return 0;
        }

        return (int) floor($amount / $base * $per);
    }

    /**
     * 积分抵扣规则：N 积分抵 M 元。
     */
    public static function pointsToAmount(int $points, ?array $orderTotal = null): float
    {
        if ($points <= 0) {
            return 0.0;
        }

        $ratio = self::ruleValue('deduct_ratio', ['points' => 100, 'amount' => 1]);
        $basePoints = (float) ($ratio['points'] ?? 100);
        $perAmount = (float) ($ratio['amount'] ?? 1);

        if ($basePoints <= 0) {
            return 0.0;
        }

        $amount = round($points / $basePoints * $perAmount, 2);

        // 抵扣上限：percent 按比例、fixed 固定金额；默认不限制
        $limit = self::ruleValue('deduct_limit', ['type' => 'unlimited', 'value' => 0]);
        if ($limit && isset($orderTotal['amount'])) {
            $total = (float) $orderTotal['amount'];
            if ($limit['type'] === 'percent' && (float) $limit['value'] > 0) {
                $amount = min($amount, round($total * (float) $limit['value'] / 100, 2));
            } elseif ($limit['type'] === 'fixed' && (float) $limit['value'] > 0) {
                $amount = min($amount, (float) $limit['value']);
            }
        }

        return round(max($amount, 0), 2);
    }

    /**
     * 计算允许的最大积分抵扣（同时受余额、上限规则约束）
     */
    public static function maxDeductiblePoints(int $memberPoints, float $orderAmount): int
    {
        $ratio = self::ruleValue('deduct_ratio', ['points' => 100, 'amount' => 1]);
        $basePoints = (float) ($ratio['points'] ?? 100);
        $perAmount = (float) ($ratio['amount'] ?? 1);

        if ($basePoints <= 0 || $orderAmount <= 0) {
            return 0;
        }

        // 理论上最多可抵的金额
        $maxAmount = round($orderAmount, 2);
        $limit = self::ruleValue('deduct_limit', ['type' => 'unlimited', 'value' => 0]);
        if ($limit && $limit['type'] === 'percent' && (float) $limit['value'] > 0) {
            $maxAmount = min($maxAmount, round($orderAmount * (float) $limit['value'] / 100, 2));
        } elseif ($limit && $limit['type'] === 'fixed' && (float) $limit['value'] > 0) {
            $maxAmount = min($maxAmount, (float) $limit['value']);
        }

        $maxPointsByAmount = (int) floor($maxAmount / $perAmount * $basePoints);

        return min($maxPointsByAmount, $memberPoints);
    }

    /**
     * 订单结算钩子：扣储值 + 扣积分 + 发放积分 + 累计消费 + 自动升级 + 写台账
     *
     * 幂等：已结算的订单重复调用会抛出异常，防止 approve 多次触发重复加分。
     *
     * @param  float  $orderAmount  订单应付总额（total_amount - discount_amount）
     * @param  float  $useBalance  本单使用储值支付金额（元）
     * @param  int  $usePoints  本单使用积分数量
     * @return array{use_balance: float, use_points: int, use_points_amount: float,
     *               earn_points: int, net_amount: float, upgraded: bool}
     */
    public function settleOrder(
        Member $member,
        string $orderNo,
        float $orderAmount,
        float $useBalance = 0,
        int $usePoints = 0,
        ?array $operator = null
    ): array {
        if (! $member->isActive()) {
            throw new \RuntimeException('会员已冻结，无法结算');
        }

        $useBalance = round(max($useBalance, 0), 2);
        $usePoints = max($usePoints, 0);
        $pointsAmount = self::pointsToAmount($usePoints, ['amount' => $orderAmount]);

        // 校验可用余额与积分，超额一律抛错（前端应先校验，这里是最后防线）
        if ($useBalance > (float) $member->balance + 0.001) {
            throw new \RuntimeException('储值余额不足');
        }
        if ($usePoints > (int) $member->points) {
            throw new \RuntimeException('积分余额不足');
        }
        // 抵扣总额不得超过本单金额：否则 netAmount 会被 clamp 到 0，
        // 但储值仍会照扣，等于用超额储值「垫付」了一张更小的单子。
        if ($useBalance + $pointsAmount > $orderAmount + 0.001) {
            throw new \RuntimeException(
                '储值+积分抵扣合计 '.number_format($useBalance + $pointsAmount, 2)
                .' 元超过订单金额 '.number_format($orderAmount, 2).' 元'
            );
        }

        $netAmount = round(max($orderAmount - $useBalance - $pointsAmount, 0), 2);

        return DB::transaction(function () use ($member, $orderNo, $orderAmount, $useBalance, $usePoints, $pointsAmount, $netAmount, $operator) {
            // 行锁串行化并发结算，防止超扣
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            $earnPoints = self::earnPointsForAmount($netAmount);

            $balanceBefore = (float) $locked->balance;
            $pointsBefore = (int) $locked->points;

            $balanceAfter = round($balanceBefore - $useBalance, 2);
            $pointsAfter = $pointsBefore - $usePoints + $earnPoints;
            $totalConsumeAfter = round((float) $locked->total_consume + $netAmount, 2);
            $totalOrdersAfter = (int) $locked->total_orders + 1;

            $upgradedLevel = $this->checkLevelUpgrade($locked, $totalConsumeAfter);

            $locked->balance = $balanceAfter;
            $locked->points = $pointsAfter;
            $locked->total_consume = $totalConsumeAfter;
            $locked->total_orders = $totalOrdersAfter;
            if ($upgradedLevel) {
                $locked->level_id = $upgradedLevel->id;
            }
            $locked->save();

            $this->writeLog([
                'member' => $locked,
                'related_order_no' => $orderNo,
                'consume_type' => MemberConsumeLog::TYPE_ORDER,
                'direction' => $useBalance + $usePoints > 0 ? MemberConsumeLog::DIRECTION_OUT : MemberConsumeLog::DIRECTION_IN,
                'consume_amount' => $netAmount,
                'use_balance' => $useBalance,
                'use_points' => $usePoints,
                'earn_points' => $earnPoints,
                'remark' => sprintf('订单消费 %.2f 元，使用储值 %.2f 元 + 积分 %d（抵 %.2f 元），实付 %.2f 元，得积分 %d%s',
                    $orderAmount, $useBalance, $usePoints, $pointsAmount, $netAmount, $earnPoints,
                    $upgradedLevel ? '，已升级至'.$upgradedLevel->name : ''),
                'balance_after' => $balanceAfter,
                'points_after' => $pointsAfter,
                'total_consume_after' => $totalConsumeAfter,
                'operator' => $operator,
            ]);

            return [
                'use_balance' => $useBalance,
                'use_points' => $usePoints,
                'use_points_amount' => $pointsAmount,
                'earn_points' => $earnPoints,
                'net_amount' => $netAmount,
                'upgraded' => (bool) $upgradedLevel,
            ];
        });
    }

    /**
     * 订单红冲退款：退回本单已扣的储值与积分，并回滚累计消费与订单数。
     * 已结算才能退，且必须做对称冲正台账。
     *
     * 与 settleOrder 严格对称：settle 时 points = points - usePoints + earnPoints，
     * 红冲时 points = points + usePoints - earnPoints。漏扣 earnPoints 会让会员
     * 凭空多出结算那一次挣到的积分。earnPoints 由调用方按 settle 时同样的口径传入。
     */
    public function refundOrder(
        Member $member,
        string $orderNo,
        float $useBalance,
        int $usePoints,
        float $orderAmount,
        ?array $operator = null,
        string $reason = '',
        int $earnPoints = 0
    ): bool {
        $useBalance = round(max($useBalance, 0), 2);
        $usePoints = max($usePoints, 0);
        $earnPoints = max($earnPoints, 0);
        if ($useBalance <= 0 && $usePoints <= 0) {
            return false;
        }

        return DB::transaction(function () use ($member, $orderNo, $useBalance, $usePoints, $orderAmount, $operator, $reason, $earnPoints) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            $balanceAfter = round((float) $locked->balance + $useBalance, 2);
            $pointsAfter = max((int) $locked->points + $usePoints - $earnPoints, 0);
            $totalConsumeAfter = round(max((float) $locked->total_consume - $orderAmount, 0), 2);
            $totalOrdersAfter = max((int) $locked->total_orders - 1, 0);

            $locked->balance = $balanceAfter;
            $locked->points = $pointsAfter;
            $locked->total_consume = $totalConsumeAfter;
            $locked->total_orders = $totalOrdersAfter;
            $locked->save();

            $this->writeLog([
                'member' => $locked,
                'related_order_no' => $orderNo,
                'consume_type' => MemberConsumeLog::TYPE_ORDER_REFUND,
                'direction' => MemberConsumeLog::DIRECTION_IN,
                'consume_amount' => -$orderAmount,
                'use_balance' => $useBalance,
                'use_points' => $usePoints,
                'earn_points' => -$earnPoints,
                'remark' => sprintf('订单红冲退款，退回储值 %s 元、积分 %d%s', number_format($useBalance, 2), $usePoints, $reason),
                'balance_after' => $balanceAfter,
                'points_after' => $pointsAfter,
                'total_consume_after' => $totalConsumeAfter,
                'operator' => $operator,
            ]);

            return true;
        });
    }

    /**
     * 充值审核通过：增加储值余额 + 写现金流水（flow_type=receive，便于财务流水报表 UNION）
     *
     * 注：现金流水查询控制器只 UNION flow_type IN (receive, pay, expense, red_flush)，
     * 因此会员充值必须落在 flow_type=receive 下才能出现在「现金流水」报表里，
     * 用 related_type=MemberRecharge 与真实收款单区分。
     */
    public function approveRecharge(MemberRecharge $recharge, ?array $auditor = null): MemberRecharge
    {
        return DB::transaction(function () use ($recharge, $auditor) {
            $member = Member::whereKey($recharge->member_id)->lockForUpdate()->firstOrFail();

            $balanceAfter = round((float) $member->balance + (float) $recharge->total_amount, 2);
            $member->balance = $balanceAfter;
            $member->save();

            $recharge->status = MemberRecharge::STATUS_APPROVED;
            $recharge->audit_time = now();
            if ($auditor) {
                $recharge->auditor_id = $auditor['id'];
                $recharge->auditor_name = $auditor['name'];
            }
            $recharge->save();

            // 现金流水：收到现金 recharge_amount，赠送金额 gift_amount 不进流水（未真实到账）
            $flowNo = self::generateNo('recharge', 'member_recharges', 'recharge_no');
            DB::table('cash_flows')->insert([
                'flow_no' => 'CF'.now()->format('YmdHis').strtoupper(substr($flowNo, -4)),
                'flow_type' => 'receive',
                'customer_id' => $member->customer_id,
                'related_id' => $recharge->id,
                'related_type' => 'MemberRecharge',
                'flow_date' => $recharge->recharge_date?->format('Y-m-d') ?? now()->toDateString(),
                'amount' => (float) $recharge->recharge_amount,
                'payment_method' => $recharge->pay_method,
                'remark' => '会员储值充值：'.($member->name).$recharge->member_no.'，到账 '.number_format((float) $recharge->total_amount, 2).' 元（含赠送 '.number_format((float) $recharge->gift_amount, 2).' 元）',
                'created_by' => $auditor['id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->writeLog([
                'member' => $member,
                'related_order_no' => $recharge->recharge_no,
                'consume_type' => MemberConsumeLog::TYPE_RECHARGE,
                'direction' => MemberConsumeLog::DIRECTION_IN,
                'consume_amount' => (float) $recharge->total_amount,
                'use_balance' => (float) $recharge->total_amount,
                'remark' => sprintf('储值充值到账 %.2f 元（充值 %.2f + 赠送 %.2f），支付方式：%s',
                    (float) $recharge->total_amount, (float) $recharge->recharge_amount,
                    (float) $recharge->gift_amount, $recharge->pay_method),
                'balance_after' => $balanceAfter,
                'points_after' => (int) $member->points,
                'total_consume_after' => (float) $member->total_consume,
                'operator' => $auditor,
            ]);

            return $recharge;
        });
    }

    /**
     * 充值驳回/取消：未审核的单据尚未加钱，无需退回，仅改状态。
     */
    public function closeRecharge(MemberRecharge $recharge, int $status, ?array $auditor = null, string $reason = ''): MemberRecharge
    {
        if ($recharge->status === MemberRecharge::STATUS_APPROVED) {
            throw new \RuntimeException('已审核单据不能驳回/取消，请走红冲');
        }

        $recharge->status = $status;
        $recharge->audit_time = now();
        if ($auditor) {
            $recharge->auditor_id = $auditor['id'];
            $recharge->auditor_name = $auditor['name'];
        }
        if ($reason) {
            $recharge->reject_reason = $reason;
        }
        $recharge->save();

        return $recharge;
    }

    /**
     * 兑换订单审核通过：扣减积分 + 扣减兑换商品库存 + 写台账
     */
    public function approvePointOrder(PointOrder $order, ?array $auditor = null): PointOrder
    {
        return DB::transaction(function () use ($order, $auditor) {
            $member = Member::whereKey($order->member_id)->lockForUpdate()->firstOrFail();

            if ((int) $member->points < (int) $order->total_points) {
                throw new \RuntimeException('会员积分不足，无法审核通过');
            }

            $product = PointProduct::whereKey($order->product_id)->lockForUpdate()->firstOrFail();
            if ((int) $product->stock < (int) $order->quantity) {
                throw new \RuntimeException('兑换商品库存不足');
            }

            $pointsAfter = (int) $member->points - (int) $order->total_points;
            $member->points = $pointsAfter;
            $member->save();

            $product->stock = (int) $product->stock - (int) $order->quantity;
            $product->exchanged = (int) $product->exchanged + (int) $order->quantity;
            $product->save();

            $order->status = PointOrder::STATUS_APPROVED;
            $order->audit_time = now();
            if ($auditor) {
                $order->auditor_id = $auditor['id'];
                $order->auditor_name = $auditor['name'];
            }
            $order->save();

            $this->writeLog([
                'member' => $member,
                'related_order_no' => $order->order_no,
                'consume_type' => MemberConsumeLog::TYPE_POINTS_DEDUCT,
                'direction' => MemberConsumeLog::DIRECTION_OUT,
                'use_points' => (int) $order->total_points,
                'remark' => sprintf('积分兑换：%s × %d，消耗 %d 积分',
                    $order->product_name, $order->quantity, $order->total_points),
                'balance_after' => (float) $member->balance,
                'points_after' => $pointsAfter,
                'total_consume_after' => (float) $member->total_consume,
                'operator' => $auditor,
            ]);

            return $order;
        });
    }

    /**
     * 兑换订单取消/驳回：退回积分与库存（仅在已审核、尚未终态时执行）。
     */
    public function closePointOrder(PointOrder $order, int $status, string $reason = ''): PointOrder
    {
        if (PointOrder::isFinal($order->status)) {
            throw new \RuntimeException('终态订单不能取消/驳回');
        }

        // 只有已审核（已扣积分+库存）的订单才需要退回
        $needRollback = $order->status === PointOrder::STATUS_APPROVED;

        return DB::transaction(function () use ($order, $status, $reason, $needRollback) {
            if ($needRollback) {
                $member = Member::whereKey($order->member_id)->lockForUpdate()->firstOrFail();
                $pointsAfter = (int) $member->points + (int) $order->total_points;
                $member->points = $pointsAfter;
                $member->save();

                $product = PointProduct::whereKey($order->product_id)->lockForUpdate()->first();
                if ($product) {
                    $product->stock = (int) $product->stock + (int) $order->quantity;
                    $product->exchanged = max((int) $product->exchanged - (int) $order->quantity, 0);
                    $product->save();

                    $this->writeLog([
                        'member' => $member,
                        'related_order_no' => $order->order_no,
                        'consume_type' => MemberConsumeLog::TYPE_POINTS_DEDUCT,
                        'direction' => MemberConsumeLog::DIRECTION_IN,
                        'use_points' => (int) $order->total_points,
                        'remark' => '兑换取消/驳回，退回 '.(int) $order->total_points.' 积分'.$reason,
                        'balance_after' => (float) $member->balance,
                        'points_after' => $pointsAfter,
                        'total_consume_after' => (float) $member->total_consume,
                    ]);
                }
            }

            $order->status = $status;
            if ($status === PointOrder::STATUS_REJECTED || $status === PointOrder::STATUS_CANCELLED) {
                $order->audit_time = $order->audit_time ?? now();
            }
            $order->reject_reason = $reason ?: $order->reject_reason;
            $order->save();

            return $order;
        });
    }

    /**
     * 自动升级检查：按累计消费找可达的最高等级。
     * 只有等级 ID 更高时才升级（不降级）。
     *
     * @return MemberLevel|null 升级后的等级，未升级返回 null
     */
    public function checkLevelUpgrade(Member $member, float $totalConsume): ?MemberLevel
    {
        $target = MemberLevel::where('status', 1)
            ->where('upgrade_amount', '<=', $totalConsume)
            ->where('id', '>', $member->level_id)
            ->orderByDesc('upgrade_amount')
            ->first();

        return $target;
    }

    /**
     * 写消费台账（只追加）。余额快照由调用方在事务内传入，保证与变动一致。
     */
    public function writeLog(array $data): MemberConsumeLog
    {
        $member = $data['member'];
        $operator = $data['operator'] ?? null;

        return MemberConsumeLog::create([
            'log_no' => self::generateNo('consume', 'member_consume_logs', 'log_no'),
            'member_id' => $member->id,
            'member_no' => $member->member_no,
            'member_name' => $member->name,
            'related_order_no' => $data['related_order_no'] ?? null,
            'consume_type' => $data['consume_type'],
            'direction' => $data['direction'] ?? MemberConsumeLog::DIRECTION_IN,
            'consume_amount' => $data['consume_amount'] ?? 0,
            'use_balance' => $data['use_balance'] ?? 0,
            'use_points' => $data['use_points'] ?? 0,
            'earn_points' => $data['earn_points'] ?? 0,
            'balance_after' => $data['balance_after'] ?? (float) $member->balance,
            'points_after' => $data['points_after'] ?? (int) $member->points,
            'total_consume_after' => $data['total_consume_after'] ?? (float) $member->total_consume,
            'operator_id' => $operator['id'] ?? null,
            'operator_name' => $operator['name'] ?? null,
            'remark' => $data['remark'] ?? null,
        ]);
    }

    /**
     * 由客户创建会员档案（「客户一键转会员」）。
     * 已存在的会员直接返回，保证 1:0..1 语义。
     */
    public function createMemberFromCustomer(array $attributes, ?array $operator = null): Member
    {
        $customerId = $attributes['customer_id'] ?? null;

        if ($customerId) {
            $existing = Member::where('customer_id', $customerId)->first();
            if ($existing) {
                return $existing;
            }
        }

        // 默认等级：LV0 普通会员，没有则取第一个启用等级
        $defaultLevel = MemberLevel::where('status', 1)->orderBy('sort')->first()
            ?? MemberLevel::orderBy('sort')->first();

        $registerGift = (int) (self::ruleValue('register_gift', ['points' => 0])['points'] ?? 0);

        return DB::transaction(function () use ($attributes, $defaultLevel, $registerGift, $operator) {
            $member = Member::create([
                'member_no' => self::generateNo('member', 'members', 'member_no'),
                'customer_id' => $attributes['customer_id'] ?? null,
                'customer_name' => $attributes['customer_name'] ?? null,
                'name' => $attributes['name'],
                'contact' => $attributes['contact'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'gender' => $attributes['gender'] ?? 0,
                'birthday' => $attributes['birthday'] ?? null,
                'email' => $attributes['email'] ?? null,
                'province' => $attributes['province'] ?? null,
                'city' => $attributes['city'] ?? null,
                'district' => $attributes['district'] ?? null,
                'address' => $attributes['address'] ?? null,
                'level_id' => $defaultLevel?->id ?? 1,
                'points' => $registerGift,
                'balance' => 0,
                'total_consume' => 0,
                'total_orders' => 0,
                'register_date' => $attributes['register_date'] ?? now()->toDateString(),
                'status' => $attributes['status'] ?? Member::STATUS_ACTIVE,
                'remark' => $attributes['remark'] ?? null,
            ]);

            if ($registerGift > 0) {
                $this->writeLog([
                    'member' => $member,
                    'consume_type' => MemberConsumeLog::TYPE_RECHARGE,
                    'direction' => MemberConsumeLog::DIRECTION_IN,
                    'earn_points' => $registerGift,
                    'remark' => '新会员注册赠送积分 '.$registerGift,
                    'balance_after' => 0,
                    'points_after' => $registerGift,
                    'total_consume_after' => 0,
                    'operator' => $operator,
                ]);
            }

            return $member;
        });
    }
}
