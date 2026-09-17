<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class SalesOrder extends Model
{
    protected $table = 'sales_orders';

    protected $fillable = [
        'order_no', 'order_type', 'customer_id', 'warehouse_id',
        'order_date', 'total_amount', 'total_qty', 'paid_amount',
        'status', 'transferred_to', 'salesman_id', 'vehicle_id',
        'delivery_person_id', 'salesman_name', 'created_by',
        'approved_by', 'dispatched_by', 'approved_at', 'dispatched_at',
        'remark', 'print_count',
    ];

    protected $casts = [
        'order_date' => 'date',
        'approved_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'sales_order_id');
    }
}
