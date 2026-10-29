<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;

/**
 * 积分发放单主表（CashIntegralGrant）——D 域积分发放 D5。
 *
 * 单号 JF+Ymd+6 位流水。状态机：草稿 → 待审核 → 已审核；草稿/待审核可作废，
 * 已审核不可逆（积分已入账，冲销走反向发放单）。
 */
class CashIntegralGrant extends Model
{
    protected $table = 'cash_integral_grant';

    protected $fillable = [
        'grant_no', 'grant_date', 'grant_type', 'total_points', 'status',
        'remark', 'operator_id', 'audited_by', 'audited_at', 'created_by',
    ];

    protected $casts = [
        'grant_date' => 'date',
        'total_points' => 'integer',
        'status' => 'integer',
        'operator_id' => 'integer',
        'audited_by' => 'integer',
        'audited_at' => 'datetime',
        'created_by' => 'integer',
    ];

    const STATUS_DRAFT = 0;

    const STATUS_PENDING = 1;

    const STATUS_AUDITED = 2;

    const STATUS_VOID = 3;

    const TYPE_DAILY = 'daily';

    const TYPE_ACTIVITY = 'activity';

    const TYPE_COMPENSATE = 'compensate';

    const TYPE_OTHER = 'other';

    public function items(): HasMany
    {
        return $this->hasMany(CashIntegralGrantItem::class, 'grant_id');
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
            self::TYPE_DAILY => '日常',
            self::TYPE_ACTIVITY => '活动',
            self::TYPE_COMPENSATE => '补偿',
            self::TYPE_OTHER => '其他',
        ][$type] ?? $type;
    }
}
