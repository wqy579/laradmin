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

    public const AUDIT_PENDING = 'pending';

    public const AUDIT_APPROVED = 'approved';

    public const AUDIT_REJECTED = 'rejected';

    public const AUDIT_LABELS = [
        self::AUDIT_PENDING => '待审核',
        self::AUDIT_APPROVED => '已审核',
        self::AUDIT_REJECTED => '已驳回',
    ];

    public const AUDIT_TAG_TYPES = [
        self::AUDIT_PENDING => 'warning',
        self::AUDIT_APPROVED => 'success',
        self::AUDIT_REJECTED => 'danger',
    ];

    public const CHECK_PENDING = 'pending';

    public const CHECK_CHECKED = 'checked';

    public const CHECK_LABELS = [
        self::CHECK_PENDING => '待检查',
        self::CHECK_CHECKED => '已检查',
    ];

    public const CHECK_TAG_TYPES = [
        self::CHECK_PENDING => 'warning',
        self::CHECK_CHECKED => 'success',
    ];

    protected $fillable = [
        'agreement_no', 'title', 'customer_id', 'customer_name', 'type',
        'start_date', 'end_date', 'amount', 'redeemed_amount',
        'total_times', 'used_times', 'check_status',
        'status', 'terminated_date', 'maker_name', 'remark',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'terminated_date' => 'date',
        'amount' => 'decimal:2',
        'redeemed_amount' => 'decimal:2',
        'total_times' => 'integer',
        'used_times' => 'integer',
        'audited_at' => 'datetime',
    ];

    /**
     * 剩余金额/剩余次数一律现算，不落库：
     * 存了就要在兑现、改协议额等每条路径上同步，早晚和源字段对不上。
     */
    protected $appends = ['remaining_times'];

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

    public static function auditLabel(?string $status): string
    {
        return self::AUDIT_LABELS[$status] ?? (string) $status;
    }

    public static function auditTagType(?string $status): string
    {
        return self::AUDIT_TAG_TYPES[$status] ?? 'info';
    }

    public static function checkLabel(?string $status): string
    {
        return self::CHECK_LABELS[$status] ?? (string) $status;
    }

    public static function checkTagType(?string $status): string
    {
        return self::CHECK_TAG_TYPES[$status] ?? 'info';
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

    /** 剩余次数：未设协议次数(0) 记 0，不做负数截断以外的修饰 */
    public function getRemainingTimesAttribute(): int
    {
        return max(0, (int) $this->total_times - (int) $this->used_times);
    }
}
