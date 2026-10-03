<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCheckItem extends Model
{
    protected $table = 'stock_check_items';

    protected $fillable = [
        'check_id', 'product_id', 'product_code', 'product_name', 'spec', 'unit',
        'book_qty', 'actual_qty', 'diff_qty', 'cost_price', 'diff_amount', 'checker',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(StockCheck::class, 'check_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
