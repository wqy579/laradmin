<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User as AdminUser;
use Modules\Stock\Models\Warehouse;

class SalesReturn extends Model
{
    protected $table = 'sales_returns';

    protected $fillable = [
        'return_no', 'order_id', 'order_no', 'customer_id', 'customer_name',
        'warehouse_id', 'return_date', 'return_type', 'status',
        'total_skus', 'total_qty', 'total_amount', 'refund_amount', 'receivable_offset',
        'remark', 'created_by', 'approved_by', 'approved_at', 'approval_comment',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'receivable_offset' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'return_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }
}
