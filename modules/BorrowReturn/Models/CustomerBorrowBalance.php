<?php

namespace Modules\BorrowReturn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Product;

class CustomerBorrowBalance extends Model
{
    protected $table = 'customer_borrow_balances';

    protected $fillable = [
        'customer_id', 'product_id', 'qty',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
