<?php

namespace Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 业务目标（支撑分析页达成率）。任务书 §八：
 * employee_id + period(YYYY-MM) + target_amount，(employee_id,period) 唯一。
 * target_type/target_id 保留兼容 Analysis 里按 target_type=employee 的旧查询。
 */
class SalesTarget extends Model
{
    protected $table = 'perf_sales_targets';

    protected $fillable = [
        'employee_id', 'target_type', 'target_id', 'period', 'target_amount',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'target_amount' => 'decimal:2',
    ];
}
