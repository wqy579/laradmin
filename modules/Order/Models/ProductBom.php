<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stock\Models\Product;

class ProductBom extends Model
{
    protected $table = 'product_bom';

    protected $fillable = [
        'product_id', 'type', 'name', 'remark',
    ];

    protected $casts = [
        'type' => 'string',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductBomItem::class, 'bom_id');
    }
}
