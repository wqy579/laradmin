<?php

namespace Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 提成单主表（任务书 §八 字段名）。
 *
 * 状态机：pending（待审核）→ audited（已审核）→ paid（已发放）；
 *                └→ rejected（已驳回，终态）。审核后数据锁定，
 * 明细快照存 detail_snapshot 保证历史可追溯。
 *
 * commission_amount = 应发提成；deduction = 扣减；actual_amount = 实发。
 */
class CommissionOrder extends Model
{
    protected $table = 'perf_commission_orders';

    protected $fillable = [
        'bill_no', 'period', 'employee_id', 'employee_name',
        'department_id', 'department_name',
        'sales_amount', 'received_amount', 'return_amount', 'profit_amount',
        'rule_id', 'rate', 'base_amount',
        'commission_amount', 'deduction', 'actual_amount',
        'status', 'detail_snapshot',
        'maker_id', 'maker_name', 'auditor_id', 'auditor_name', 'audited_at', 'paid_at',
        'remark',
    ];

    protected $casts = [
        'sales_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'return_amount' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'deduction' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'rate' => 'decimal:4',
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
        return $this->hasMany(CommissionOrderItem::class, 'order_id');
    }
}
