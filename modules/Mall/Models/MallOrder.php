<?php

namespace Modules\Mall\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Order\Models\SalesOrder;

/**
 * 商城订单主表。
 *
 * 下单即生成订货申报单（sales_orders），经 sales_order_id / sales_order_no 回溯。
 * 状态机：pending → accepted / rejected / cancelled。
 */
class MallOrder extends Model
{
    protected $table = 'mall_orders';

    protected $fillable = [
        'order_no', 'idempotency_key', 'customer_id', 'customer_name', 'warehouse_id', 'order_date',
        'total_amount', 'total_qty', 'paid_amount', 'status',
        'receiver_name', 'receiver_phone', 'receiver_address',
        'sales_order_id', 'sales_order_no', 'created_by', 'creator_name',
        'accepted_by', 'accepted_at', 'accept_remark',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'warehouse_id' => 'integer',
        'total_amount' => 'decimal:2',
        'total_qty' => 'integer',
        'paid_amount' => 'decimal:2',
        'sales_order_id' => 'integer',
        'created_by' => 'integer',
        'accepted_by' => 'integer',
        'accepted_at' => 'datetime',
        'order_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(MallOrderItem::class, 'order_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '待接单',
            'accepted' => '已接单',
            'rejected' => '已拒绝',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    public static function statusTagType(string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'accepted' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'info',
            default => 'info',
        };
    }
}
