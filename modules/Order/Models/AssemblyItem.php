<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class AssemblyItem extends Model
{
    protected $table = 'assembly_order_items';

    protected $fillable = [
        'assembly_order_id', 'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'unit_usage', 'total_usage', 'unit_cost', 'total_cost',
    ];

    protected $casts = [
        'unit_usage' => 'integer',
        'total_usage' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class, 'assembly_order_id');
    }
}
