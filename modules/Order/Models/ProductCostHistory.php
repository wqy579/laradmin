<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class ProductCostHistory extends Model
{
    protected $table = 'product_cost_history';

    protected $fillable = [
        'product_id', 'warehouse_id', 'old_cost', 'new_cost', 'old_qty', 'new_qty',
        'change_type', 'related_id', 'related_type',
        'operator_id', 'operator_name', 'remark',
    ];

    protected $casts = [
        'old_cost' => 'decimal:2',
        'new_cost' => 'decimal:2',
        'old_qty' => 'decimal:3',
        'new_qty' => 'decimal:3',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
