<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transfer extends Model
{
    protected $table = 'transfers';

    protected $fillable = ['order_no', 'from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'total_amount', 'total_qty', 'status', 'created_by', 'remark'];

    protected $casts = ['transfer_date' => 'date'];

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class);
    }
}
