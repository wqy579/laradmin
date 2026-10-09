<?php

namespace Modules\BorrowReturn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class BorrowOrderItem extends Model
{
    protected $table = 'borrow_order_items';

    protected $fillable = [
        'order_id', 'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'borrow_qty', 'returned_qty', 'unit_price', 'amount', 'remark', 'sort',
    ];

    protected $casts = [
        'borrow_qty' => 'integer',
        'returned_qty' => 'integer',
        'unit_price' => 'float',
        'amount' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BorrowOrder::class, 'order_id');
    }
}
