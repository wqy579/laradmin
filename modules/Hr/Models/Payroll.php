<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 工资核算单（每人每期一张）。
 *
 * 状态机：draft（草稿，可改可删）→ paid（已发放，锁定，生成付款单）。
 * 应发净额 net_amount = 基本 + 工龄 + 提成 + 绩效 + 加班 + 调整 + 报销 − 扣项，
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

    /** 手工可录的工资项目（提成不在其中：它是 perf 提成单的只读快照） */
    public const ITEM_LABELS = [
        'base_amount' => '基本工资',
        'seniority_amount' => '工龄工资',
        'performance_amount' => '绩效工资',
        'overtime_amount' => '加班工资',
        'adjustment_amount' => '调整工资',
        'reimbursement_amount' => '报销金额',
        'deduction_amount' => '扣项',
    ];

    /**
     * 汇总与导出的金额列序（合计条、工资报表 Tab、两份 CSV 共用同一顺序）。
     * 提成与应发合计只读，标签在 READONLY_LABELS 里补齐。
     */
    public const AMOUNT_FIELDS = [
        'base_amount', 'seniority_amount', 'commission_amount', 'performance_amount',
        'overtime_amount', 'adjustment_amount', 'reimbursement_amount', 'deduction_amount', 'net_amount',
    ];

    private const READONLY_LABELS = [
        'commission_amount' => '销售提成',
        'net_amount' => '应发合计',
    ];

    protected $fillable = [
        'payroll_no', 'period', 'employee_id', 'employee_name',
        'department_id', 'department_name',
        'base_amount', 'seniority_amount', 'commission_amount', 'performance_amount',
        'overtime_amount', 'adjustment_amount', 'reimbursement_amount', 'deduction_amount', 'net_amount',
        'status', 'pay_id', 'pay_no', 'cash_flow_no',
        'paid_date', 'payment_method', 'paid_at', 'paid_by', 'remark',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'seniority_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'performance_amount' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'reimbursement_amount' => 'decimal:2',
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

    /** 金额列的中文表头（合计条、报表、CSV 三处共用） */
    public static function amountLabel(string $field): string
    {
        return self::ITEM_LABELS[$field] ?? self::READONLY_LABELS[$field] ?? $field;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
