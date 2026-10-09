<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 上交明细：按收款方式拆行，记录本次上交对应的收款单。
 */
class DeliveryRemitItem extends Model
{
    protected $table = 'delivery_remit_items';

    protected $fillable = [
        'remit_id', 'collection_id', 'collection_no', 'payment_method', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function remit(): BelongsTo
    {
        return $this->belongsTo(DeliveryRemit::class, 'remit_id');
    }
}
