<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;

/**
 * 收入上交款单（头表）
 *
 * amount 恒等于明细 handover_amount 之和（保存时服务端强校验）；
 * 确认后按 amount 生成一条 income 流水挂 account_id，并回写明细订单收款状态。
 */
class CashHandover extends Model
{
    protected $table = 'cash_handover';

    protected $fillable = [
        'handover_no', 'handover_date', 'salesman_id', 'salesman_name',
        'account_id', 'amount', 'status', 'remark', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'handover_date' => 'date',
        'salesman_id' => 'integer',
        'account_id' => 'integer',
        'amount' => 'decimal:2',
        'confirmed_by' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';

    const STATUS_CONFIRMED = 'confirmed';

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CashHandoverItem::class, 'handover_id')->orderBy('sort')->orderBy('id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public static function statusLabel(string $status): string
    {
        return $status === self::STATUS_CONFIRMED ? '已确认' : '待确认';
    }
}
