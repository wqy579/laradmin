<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnOrder extends Model
{
    protected $table = 'returns';
    protected $fillable = ['order_no', 'supplier_id', 'warehouse_id', 'return_date', 'total_amount', 'total_qty', 'status', 'created_by', 'approved_by', 'approved_at', 'remark'];
    protected $casts = ['return_date' => 'date'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }
}
