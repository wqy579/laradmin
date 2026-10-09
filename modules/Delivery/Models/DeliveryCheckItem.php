<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryCheckItem extends Model
{
    protected $table = 'delivery_check_items';

    protected $fillable = [
        'check_id', 'pick_item_id', 'product_id', 'product_code', 'product_name',
        'spec', 'unit', 'pick_qty', 'actual_qty', 'diff_qty',
        'remark', 'sort',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(DeliveryCheck::class, 'check_id');
    }
}
