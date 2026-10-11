<?php

namespace Modules\Member\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 会员消费记录（只追加台账 append-only）。
 *
 * 记录每一笔储值/积分变动及变动后的余额快照，用于追溯对账。
 * 本表不提供 update/delete 接口——账务凭证一旦写入不可篡改，
 * 出错时通过写一条反向记录（direction 取反）来冲正。
 */
class MemberConsumeLog extends Model
{
    protected $table = 'member_consume_logs';

    protected $fillable = [
        'log_no', 'member_id', 'member_no', 'member_name',
        'related_order_no', 'consume_type', 'direction',
        'consume_amount', 'use_balance', 'use_points', 'earn_points',
        'balance_after', 'points_after', 'total_consume_after',
        'operator_id', 'operator_name', 'remark',
    ];

    protected $casts = [
        'consume_amount' => 'decimal:2',
        'use_balance' => 'decimal:2',
        'use_points' => 'integer',
        'earn_points' => 'integer',
        'balance_after' => 'decimal:2',
        'points_after' => 'integer',
        'total_consume_after' => 'decimal:2',
    ];

    const TYPE_ORDER = 'order';
    const TYPE_RECHARGE = 'recharge';
    const TYPE_POINTS_DEDUCT = 'points_deduct';
    const TYPE_ORDER_REFUND = 'order_refund';
    const TYPE_RECHARGE_REFUND = 'recharge_refund';

    const DIRECTION_IN = 'in';
    const DIRECTION_OUT = 'out';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public static function typeLabel(string $type): string
    {
        return [
            self::TYPE_ORDER => '订单消费',
            self::TYPE_RECHARGE => '储值充值',
            self::TYPE_POINTS_DEDUCT => '积分抵扣',
            self::TYPE_ORDER_REFUND => '订单退款',
            self::TYPE_RECHARGE_REFUND => '充值冲正',
        ][$type] ?? $type;
    }
}
