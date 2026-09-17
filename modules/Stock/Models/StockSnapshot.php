<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;

class StockSnapshot extends Model
{
    protected $table = 'stock_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_date', 'product_id', 'warehouse_id', 'quantity', 'frozen_qty', 'created_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'quantity' => 'decimal:2',
        'frozen_qty' => 'decimal:2',
    ];
}
