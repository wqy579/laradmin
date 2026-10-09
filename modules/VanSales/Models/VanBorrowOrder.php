<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class VanBorrowOrder extends Model
{
    protected $table = 'van_borrow_orders';

    protected $fillable = [
        'borrow_no', 'salesman_id', 'salesman_name', 'customer_id', 'customer_name',
        'vehicle_id', 'vehicle_warehouse_id', 'borrow_date', 'due_date',
        'total_qty', 'total_amount', 'status', 'created_by', 'creator_name', 'approved_at', 'remark',
    ];

    protected $casts = [
        'borrow_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime',
        'total_amount' => 'float',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(VanBorrowOrderItem::class, 'order_id');
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
