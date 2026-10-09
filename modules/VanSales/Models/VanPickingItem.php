<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class VanPickingItem extends Model
{
    protected $table = 'van_picking_items';

    protected $fillable = [
        'picking_id', 'product_id', 'product_code', 'product_name',
        'spec', 'unit', 'apply_qty', 'pick_qty', 'check_qty', 'diff_qty',
        'diff_remark', 'location', 'unit_cost', 'amount', 'sort',
    ];

    protected $casts = [
        'apply_qty' => 'integer',
        'pick_qty' => 'integer',
        'check_qty' => 'integer',
        'diff_qty' => 'integer',
        'unit_cost' => 'float',
        'amount' => 'float',
    ];

    public function picking(): BelongsTo
    {
        return $this->belongsTo(VanPicking::class, 'picking_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
