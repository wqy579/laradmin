<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseApplicationItem extends Model
{
    protected $table = 'purchase_application_items';

    protected $fillable = [
        'application_id', 'product_id',
        'product_code', 'product_name', 'spec',
        'unit_large', 'unit_medium', 'unit_small',
        'unit_conversion', 'unit_conversion_medium',
        'qty_large', 'qty_medium', 'qty_small',
        'price_large', 'price_medium', 'price_small',
        'quantity', 'amount', 'cost_price',
        'remark', 'sort',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'amount' => 'float',
        'cost_price' => 'float',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(PurchaseApplication::class, 'application_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Stock\Models\Product::class, 'product_id');
    }
}
