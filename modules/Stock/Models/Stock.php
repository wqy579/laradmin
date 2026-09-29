<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    protected $table = 'stocks';

    protected $fillable = ['product_id', 'warehouse_id', 'quantity', 'frozen_qty', 'cost_price', 'total_amount', 'updated_at'];

    // stocks 表无 created_at 列（只有 updated_at），关掉自动 timestamps，否则 Stock::create 写 created_at 会 500
    public $timestamps = false;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
