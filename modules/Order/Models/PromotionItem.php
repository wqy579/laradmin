<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionItem extends Model
{
    protected $table = 'promotion_items';

    protected $fillable = [
        'promotion_id', 'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'original_price', 'discount_rate', 'special_price',
        'gift_product_id', 'gift_qty', 'buy_qty',
    ];
}
