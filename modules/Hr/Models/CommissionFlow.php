<?php

namespace Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 提成流水：perf 域提成单的发放侧快照（只读引用 perf 数据，不改其表）。
 *
 * 数字口径与 perf_commission_orders 完全一致：
 * commission_amount=应发提成、deduction=扣减、actual_amount=实发。
 */
class CommissionFlow extends Model
{
    protected $table = 'hr_commission_flows';

    protected $fillable = [
        'flow_no', 'commission_order_id', 'bill_no', 'period',
        'employee_id', 'employee_name', 'department_id', 'department_name',
        'source_no',
        'sales_amount', 'received_amount', 'base_amount',
        'commission_amount', 'deduction', 'actual_amount',
        'payroll_id', 'payroll_no', 'paid_date', 'remark',
    ];

    protected $casts = [
        'sales_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'deduction' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'paid_date' => 'date',
    ];
}
