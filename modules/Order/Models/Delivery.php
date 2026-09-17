<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stock\Models\Warehouse;

class Delivery extends Model
{
    protected $table = 'deliveries';

    protected $guarded = [];

    protected $casts = [
        'order_id' => 'integer',
        'warehouse_id' => 'integer',
        'vehicle_id' => 'integer',
        'driver_id' => 'integer',
        'route_id' => 'integer',
        'customer_id' => 'integer',
        'total_amount' => 'decimal:2',
        'delivery_date' => 'date',
        'status' => 'integer',
        'paid_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['order_no', 'delivery_no'];

    public function getOrderNoAttribute()
    {
        return $this->order_id ? 'SO'.str_pad($this->order_id, 6, '0', STR_PAD_LEFT) : '';
    }

    public function getDeliveryNoAttribute()
    {
        return $this->delivery_no ?? 'DEL'.date('YmdHis');
    }

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
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
