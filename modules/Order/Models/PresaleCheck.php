<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User as AdminUser;

/**
 * 客户订货核对单（T5.1）
 *
 * 从销售订单生成：一张 pending 订单核对单，核对完成 status → checked。
 */
class PresaleCheck extends Model
{
    protected $table = 'presale_check';

    protected $fillable = [
        'check_no', 'sales_order_id', 'customer_id', 'warehouse_id',
        'status', 'checked_by', 'checked_at', 'remark', 'created_by',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'diff_rate' => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PresaleCheckItem::class, 'presale_check_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\Modules\Stock\Models\Warehouse::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'checked_by');
    }
}
