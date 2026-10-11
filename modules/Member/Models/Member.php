<?php

namespace Modules\Member\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;

/**
 * 会员档案。
 *
 * customer_id 绑定客户档案（1:0..1，customers.id 唯一）。销售订单挂在客户上，
 * 会员身份经客户间接关联，订单侧只落 member_id 快照。
 *
 * ⚠️ 与 customers.balance（欠款余额）无关：本模型的 balance 是会员储值余额。
 */
class Member extends Model
{
    protected $table = 'members';

    protected $fillable = [
        'member_no', 'customer_id', 'customer_name', 'name', 'contact', 'phone',
        'gender', 'birthday', 'email', 'province', 'city', 'district', 'address',
        'level_id', 'points', 'balance', 'total_consume', 'total_orders',
        'register_date', 'status', 'remark',
    ];

    protected $casts = [
        'gender' => 'integer',
        'birthday' => 'date',
        'level_id' => 'integer',
        'points' => 'integer',
        'balance' => 'decimal:2',
        'total_consume' => 'decimal:2',
        'total_orders' => 'integer',
        'register_date' => 'date',
        'status' => 'integer',
    ];

    const STATUS_ACTIVE = 1;

    const STATUS_FROZEN = 0;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'level_id');
    }

    public function recharges(): HasMany
    {
        return $this->hasMany(MemberRecharge::class, 'member_id');
    }

    public function pointOrders(): HasMany
    {
        return $this->hasMany(PointOrder::class, 'member_id');
    }

    public function consumeLogs(): HasMany
    {
        return $this->hasMany(MemberConsumeLog::class, 'member_id');
    }

    /** 会员是否正常状态 */
    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }
}
