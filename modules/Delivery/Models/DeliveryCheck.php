<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 验货单（验货员验货）。由拣货单确认后自动生成。
 */
class DeliveryCheck extends Model
{
    protected $table = 'delivery_check';

    protected $fillable = [
        'check_no', 'pick_id', 'pick_no', 'customer_id', 'customer_name',
        'check_date', 'checker_id', 'checker_name',
        'total_skus', 'expected_qty', 'actual_qty', 'diff_qty',
        'status', 'load_id',
        'remark',
    ];

    protected $casts = [
        'check_date' => 'date',
    ];

    const STATUS_PENDING = 'pending';     // 待验货

    const STATUS_CHECKING = 'checking';   // 验货中

    const STATUS_CHECKED = 'checked';     // 已验货

    const STATUS_EXCEPTION = 'exception'; // 验货异常

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryCheckItem::class, 'check_id');
    }

    public function pick(): BelongsTo
    {
        return $this->belongsTo(DeliveryPick::class, 'pick_id');
    }
}
