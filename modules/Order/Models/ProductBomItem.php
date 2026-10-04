<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class ProductBomItem extends Model
{
    protected $table = 'product_bom_items';

    protected $fillable = [
        'bom_id', 'product_id', 'product_code', 'product_name',
        'unit_usage', 'unit_cost', 'remark',
    ];

    protected $casts = [
        'unit_usage' => 'decimal:3',
        'unit_cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(ProductBom::class, 'bom_id');
    }
}
