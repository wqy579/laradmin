<?php

namespace Modules\Mall\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 商城订单明细行（商品/规格/三档价格数量快照）。
 */
class MallOrderItem extends Model
{
    protected $table = 'mall_order_items';

    protected $fillable = [
        'order_id', 'product_id', 'product_code', 'product_name', 'spec',
        'unit_large', 'unit_medium', 'unit_small',
        'unit_conversion', 'unit_conversion_medium',
        'qty_large', 'qty_medium', 'qty_small',
        'price_large', 'price_medium', 'price_small',
        'quantity', 'amount',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'product_id' => 'integer',
        'unit_conversion' => 'integer',
        'unit_conversion_medium' => 'integer',
        'qty_large' => 'integer',
        'qty_medium' => 'integer',
        'qty_small' => 'integer',
        'price_large' => 'decimal:2',
        'price_medium' => 'decimal:2',
        'price_small' => 'decimal:2',
        'quantity' => 'integer',
        'amount' => 'decimal:2',
    ];
}
