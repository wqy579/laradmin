<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Member\Models\Member;

/**
 * 积分发放单明细（CashIntegralGrantItem）——D 域积分发放 D5。
 *
 * settled_at 为「已处理标记」：审核事务内写完 member_consume_logs 与
 * members.points 后置入时间戳，重复审核时非空即跳过（幂等守卫）。
 */
class CashIntegralGrantItem extends Model
{
    protected $table = 'cash_integral_grant_item';

    protected $fillable = [
        'grant_id', 'member_id', 'points', 'remark', 'settled_at',
    ];

    protected $casts = [
        'grant_id' => 'integer',
        'member_id' => 'integer',
        'points' => 'integer',
        'settled_at' => 'datetime',
    ];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(CashIntegralGrant::class, 'grant_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
