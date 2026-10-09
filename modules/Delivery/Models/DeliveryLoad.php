<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 装车单（库管装车）。选择多个已验货单 + 配送员 + 车牌，确认装车时扣减库存并生成配送任务。
 */
class DeliveryLoad extends Model
{
    protected $table = 'delivery_load';

    protected $fillable = [
        'load_no', 'load_date', 'delivery_person_id', 'delivery_person_name',
        'employee_id', 'vehicle_id', 'plate_no',
        'order_count', 'total_skus', 'total_qty', 'total_amount',
        'status', 'remark',
        'created_by', 'creator_name', 'loaded_at',
    ];

    protected $casts = [
        'load_date' => 'date',
        'loaded_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    const STATUS_PENDING = 'pending';         // 待装车

    const STATUS_LOADED = 'loaded';           // 已装车

    const STATUS_DELIVERING = 'delivering';    // 配送中

    const STATUS_COMPLETED = 'completed';     // 已完成

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryLoadItem::class, 'load_id');
    }
}
