<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class SalesReturnItem extends Model
{
    protected $table = 'sales_return_items';

    protected $fillable = [
        'return_id', 'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'order_qty', 'returned_qty', 'return_qty', 'return_price', 'return_amount',
    ];

    protected $casts = [
        'return_qty' => 'integer',
        'order_qty' => 'integer',
        'returned_qty' => 'integer',
        'return_price' => 'decimal:2',
        'return_amount' => 'decimal:2',
    ];

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
