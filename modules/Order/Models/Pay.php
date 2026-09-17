<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pay extends Model
{
    protected $table = 'pays';
    protected $fillable = [
        'pay_no', 'pay_type', 'supplier_id', 'purchase_order_id',
        'amount', 'pay_date', 'payment_method', 'handler_id', 'remark', 'status',
    ];
    protected $casts = [
        'pay_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handler_id');
    }
}
