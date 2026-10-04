<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class SplitItem extends Model
{
    protected $table = 'split_order_items';

    protected $fillable = [
        'split_order_id', 'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'split_qty', 'split_total', 'unit_cost', 'total_cost',
    ];

    protected $casts = [
        'split_qty' => 'integer',
        'split_total' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function split(): BelongsTo
    {
        return $this->belongsTo(Split::class, 'split_order_id');
    }
}
