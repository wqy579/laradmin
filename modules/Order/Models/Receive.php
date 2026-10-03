<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receive extends Model
{
    protected $table = 'receives';

    protected $fillable = [
        'receive_no', 'receive_type', 'customer_id', 'sales_order_id',
        'sales_order_items', 'amount', 'receive_date', 'payment_method', 'handler_id', 'remark', 'status',
    ];

    protected $casts = [
        'receive_date'      => 'date',
        'amount'            => 'decimal:2',
        'sales_order_items' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handler_id');
    }
}
