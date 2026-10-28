<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;
use Modules\Member\Models\Member;

/**
 * 积分核销单主表（CashIntegralVerify）——D 域积分核销 D6。
 *
 * 单号 HX+Ymd+6 位流水，一单只核销一个会员（无明细表）。状态机与发放单同义：
 * 草稿 → 待审核 → 已审核；草稿/待审核可作废，已审核不可逆（积分已扣减，冲销走反向单）。
 *
 * settled_at 是幂等标记：非空即表示审核事务内已扣过会员积分。
 */
class CashIntegralVerify extends Model
{
    protected $table = 'cash_integral_verify';

    protected $fillable = [
        'verify_no', 'verify_date', 'member_id', 'points', 'points_before', 'points_after',
        'verify_type', 'related_order_no', 'status', 'settled_at', 'remark',
        'operator_id', 'audited_by', 'audited_at', 'created_by',
    ];

    protected $casts = [
        'verify_date' => 'date',
        'member_id' => 'integer',
        'points' => 'integer',
        'points_before' => 'integer',
        'points_after' => 'integer',
        'status' => 'integer',
        'settled_at' => 'datetime',
        'operator_id' => 'integer',
        'audited_by' => 'integer',
        'audited_at' => 'datetime',
        'created_by' => 'integer',
    ];

    const STATUS_DRAFT = 0;

    const STATUS_PENDING = 1;

    const STATUS_AUDITED = 2;

    const STATUS_VOID = 3;

    const TYPE_CONSUME = 'consume';

    const TYPE_EXCHANGE = 'exchange';

    const TYPE_ADJUST = 'adjust';

    const TYPE_OTHER = 'other';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public static function statusLabel(int $status): string
    {
        return [
            self::STATUS_DRAFT => '草稿',
            self::STATUS_PENDING => '待审核',
            self::STATUS_AUDITED => '已审核',
            self::STATUS_VOID => '已作废',
        ][$status] ?? '未知';
    }

    public static function typeLabel(string $type): string
    {
        return [
            self::TYPE_CONSUME => '消费抵扣',
            self::TYPE_EXCHANGE => '兑换',
            self::TYPE_ADJUST => '调整',
            self::TYPE_OTHER => '其他',
        ][$type] ?? $type;
    }
}
