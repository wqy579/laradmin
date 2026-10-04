<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;

class ProductLevelPrice extends Model
{
    protected $table = 'product_level_prices';

    protected $fillable = ['product_id', 'level_id', 'price'];
}
