<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 采购订单
 *
 * 主数据落在 purchase_orders 表（Business 模块迁移建表），
 * 付款单 / 入库单通过 purchase_order_id 引用本表。
 */
class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    protected $fillable = [
        'order_no', 'supplier_id', 'warehouse_id', 'order_date',
        'total_amount', 'total_qty', 'status', 'created_by', 'approved_by',
        'approved_at', 'remark',
    ];

    protected $casts = [
        'order_date' => 'date',
        'total_amount' => 'decimal:2',
        'total_qty' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
