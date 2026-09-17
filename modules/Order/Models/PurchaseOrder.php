<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stock\Models\Warehouse;

class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    protected $fillable = ['order_no', 'supplier_id', 'warehouse_id', 'order_date', 'total_amount', 'total_qty', 'status', 'created_by', 'approved_by', 'approved_at', 'remark'];

    protected $casts = ['order_date' => 'date', 'approved_at' => 'datetime'];

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
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }
}
