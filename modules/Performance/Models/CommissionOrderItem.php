<?php

namespace Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 提成明细行（任务书 §八：perf_commission_items）。
 * source_type: order（订单汇总行）/ product（单品行）。
 */
class CommissionOrderItem extends Model
{
    protected $table = 'perf_commission_items';

    protected $fillable = [
        'order_id', 'source_type', 'source_id', 'source_no',
        'product_id', 'product_name', 'quantity',
        'sales_amount', 'cost_amount', 'profit_amount',
        'base_amount', 'contribution',
        'rule_id', 'rule_name', 'calc_type', 'rate_or_unit', 'amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sales_amount' => 'decimal:2',
        'cost_amount' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'contribution' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommissionOrder::class, 'order_id');
    }
}
