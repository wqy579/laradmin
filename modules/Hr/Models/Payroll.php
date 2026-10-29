<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 工资核算单（每人每期一张）。
 *
 * 状态机：draft（草稿，可改可删）→ paid（已发放，锁定，生成付款单）。
 * 应发净额 net_amount = base + commission + performance − deduction，
 * 由 PayrollService::calcNet() 统一口径，模型上不重算。
 */
class Payroll extends Model
{
    protected $table = 'hr_payrolls';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PAID = 'paid';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_PAID => '已发放',
    ];

    protected $fillable = [
        'payroll_no', 'period', 'employee_id', 'employee_name',
        'department_id', 'department_name',
        'base_amount', 'commission_amount', 'performance_amount', 'deduction_amount', 'net_amount',
        'status', 'pay_id', 'pay_no', 'cash_flow_no',
        'paid_date', 'payment_method', 'paid_at', 'paid_by', 'remark',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'performance_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_date' => 'date',
        'paid_at' => 'datetime',
    ];

    public function commissionFlows(): HasMany
    {
        return $this->hasMany(CommissionFlow::class, 'payroll_id');
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
