<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryPickItem extends Model
{
    protected $table = 'delivery_pick_items';

    protected $fillable = [
        'pick_id', 'picking_item_id', 'product_id', 'product_code', 'product_name',
        'spec', 'unit', 'pick_qty', 'actual_qty', 'short_qty',
        'bin_location', 'remark', 'sort',
    ];

    public function pick(): BelongsTo
    {
        return $this->belongsTo(DeliveryPick::class, 'pick_id');
    }
}
