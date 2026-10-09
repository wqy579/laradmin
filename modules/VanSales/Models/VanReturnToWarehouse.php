<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

/**
 * 车上退仓单：车辆把车上库存退回普通仓库（车 → 仓库，反向调拨）。
 *
 * 与 VanReturnOrder（VXT，客户退货回车上）方向相反。
 */
class VanReturnToWarehouse extends Model
{
    protected $table = 'van_return_to_warehouse';

    protected $fillable = [
        'return_no', 'salesman_id', 'salesman_name', 'vehicle_id',
        'vehicle_warehouse_id', 'warehouse_id', 'return_date',
        'total_qty', 'total_amount', 'status', 'created_by', 'creator_name',
        'approved_by', 'approver_name', 'approved_at', 'approval_comment', 'remark',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'float',
        'approved_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(VanReturnToWarehouseItem::class, 'order_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function vehicleWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'vehicle_warehouse_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
