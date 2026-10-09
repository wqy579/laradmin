<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class VanRequisitionItem extends Model
{
    protected $table = 'van_requisition_items';

    protected $fillable = [
        'requisition_id', 'product_id', 'product_code', 'product_name',
        'spec', 'unit', 'apply_qty', 'stock_qty', 'unit_cost', 'amount',
        'remark', 'sort',
    ];

    protected $casts = [
        'apply_qty' => 'integer',
        'stock_qty' => 'integer',
        'unit_cost' => 'float',
        'amount' => 'float',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(VanRequisition::class, 'requisition_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
