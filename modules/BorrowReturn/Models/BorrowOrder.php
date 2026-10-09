<?php

namespace Modules\BorrowReturn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Warehouse;

class BorrowOrder extends Model
{
    protected $table = 'borrow_orders';

    protected $fillable = [
        'borrow_no', 'customer_id', 'customer_name', 'contact', 'contact_phone',
        'warehouse_id', 'warehouse_name', 'salesman_id', 'salesman_name',
        'borrow_date', 'due_date', 'borrow_reason', 'total_kinds', 'total_qty',
        'total_amount', 'returned_qty', 'returned_amount', 'status',
        'created_by', 'creator_name', 'remark', 'approved_at',
        'cancelled_at', 'cancel_reason', 'converted_at',
    ];

    protected $casts = [
        'borrow_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'converted_at' => 'datetime',
        'total_amount' => 'float',
        'returned_amount' => 'float',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_UNRETURNED = 'unreturned';

    const STATUS_PARTIAL = 'partial';

    const STATUS_CLEARED = 'cleared';

    const STATUS_CONVERTED = 'converted';

    const STATUS_CANCELLED = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(BorrowOrderItem::class, 'order_id')->orderBy('sort');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** 文档状态标签：草稿也归到「未还」展示 */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => '未还',
            self::STATUS_UNRETURNED => '未还',
            self::STATUS_PARTIAL => '部分还',
            self::STATUS_CLEARED => '已还清',
            self::STATUS_CONVERTED => '已转销售',
            self::STATUS_CANCELLED => '已取消',
            default => $status,
        };
    }
}
