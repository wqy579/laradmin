<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class PurchaseReturnItem extends Model
{
    protected $table = 'purchase_return_items';

    protected $fillable = [
        'return_id', 'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'original_qty', 'returned_qty', 'return_qty', 'return_price', 'return_amount',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
