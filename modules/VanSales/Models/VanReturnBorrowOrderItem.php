<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class VanReturnBorrowOrderItem extends Model
{
    protected $table = 'van_return_borrow_order_items';

    protected $fillable = [
        'order_id', 'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'return_qty', 'unit_price', 'amount', 'remark', 'sort',
    ];

    protected $casts = [
        'return_qty' => 'integer',
        'unit_price' => 'float',
        'amount' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(VanReturnBorrowOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
