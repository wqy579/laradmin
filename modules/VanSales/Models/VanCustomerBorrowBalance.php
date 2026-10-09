<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Product;

class VanCustomerBorrowBalance extends Model
{
    protected $table = 'van_customer_borrow_balances';

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
