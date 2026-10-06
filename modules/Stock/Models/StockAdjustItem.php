<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustItem extends Model
{
    protected $table = 'stock_adjust_items';

    protected $fillable = [
        'adjust_id', 'product_id', 'product_code', 'product_name',
        'spec', 'unit', 'before_qty', 'adjust_qty', 'after_qty',
        'unit_cost', 'total_cost', 'remark',
    ];

    protected $casts = [
        'before_qty' => 'float',
        'adjust_qty' => 'float',
        'after_qty' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
    ];

    public function adjust(): BelongsTo
    {
        return $this->belongsTo(StockAdjust::class, 'adjust_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
