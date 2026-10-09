<?php

namespace Modules\Exchange\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Warehouse;

class ExchangeOrder extends Model
{
    protected $table = 'exchange_orders';

    protected $fillable = [
        'exchange_no', 'customer_id', 'customer_name', 'warehouse_id', 'warehouse_name',
        'salesman_id', 'salesman_name', 'exchange_date', 'exchange_reason',
        'total_kinds_out', 'total_kinds_in', 'total_qty_out', 'total_qty_in',
        'amount_out', 'amount_in', 'diff_amount', 'payment_method', 'refund_method',
        'status', 'created_by', 'creator_name', 'remark', 'approved_at',
        'rejected_at', 'reject_reason',
    ];

    protected $casts = [
        'exchange_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'amount_out' => 'float',
        'amount_in' => 'float',
        'diff_amount' => 'float',
    ];

    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_CANCELLED = 'cancelled';

    const PAYMENT_CASH = 'cash';
    const PAYMENT_WECHAT = 'wechat';
    const PAYMENT_ALIPAY = 'alipay';
    const PAYMENT_BANK = 'bank';
    const PAYMENT_CREDIT = 'credit';

    const REFUND_CASH_RETURN = 'cash_return';
    const REFUND_OFFSET = 'offset';

    public function items(): HasMany
    {
        return $this->hasMany(ExchangeOrderItem::class, 'order_id')->orderBy('sort');
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
            self::STATUS_DRAFT => '草稿',
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '已审核',
            self::STATUS_CANCELLED => '已取消',
            default => $status,
        };
    }

    public static function diffLabel(float $diff): string
    {
        if ($diff > 0.01) {
            return '客户补款';
        }
        if ($diff < -0.01) {
            return '退款给客户';
        }

        return '等价交换';
    }
}
