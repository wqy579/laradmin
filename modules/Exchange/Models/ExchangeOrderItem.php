<?php

namespace Modules\Exchange\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class ExchangeOrderItem extends Model
{
    protected $table = 'exchange_order_items';

    protected $fillable = [
        'order_id', 'product_id_out', 'product_name_out', 'spec_out', 'unit_out',
        'qty', 'unit_price_out', 'amount_out', 'product_id_in', 'product_name_in',
        'spec_in', 'unit_in', 'unit_price_in', 'amount_in', 'diff_amount', 'remark', 'sort',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price_out' => 'float',
        'amount_out' => 'float',
        'unit_price_in' => 'float',
        'amount_in' => 'float',
        'diff_amount' => 'float',
    ];

    public function productOut(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id_out');
    }

    public function productIn(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id_in');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExchangeOrder::class, 'order_id');
    }
}
