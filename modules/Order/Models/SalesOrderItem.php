<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Stock\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    protected $table = 'sales_order_items';
    protected $fillable = [
        'sales_order_id', 'product_id', 'quantity', 'actual_qty',
        'qty_large', 'qty_medium', 'qty_small',
        'price', 'price_large', 'price_medium', 'price_small',
        'amount', 'remark', 'sale_mode',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
