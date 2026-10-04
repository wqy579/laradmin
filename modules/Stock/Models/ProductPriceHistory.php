<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPriceHistory extends Model
{
    protected $table = 'product_price_history';

    protected $fillable = [
        'product_id', 'price_type', 'price_type_name', 'old_price', 'new_price',
        'change_type', 'batch_rule', 'operator_id', 'operator_name',
    ];
}
