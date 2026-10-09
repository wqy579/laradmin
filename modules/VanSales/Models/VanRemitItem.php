<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VanRemitItem extends Model
{
    protected $table = 'van_remit_items';

    protected $fillable = [
        'remit_id', 'sale_order_id', 'sale_order_no', 'payment_method', 'amount',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function remit(): BelongsTo
    {
        return $this->belongsTo(VanRemit::class, 'remit_id');
    }
}
