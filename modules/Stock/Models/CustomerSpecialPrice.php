<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Order\Models\Customer;

class CustomerSpecialPrice extends Model
{
    protected $table = 'customer_special_price';

    protected $fillable = [
        'price_plan_id', 'customer_id', 'product_id', 'price',
        'start_date', 'end_date', 'status', 'remark',
    ];

    protected $casts = [
        'price' => 'float',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function plan()
    {
        return $this->belongsTo(PricePlan::class, 'price_plan_id');
    }
}
