<?php

namespace Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionOrderItem extends Model
{
    protected $table = 'perf_commission_order_items';

    protected $fillable = [
        'commission_order_id', 'source_type', 'source_id', 'source_no',
        'product_id', 'product_name', 'qty', 'base_amount',
        'rule_id', 'rule_name', 'calc_type', 'rate_or_unit', 'amount',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'base_amount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function commissionOrder(): BelongsTo
    {
        return $this->belongsTo(CommissionOrder::class, 'commission_order_id');
    }
}
