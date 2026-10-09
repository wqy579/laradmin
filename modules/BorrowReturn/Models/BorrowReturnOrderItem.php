<?php

namespace Modules\BorrowReturn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class BorrowReturnOrderItem extends Model
{
    protected $table = 'borrow_return_order_items';

    protected $fillable = [
        'order_id', 'borrow_order_item_id', 'product_id', 'product_code', 'product_name',
        'spec', 'unit', 'unreturned_qty', 'return_qty', 'good_qty', 'bad_qty',
        'bad_reason', 'unit_price', 'amount', 'remark', 'sort',
    ];

    protected $casts = [
        'unreturned_qty' => 'integer',
        'return_qty' => 'integer',
        'good_qty' => 'integer',
        'bad_qty' => 'integer',
        'unit_price' => 'float',
        'amount' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BorrowReturnOrder::class, 'order_id');
    }
}
