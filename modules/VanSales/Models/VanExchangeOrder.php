<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class VanExchangeOrder extends Model
{
    protected $table = 'van_exchange_orders';

    protected $fillable = [
        'exchange_no', 'salesman_id', 'salesman_name', 'customer_id', 'customer_name',
        'vehicle_id', 'vehicle_warehouse_id', 'exchange_date', 'diff_amount',
        'settle_method', 'status', 'created_by', 'creator_name', 'approved_at', 'remark',
    ];

    protected $casts = [
        'exchange_date' => 'date',
        'diff_amount' => 'float',
        'approved_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    const SETTLE_CASH = 'cash';

    const SETTLE_OFFSET = 'offset';

    const SETTLE_CREDIT = 'credit';

    public function items(): HasMany
    {
        return $this->hasMany(VanExchangeOrderItem::class, 'order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function vehicleWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'vehicle_warehouse_id');
    }
}
