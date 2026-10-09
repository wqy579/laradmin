<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class VanRequisition extends Model
{
    protected $table = 'van_requisitions';

    protected $fillable = [
        'requisition_no', 'salesman_id', 'salesman_name', 'vehicle_id', 'warehouse_id',
        'apply_date', 'expected_date', 'status', 'total_qty', 'total_amount',
        'created_by', 'creator_name', 'approved_by', 'approver_name', 'approved_at',
        'approval_comment', 'remark',
    ];

    protected $casts = [
        'apply_date' => 'date',
        'expected_date' => 'date',
        'approved_at' => 'datetime',
        'total_amount' => 'float',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_PICKED = 'picked';

    const STATUS_REJECTED = 'rejected';

    const STATUS_CANCELLED = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(VanRequisitionItem::class, 'requisition_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'salesman_id');
    }
}
