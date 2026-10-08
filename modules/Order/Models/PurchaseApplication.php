<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Order\Models\Supplier;

class PurchaseApplication extends Model
{
    protected $table = 'purchase_applications';

    protected $fillable = [
        'apply_no', 'supplier_id', 'supplier_name', 'warehouse_id',
        'apply_date', 'expected_date', 'payment_type',
        'approver_id', 'approver_name', 'status',
        'total_skus', 'total_qty_large', 'total_qty_medium', 'total_qty_small',
        'total_quantity', 'total_amount', 'payable_amount',
        'attachment', 'remark',
        'created_by', 'creator_name',
        'approved_by', 'approved_at', 'approval_comment',
        'transferred_by', 'transferred_at',
    ];

    protected $casts = [
        'apply_date' => 'date',
        'expected_date' => 'date',
        'approved_at' => 'datetime',
        'transferred_at' => 'datetime',
        'attachment' => 'array',
        'total_amount' => 'float',
        'payable_amount' => 'float',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_TRANSFERRED = 'transferred';

    const PAYMENT_CASH = 'cash';

    const PAYMENT_TRANSFER = 'transfer';

    const PAYMENT_MONTHLY = 'monthly';

    const PAYMENT_OTHER = 'other';

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseApplicationItem::class, 'application_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
