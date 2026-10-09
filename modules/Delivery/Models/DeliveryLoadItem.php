<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 装车单明细：按验货单×商品记录，确认装车时据此扣减库存。
 */
class DeliveryLoadItem extends Model
{
    protected $table = 'delivery_load_items';

    protected $fillable = [
        'load_id', 'check_id', 'check_no', 'sales_order_id', 'order_no',
        'customer_id', 'customer_name', 'address', 'phone',
        'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'quantity', 'price', 'amount',
        'picking_id', 'remark', 'sort',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function load(): BelongsTo
    {
        return $this->belongsTo(DeliveryLoad::class, 'load_id');
    }
}
