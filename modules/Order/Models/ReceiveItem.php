<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiveItem extends Model
{
    protected $table = 'receive_items';

    protected $fillable = [
        'receive_id',
        'sales_order_id',
        'order_no',
        'pay_amount',
        'paid_before',
        'receivable_before',
    ];

    protected $casts = [
        'pay_amount' => 'decimal:2',
        'paid_before' => 'decimal:2',
        'receivable_before' => 'decimal:2',
    ];

    public function receive(): BelongsTo
    {
        return $this->belongsTo(Receive::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
