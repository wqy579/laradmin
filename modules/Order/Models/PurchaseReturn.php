<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User as AdminUser;
use Modules\Stock\Models\Warehouse;

class PurchaseReturn extends Model
{
    protected $table = 'purchase_returns';

    protected $fillable = [
        'return_no', 'stock_in_id', 'stock_in_no', 'supplier_id', 'supplier_name',
        'warehouse_id', 'return_date', 'status',
        'total_skus', 'total_qty', 'total_amount', 'payable_offset',
        'contact', 'phone', 'remark',
        'created_by', 'approved_by', 'approved_at', 'approval_comment',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'decimal:2',
        'payable_offset' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class, 'return_id');
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
