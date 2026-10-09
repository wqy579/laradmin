<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class VanPicking extends Model
{
    protected $table = 'van_picking';

    protected $fillable = [
        'picking_no', 'requisition_id', 'warehouse_id', 'vehicle_id', 'vehicle_warehouse_id',
        'picker_id', 'picker_name', 'pick_date', 'status', 'checked',
        'checker_id', 'checker_name', 'checked_at', 'total_qty', 'remark',
    ];

    protected $casts = [
        'pick_date' => 'date',
        'checked' => 'boolean',
        'checked_at' => 'datetime',
        'total_qty' => 'integer',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(VanPickingItem::class, 'picking_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(VanRequisition::class, 'requisition_id');
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
