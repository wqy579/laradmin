<?php

namespace Modules\BorrowReturn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Warehouse;

class BorrowReturnOrder extends Model
{
    protected $table = 'borrow_return_orders';

    protected $fillable = [
        'return_no', 'borrow_order_id', 'customer_id', 'customer_name',
        'warehouse_id', 'warehouse_name', 'salesman_id', 'salesman_name',
        'return_date', 'return_reason', 'total_kinds', 'total_qty',
        'total_amount', 'good_qty', 'bad_qty', 'status',
        'created_by', 'creator_name', 'remark', 'approved_at',
        'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'return_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_amount' => 'float',
    ];

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(BorrowReturnOrderItem::class, 'order_id')->orderBy('sort');
    }

    public function borrowOrder(): BelongsTo
    {
        return $this->belongsTo(BorrowOrder::class, 'borrow_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '已审核',
            self::STATUS_CANCELLED => '已取消',
            default => $status,
        };
    }
}
