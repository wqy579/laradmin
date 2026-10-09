<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;

/**
 * @property string $status
 */
class DeliveryPicking extends Model
{
    protected $table = 'delivery_picking';

    protected $fillable = [
        'picking_no', 'sales_order_id', 'order_no', 'customer_id', 'customer_name',
        'warehouse_id', 'picking_date', 'total_skus', 'total_qty', 'total_amount',
        'status', 'stock_frozen', 'frozen_from_order', 'pick_id',
        'created_by', 'creator_name', 'confirmed_at',
    ];

    protected $casts = [
        'picking_date' => 'date',
        'confirmed_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'stock_frozen' => 'boolean',
        'frozen_from_order' => 'boolean',
    ];

    // 状态
    const STATUS_PENDING = 'pending';   // 待配货

    const STATUS_PICKED = 'picked';     // 已配货

    const STATUS_CANCELLED = 'cancelled'; // 已取消

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryPickingItem::class, 'picking_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
