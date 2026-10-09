<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryPickingItem extends Model
{
    protected $table = 'delivery_picking_items';

    protected $fillable = [
        'picking_id', 'sales_order_item_id', 'product_id', 'product_code', 'product_name',
        'spec', 'unit', 'order_qty', 'qty_large', 'qty_medium', 'qty_small', 'quantity',
        'price', 'amount', 'stock_qty', 'remark', 'sort',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function picking(): BelongsTo
    {
        return $this->belongsTo(DeliveryPicking::class, 'picking_id');
    }
}
