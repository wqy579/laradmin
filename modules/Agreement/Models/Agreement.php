<?php

namespace Modules\Agreement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 客户协议（含陈列协议）。三态状态机：
 *
 *  active 生效中 → expired 已到期（end_date 已过，服务层惰性刷新）
 *                → terminated 已终止（人工终止，终态，不可再兑现）
 */
class Agreement extends Model
{
    protected $table = 'agr_agreements';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => '生效中',
        self::STATUS_EXPIRED => '已到期',
        self::STATUS_TERMINATED => '已终止',
    ];

    public const STATUS_TAG_TYPES = [
        self::STATUS_ACTIVE => 'success',
        self::STATUS_EXPIRED => 'info',
        self::STATUS_TERMINATED => 'danger',
    ];

    public const TYPE_DISPLAY = 'display';

    public const TYPE_GENERAL = 'general';

    public const TYPE_LABELS = [
        self::TYPE_DISPLAY => '陈列协议',
        self::TYPE_GENERAL => '一般协议',
    ];

    protected $fillable = [
        'agreement_no', 'title', 'customer_id', 'customer_name', 'type',
        'start_date', 'end_date', 'amount', 'redeemed_amount',
        'status', 'terminated_date', 'maker_name', 'remark',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'terminated_date' => 'date',
        'amount' => 'decimal:2',
        'redeemed_amount' => 'decimal:2',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    public static function statusTagType(?string $status): string
    {
        return self::STATUS_TAG_TYPES[$status] ?? 'info';
    }

    public static function typeLabel(?string $type): string
    {
        return self::TYPE_LABELS[$type] ?? (string) $type;
    }

    /** 兑现率 0~100（协议额<=0 记 0，避免除零 NaN） */
    public function redemptionRate(): float
    {
        $amount = (float) $this->amount;
        if ($amount <= 0) {
            return 0.0;
        }

        return round((float) $this->redeemed_amount / $amount * 100, 2);
    }
}
