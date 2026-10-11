<?php

namespace Modules\Member\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

class MemberRecharge extends Model
{
    protected $table = 'member_recharges';

    protected $fillable = [
        'recharge_no', 'member_id', 'member_no', 'member_name',
        'recharge_amount', 'gift_amount', 'total_amount', 'pay_method',
        'recharge_date', 'operator_id', 'operator_name',
        'status', 'auditor_id', 'auditor_name', 'audit_time',
        'reject_reason', 'remark',
    ];

    protected $casts = [
        'recharge_amount' => 'decimal:2',
        'gift_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'recharge_date' => 'date',
        'status' => 'integer',
        'audit_time' => 'datetime',
    ];

    const STATUS_PENDING = 0;

    const STATUS_APPROVED = 1;

    const STATUS_REJECTED = 2;

    const STATUS_CANCELLED = 3;

    const PAY_CASH = 'cash';

    const PAY_WECHAT = 'wechat';

    const PAY_ALIPAY = 'alipay';

    const PAY_BANK = 'bank';

    const PAY_OTHER = 'other';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    public static function statusLabel(int $status): string
    {
        return [
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '已审核',
            self::STATUS_REJECTED => '已驳回',
            self::STATUS_CANCELLED => '已取消',
        ][$status] ?? '未知';
    }
}
