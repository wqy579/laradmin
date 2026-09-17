<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferItem extends Model
{
    protected $table = 'transfer_items';

    protected $fillable = ['transfer_id', 'product_id', 'quantity', 'price', 'amount', 'remark'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
