<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class VanReturnOrder extends Model
{
    protected $table = 'van_return_orders';

    protected $fillable = [
        'return_no', 'salesman_id', 'salesman_name', 'customer_id', 'customer_name',
        'vehicle_id', 'vehicle_warehouse_id', 'return_date', 'return_reason',
        'total_qty', 'total_amount', 'refund_method', 'refund_amount', 'receivable_offset',
        'status', 'visit_log_id', 'created_by', 'creator_name', 'approved_at', 'remark',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'float',
        'refund_amount' => 'float',
        'receivable_offset' => 'float',
        'approved_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    const REFUND_CASH = 'cash';

    const REFUND_OFFSET = 'offset';

    const REFUND_CREDIT = 'credit';

    public function items(): HasMany
    {
        return $this->hasMany(VanReturnOrderItem::class, 'order_id');
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
