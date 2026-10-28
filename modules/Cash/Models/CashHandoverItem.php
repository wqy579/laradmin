<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 收入上交款明细：一行对应一个销售订单的本次上交金额
 */
class CashHandoverItem extends Model
{
    protected $table = 'cash_handover_item';

    protected $fillable = [
        'handover_id', 'sales_order_id', 'order_no', 'customer_id', 'customer_name',
        'order_date', 'order_amount', 'handover_amount', 'sort',
    ];

    protected $casts = [
        'handover_id' => 'integer',
        'sales_order_id' => 'integer',
        'customer_id' => 'integer',
        'order_date' => 'date',
        'order_amount' => 'decimal:2',
        'handover_amount' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function handover(): BelongsTo
    {
        return $this->belongsTo(CashHandover::class, 'handover_id');
    }
}
