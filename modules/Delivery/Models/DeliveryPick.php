<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 拣货单（库管拣货）。由配货单确认后自动生成。
 */
class DeliveryPick extends Model
{
    protected $table = 'delivery_pick';

    protected $fillable = [
        'pick_no', 'picking_id', 'picking_no', 'customer_id', 'customer_name',
        'warehouse_id', 'pick_date', 'picker_id', 'picker_name',
        'total_skus', 'total_qty', 'short_qty',
        'status', 'check_id',
        'remark',
    ];

    protected $casts = [
        'pick_date' => 'date',
    ];

    const STATUS_PENDING = 'pending';   // 待拣货
    const STATUS_PICKING = 'picking';   // 拣货中
    const STATUS_PICKED = 'picked';     // 已拣货
    const STATUS_CANCELLED = 'cancelled'; // 已取消

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryPickItem::class, 'pick_id');
    }

    public function picking(): BelongsTo
    {
        return $this->belongsTo(DeliveryPicking::class, 'picking_id');
    }
}
