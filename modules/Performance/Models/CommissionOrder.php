<?php

namespace Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 提成单主表。
 *
 * 一张提成单 = 某业务员某月的提成结算，状态机：
 *   pending（待审核）→ audited（已审核）→ paid（已发放）
 *                  └→ rejected（已驳回，终态）
 * 审核后数据锁定，明细快照存 detail_snapshot 保证历史可追溯。
 */
class CommissionOrder extends Model
{
    protected $table = 'perf_commission_orders';

    protected $fillable = [
        'bill_no', 'period', 'employee_id', 'employee_name',
        'department_id', 'department_name',
        'sale_amount', 'payment_amount', 'return_amount', 'gross_profit',
        'commission_base', 'commission_gross', 'commission_deduct', 'commission_amount',
        'status', 'detail_snapshot',
        'maker_id', 'maker_name', 'auditor_id', 'auditor_name', 'audited_at', 'paid_at',
        'remark',
    ];

    protected $casts = [
        'sale_amount' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'return_amount' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'commission_base' => 'decimal:2',
        'commission_gross' => 'decimal:2',
        'commission_deduct' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'detail_snapshot' => 'array',
        'audited_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'rule_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommissionOrderItem::class, 'commission_order_id');
    }
}
