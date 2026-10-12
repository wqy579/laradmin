<?php

namespace Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 销售目标（可选）。用于业务员达成率展示。
 * target_type: employee / customer / product；target_id 对应实体 id。
 */
class SalesTarget extends Model
{
    protected $table = 'perf_sales_targets';

    protected $fillable = ['target_type', 'target_id', 'period', 'target_amount'];

    protected $casts = [
        'target_amount' => 'decimal:2',
    ];
}
